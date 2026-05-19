<?php

declare(strict_types=1);

namespace Seismo\Repository;

use PDO;

/**
 * `feeds` table — Slice 8 module admin (Slice 8).
 *
 * The **Feeds** page lists RSS + Substack + `parl_press` (Parlament Medien) sources.
 * Rows that are scraper-backed
 * (`source_type`, `category`, or a live `scraper_configs` URL match) belong on
 * {@see ScraperConfigRepository} / `?action=scraper` — same rule as
 * {@see EntryRepository::getRssModuleTimeline()}.
 *
 * All SQL uses {@see entryTable()}. Mutating methods refuse satellite mode.
 */
final class FeedRepository
{
    public const MAX_LIMIT = 200;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Feeds-module source list: RSS + Substack only (excludes scraper-linked rows).
     *
     * @return list<array<string, mixed>>
     */
    public function listRssSubstackModuleSources(int $limit, int $offset): array
    {
        $limit  = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);
        $f = entryTable('feeds');
        $sc = entryTable('scraper_configs');
        $sql = "SELECT f.* FROM {$f} f
            WHERE f.source_type IN ('rss', 'substack', 'parl_press')
              AND (IFNULL(f.category, '') <> 'scraper')
              AND NOT EXISTS (
                  SELECT 1 FROM {$sc} sc
                  WHERE sc.url = f.url AND sc.disabled = 0
              )
            ORDER BY f.id ASC
            LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset;

        $stmt = $this->pdo->query($sql);

        return $stmt->fetchAll();
    }

    /**
     * @return ?array<string, mixed>
     */
    public function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $t   = entryTable('feeds');
        $sql = "SELECT * FROM {$t} WHERE id = ? LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array{
     *   url: string,
     *   title: string,
     *   source_type?: string,
     *   description?: string|null,
     *   link?: string|null,
     *   category?: string|null,
     *   disabled?: int|bool,
     * } $data
     */
    public function insert(array $data): int
    {
        $this->assertNotSatellite();
        $url   = trim((string)($data['url'] ?? ''));
        $title = trim((string)($data['title'] ?? ''));
        if ($url === '' || $title === '') {
            throw new \InvalidArgumentException('Feed URL and title are required.');
        }
        $sourceType = trim((string)($data['source_type'] ?? 'rss'));
        $sourceType = $this->normaliseSourceType($sourceType);
        $t = entryTable('feeds');
        $sql = "INSERT INTO {$t} (
            url, source_type, title, description, link, category, disabled,
            consecutive_failures, last_error, last_error_at, last_fetched
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, 0, NULL, NULL, NULL
        )";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $url,
            $sourceType,
            $title,
            $data['description'] ?? null,
            $data['link'] ?? null,
            $data['category'] ?? null,
            !empty($data['disabled']) ? 1 : 0,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array{
     *   title?: string,
     *   source_type?: string,
     *   description?: string|null,
     *   link?: string|null,
     *   category?: string|null,
     *   disabled?: int|bool,
     *   url?: string,
     * } $data
     */
    public function update(int $id, array $data): void
    {
        $this->assertNotSatellite();
        if ($id <= 0) {
            throw new \InvalidArgumentException('Invalid feed id.');
        }
        $existing = $this->findById($id);
        if ($existing === null) {
            throw new \InvalidArgumentException('Feed not found.');
        }
        $url   = array_key_exists('url', $data) ? trim((string)$data['url']) : (string)$existing['url'];
        $title = array_key_exists('title', $data) ? trim((string)$data['title']) : (string)$existing['title'];
        if ($url === '' || $title === '') {
            throw new \InvalidArgumentException('Feed URL and title are required.');
        }
        $sourceType = array_key_exists('source_type', $data)
            ? $this->normaliseSourceType(trim((string)$data['source_type']))
            : $this->normaliseSourceType((string)($existing['source_type'] ?? 'rss'));
        $disabled = array_key_exists('disabled', $data)
            ? (!empty($data['disabled']) ? 1 : 0)
            : (int)($existing['disabled'] ?? 0);

        $t   = entryTable('feeds');
        $sql = "UPDATE {$t} SET
            url = ?,
            source_type = ?,
            title = ?,
            description = ?,
            link = ?,
            category = ?,
            disabled = ?
            WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $url,
            $sourceType,
            $title,
            $data['description'] ?? $existing['description'],
            $data['link'] ?? $existing['link'],
            $data['category'] ?? $existing['category'],
            $disabled,
            $id,
        ]);
    }

    /**
     * Flip `disabled` for an existing feed (_sources table quick toggle).
     *
     * @return bool New disabled state (true = disabled).
     */
    public function toggleDisabled(int $id): bool
    {
        $this->assertNotSatellite();
        $existing = $this->findById($id);
        if ($existing === null) {
            throw new \InvalidArgumentException('Feed not found.');
        }
        $next = !empty($existing['disabled']) ? 0 : 1;
        $this->update($id, ['disabled' => $next]);

        return $next === 1;
    }

    public function delete(int $id): void
    {
        $this->assertNotSatellite();
        if ($id <= 0) {
            throw new \InvalidArgumentException('Invalid feed id.');
        }
        $t   = entryTable('feeds');
        $sql = "DELETE FROM {$t} WHERE id = ? LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
    }

    private function assertNotSatellite(): void
    {
        if (isSatellite()) {
            throw new \RuntimeException('Satellite mode — feed configuration is managed on the mothership only.');
        }
    }

    private function normaliseSourceType(string $raw): string
    {
        $raw = strtolower(trim($raw));

        return in_array($raw, ['rss', 'substack', 'scraper', 'parl_press'], true) ? $raw : 'rss';
    }
}
