<?php
/**
 * Migration 012 — `plugin_run_log.status` adds `warn` for core batch fetchers with
 * partial per-source failures (some feeds ok, some failed).
 *
 * Schema 28.
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;
use RuntimeException;

final class Migration012PluginRunLogWarn
{
    public const VERSION = 28;

    public function apply(PDO $pdo): void
    {
        try {
            $pdo->exec(
                "ALTER TABLE plugin_run_log
                 MODIFY COLUMN status ENUM('ok','skipped','error','warn') NOT NULL"
            );
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Migration 012 failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
