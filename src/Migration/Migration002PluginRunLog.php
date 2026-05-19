<?php
/**
 * Migration 002 — plugin_run_log table (Slice 3).
 *
 * Structured diagnostics for RefreshAllService. Idempotent: CREATE TABLE IF
 * NOT EXISTS so re-running is safe on hosts where cron already created it via
 * Migration 001 (the consolidated schema also contains this DDL).
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use RuntimeException;

final class Migration002PluginRunLog
{
    public const VERSION = 18;

    public function apply(PDO $pdo): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS plugin_run_log (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            plugin_id     VARCHAR(64) NOT NULL,
            run_at        DATETIME    NOT NULL,
            status        ENUM('ok','skipped','error') NOT NULL,
            item_count    INT         NOT NULL DEFAULT 0,
            error_message TEXT        DEFAULT NULL,
            duration_ms   INT         NOT NULL DEFAULT 0,
            INDEX idx_plugin_run_at (plugin_id, run_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        try {
            $pdo->exec($sql);
        } catch (\PDOException $e) {
            throw new RuntimeException(
                'Migration 002 failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
