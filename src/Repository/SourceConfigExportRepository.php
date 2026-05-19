<?php

declare(strict_types=1);

namespace Seismo\Repository;

use PDO;

/**
 * Read-only export snapshots for source/module configuration.
 *
 * This repository intentionally returns full row sets (no paging) because the
 * export endpoint is an operator-driven backup action.
 */
final class SourceConfigExportRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * RSS/Substack/Parl. press sources visible on the Feeds module.
     *
     * @return list<array<string, mixed>>
     */
    public function listFeedsModuleSources(): array
    {
        $f = entryTable('feeds');
        $sc = entryTable('scraper_configs');
        $sql = "SELECT f.* FROM {$f} f
            WHERE f.source_type IN ('rss', 'substack', 'parl_press')
              AND (IFNULL(f.category, '') <> 'scraper')
              AND NOT EXISTS (
                  SELECT 1 FROM {$sc} sc
                  WHERE sc.url = f.url AND sc.disabled = 0
              )
            ORDER BY f.id ASC";

        $stmt = $this->pdo->query($sql);

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listScraperConfigs(): array
    {
        $t = entryTable('scraper_configs');
        $stmt = $this->pdo->query("SELECT * FROM {$t} ORDER BY id ASC");

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listEmailSubscriptions(): array
    {
        $t = entryTable('email_subscriptions');
        $stmt = $this->pdo->query(
            "SELECT * FROM {$t}
             WHERE removed_at IS NULL
             ORDER BY id ASC"
        );

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, string>
     */
    public function mapSystemConfigByPrefix(string $prefix): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT config_key, config_value
             FROM system_config
             WHERE config_key LIKE ?
             ORDER BY config_key ASC"
        );
        $stmt->execute([$prefix . '%']);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string)($row['config_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $out[$key] = (string)($row['config_value'] ?? '');
        }

        return $out;
    }
}

