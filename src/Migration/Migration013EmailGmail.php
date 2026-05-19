<?php
/**
 * Migration 013 — Gmail API ingest columns on `emails` (Slice 11).
 *
 * Rollback (only when no Gmail rows were ingested):
 *   ALTER TABLE emails DROP INDEX uniq_emails_gmail_message_id;
 *   ALTER TABLE emails DROP COLUMN gmail_message_id;
 *   ALTER TABLE emails DROP COLUMN metadata;
 *
 * Schema 29.
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;
use RuntimeException;

final class Migration013EmailGmail
{
    public const VERSION = 29;

    public function apply(PDO $pdo): void
    {
        try {
            $pdo->exec(
                'ALTER TABLE emails
                 ADD COLUMN gmail_message_id VARCHAR(32) NULL DEFAULT NULL AFTER imap_uid,
                 ADD COLUMN metadata JSON NULL DEFAULT NULL AFTER raw_headers'
            );
        } catch (PDOException $e) {
            if (!self::isDuplicateColumn($e)) {
                throw new RuntimeException('Migration 013 failed: ' . $e->getMessage(), 0, $e);
            }
        }

        try {
            $pdo->exec(
                'CREATE UNIQUE INDEX uniq_emails_gmail_message_id ON emails (gmail_message_id)'
            );
        } catch (PDOException $e) {
            if (!self::isDuplicateKeyName($e)) {
                throw new RuntimeException('Migration 013 index failed: ' . $e->getMessage(), 0, $e);
            }
        }
    }

    private static function isDuplicateColumn(PDOException $e): bool
    {
        return str_contains($e->getMessage(), 'Duplicate column');
    }

    private static function isDuplicateKeyName(PDOException $e): bool
    {
        return str_contains($e->getMessage(), 'Duplicate key name');
    }
}
