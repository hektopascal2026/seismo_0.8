<?php
/**
 * Migration 011 — `source_log` append-only audit of new Feeds / Scraper / Mail sources.
 *
 * Schema 27. Mothership only at runtime; table lives on mothership DB.
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;

final class Migration011SourceLog
{
    public const VERSION = 27;

    public function apply(PDO $pdo): void
    {
        if ($this->tableExists($pdo, 'source_log')) {
            return;
        }

        $pdo->exec(
            'CREATE TABLE source_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                kind VARCHAR(20) NOT NULL,
                ref_id INT NOT NULL,
                label_snapshot VARCHAR(255) NOT NULL,
                INDEX idx_source_log_occurred (occurred_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            "INSERT INTO source_log (occurred_at, kind, ref_id, label_snapshot)
             SELECT created_at,
                    CASE LOWER(TRIM(source_type))
                        WHEN 'rss' THEN 'rss'
                        WHEN 'substack' THEN 'substack'
                        WHEN 'parl_press' THEN 'parl_press'
                    END,
                    id,
                    LEFT(title, 255)
               FROM feeds
              WHERE LOWER(TRIM(source_type)) IN ('rss', 'substack', 'parl_press')"
        );

        $pdo->exec(
            "INSERT INTO source_log (occurred_at, kind, ref_id, label_snapshot)
             SELECT created_at, 'scraper', id, LEFT(name, 255) FROM scraper_configs"
        );

        $pdo->exec(
            "INSERT INTO source_log (occurred_at, kind, ref_id, label_snapshot)
             SELECT created_at, 'mail', id, LEFT(display_name, 255) FROM email_subscriptions"
        );
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);

        return (bool)$stmt->fetchColumn();
    }
}
