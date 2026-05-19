<?php

declare(strict_types=1);

namespace Seismo\Repository;

use PDO;

/**
 * `scraper_configs` — web scraper targets (Slice 8).
 */
final class ScraperConfigRepository
{
    public const MAX_LIMIT = 200;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(int $limit, int $offset): array
    {
        $limit  = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);
        $t      = entryTable('scraper_configs');
        $sql    = "SELECT * FROM {$t} ORDER BY id ASC LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset;

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
        $t   = entryTable('scraper_configs');
        $sql = "SELECT * FROM {$t} WHERE id = ? LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array{
     *   name: string,
     *   url: string,
     *   link_pattern?: string|null,
     *   date_selector?: string|null,
     *   exclude_selectors?: string|null,
     *   category?: string|null,
     *   disabled?: int|bool,
     * } $data
     */
    public function insert(array $data): int
    {
        $this->assertNotSatellite();
        $name = trim((string)($data['name'] ?? ''));
        $url  = trim((string)($data['url'] ?? ''));
        if ($name === '' || $url === '') {
            throw new \InvalidArgumentException('Scraper name and URL are required.');
        }
        $t   = entryTable('scraper_configs');
        $sql = "INSERT INTO {$t} (name, url, link_pattern, date_selector, exclude_selectors, category, disabled)
            VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $name,
            $url,
            $data['link_pattern'] ?? null,
            $data['date_selector'] ?? null,
            $data['exclude_selectors'] ?? null,
            $data['category'] ?? 'scraper',
            !empty($data['disabled']) ? 1 : 0,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $this->assertNotSatellite();
        if ($id <= 0) {
            throw new \InvalidArgumentException('Invalid scraper id.');
        }
        $existing = $this->findById($id);
        if ($existing === null) {
            throw new \InvalidArgumentException('Scraper config not found.');
        }
        $name = array_key_exists('name', $data) ? trim((string)$data['name']) : (string)$existing['name'];
        $url  = array_key_exists('url', $data) ? trim((string)$data['url']) : (string)$existing['url'];
        if ($name === '' || $url === '') {
            throw new \InvalidArgumentException('Scraper name and URL are required.');
        }
        $disabled = array_key_exists('disabled', $data)
            ? (!empty($data['disabled']) ? 1 : 0)
            : (int)($existing['disabled'] ?? 0);

        $t   = entryTable('scraper_configs');
        $sql = "UPDATE {$t} SET
            name = ?,
            url = ?,
            link_pattern = ?,
            date_selector = ?,
            exclude_selectors = ?,
            category = ?,
            disabled = ?
            WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $name,
            $url,
            $data['link_pattern'] ?? $existing['link_pattern'],
            $data['date_selector'] ?? $existing['date_selector'],
            $data['exclude_selectors'] ?? $existing['exclude_selectors'] ?? null,
            $data['category'] ?? $existing['category'],
            $disabled,
            $id,
        ]);
    }

    /**
     * Flip `disabled` for an existing scraper config (sources table quick toggle).
     *
     * @return bool New disabled state (true = disabled).
     */
    public function toggleDisabled(int $id): bool
    {
        $this->assertNotSatellite();
        $existing = $this->findById($id);
        if ($existing === null) {
            throw new \InvalidArgumentException('Scraper config not found.');
        }
        $next = !empty($existing['disabled']) ? 0 : 1;
        $this->update($id, ['disabled' => $next]);

        return $next === 1;
    }

    public function delete(int $id): void
    {
        $this->assertNotSatellite();
        if ($id <= 0) {
            throw new \InvalidArgumentException('Invalid scraper id.');
        }
        $t   = entryTable('scraper_configs');
        $sql = "DELETE FROM {$t} WHERE id = ? LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
    }

    private function assertNotSatellite(): void
    {
        if (isSatellite()) {
            throw new \RuntimeException('Satellite mode — scraper configuration is managed on the mothership only.');
        }
    }
}
