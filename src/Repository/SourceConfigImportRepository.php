<?php

declare(strict_types=1);

namespace Seismo\Repository;

use PDO;

/**
 * Focused importer for source definitions.
 *
 * Scope is intentionally narrow: feeds-module sources and scraper configs only.
 */
final class SourceConfigImportRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{inserted:int,updated:int,skipped:int}
     */
    public function importFeedsModuleSources(array $rows): array
    {
        $stats = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
        $f = entryTable('feeds');

        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                $url = trim((string)($row['url'] ?? ''));
                $title = trim((string)($row['title'] ?? ''));
                $sourceType = strtolower(trim((string)($row['source_type'] ?? 'rss')));
                if ($url === '' || $title === '' || !in_array($sourceType, ['rss', 'substack', 'parl_press'], true)) {
                    $stats['skipped']++;
                    continue;
                }

                $category = trim((string)($row['category'] ?? ''));
                if ($category === 'scraper') {
                    $stats['skipped']++;
                    continue;
                }
                $description = array_key_exists('description', $row) ? (string)$row['description'] : null;
                $link = array_key_exists('link', $row) ? (string)$row['link'] : null;
                $disabled = !empty($row['disabled']) ? 1 : 0;

                $find = $this->pdo->prepare(
                    "SELECT id FROM {$f}
                     WHERE url = ? AND source_type = ? AND IFNULL(category, '') <> 'scraper'
                     ORDER BY id ASC
                     LIMIT 1"
                );
                $find->execute([$url, $sourceType]);
                $existingId = $find->fetchColumn();

                if ($existingId !== false && $existingId !== null) {
                    $update = $this->pdo->prepare(
                        "UPDATE {$f}
                         SET title = ?, description = ?, link = ?, category = ?, disabled = ?
                         WHERE id = ?"
                    );
                    $update->execute([$title, $description, $link, $category !== '' ? $category : null, $disabled, (int)$existingId]);
                    $stats['updated']++;
                    continue;
                }

                $insert = $this->pdo->prepare(
                    "INSERT INTO {$f}
                    (url, source_type, title, description, link, category, disabled, consecutive_failures, last_error, last_error_at, last_fetched)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 0, NULL, NULL, NULL)"
                );
                $insert->execute([$url, $sourceType, $title, $description, $link, $category !== '' ? $category : null, $disabled]);
                $stats['inserted']++;
            }

            $this->pdo->commit();
            return $stats;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{inserted:int,updated:int,skipped:int}
     */
    public function importScraperConfigs(array $rows): array
    {
        $stats = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
        $t = entryTable('scraper_configs');

        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                $name = trim((string)($row['name'] ?? ''));
                $url = trim((string)($row['url'] ?? ''));
                if ($name === '' || $url === '') {
                    $stats['skipped']++;
                    continue;
                }

                $linkPattern = array_key_exists('link_pattern', $row) ? (string)$row['link_pattern'] : null;
                $dateSelector = array_key_exists('date_selector', $row) ? (string)$row['date_selector'] : null;
                $excludeSelectors = array_key_exists('exclude_selectors', $row) ? (string)$row['exclude_selectors'] : null;
                $category = trim((string)($row['category'] ?? 'scraper'));
                if ($category === '') {
                    $category = 'scraper';
                }
                $disabled = !empty($row['disabled']) ? 1 : 0;

                $find = $this->pdo->prepare("SELECT id FROM {$t} WHERE url = ? LIMIT 1");
                $find->execute([$url]);
                $existingId = $find->fetchColumn();

                if ($existingId !== false && $existingId !== null) {
                    $update = $this->pdo->prepare(
                        "UPDATE {$t}
                         SET name = ?, link_pattern = ?, date_selector = ?, exclude_selectors = ?, category = ?, disabled = ?
                         WHERE id = ?"
                    );
                    $update->execute([$name, $linkPattern, $dateSelector, $excludeSelectors, $category, $disabled, (int)$existingId]);
                    $stats['updated']++;
                    continue;
                }

                $insert = $this->pdo->prepare(
                    "INSERT INTO {$t}
                    (name, url, link_pattern, date_selector, exclude_selectors, category, disabled)
                    VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $insert->execute([$name, $url, $linkPattern, $dateSelector, $excludeSelectors, $category, $disabled]);
                $stats['inserted']++;
            }

            $this->pdo->commit();
            return $stats;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}

