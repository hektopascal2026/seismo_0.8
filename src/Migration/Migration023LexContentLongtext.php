<?php
/**
 * Migration 023 — legal corpus storage for Magnitu training.
 *
 * `lex_items.content` holds full document text (LONGTEXT); `description` stays a
 * short synopsis for the dashboard and recipe scoring. `calendar_events.content`
 * is widened from TEXT (~64 KiB) so Leg motions can store full body text.
 *
 * Schema 39.
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;
use RuntimeException;

final class Migration023LexContentLongtext
{
    public const VERSION = 39;

    public static function migrationScope(): MigrationScope
    {
        return MigrationScope::MothershipOnly;
    }

    public function apply(PDO $pdo, MigrationTarget $target): void
    {
        try {
            $pdo->exec(
                'ALTER TABLE calendar_events
                 MODIFY COLUMN content LONGTEXT NULL'
            );
            $pdo->exec(
                'ALTER TABLE lex_items
                 ADD COLUMN content LONGTEXT NULL AFTER description'
            );
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Migration 023 failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
