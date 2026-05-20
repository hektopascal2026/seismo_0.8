<?php
/**
 * Migration 014 — `emails.text_body` / `html_body` were TEXT (~64 KiB);
 * Gmail newsletters exceed that while `body_*` are already LONGTEXT.
 *
 * Schema 30.
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;
use RuntimeException;

final class Migration014EmailBodyLongtext
{
    public const VERSION = 30;

    public static function migrationScope(): MigrationScope
    {
        return MigrationScope::MothershipOnly;
    }

    public function apply(PDO $pdo, MigrationTarget $target): void
    {
        try {
            $pdo->exec(
                'ALTER TABLE emails
                 MODIFY COLUMN text_body LONGTEXT NULL,
                 MODIFY COLUMN html_body LONGTEXT NULL'
            );
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Migration 014 failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
