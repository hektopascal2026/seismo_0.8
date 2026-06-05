<?php
/**
 * Migration 028 — Support for multi-story digest splitting and subject filters (schema 44).
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;
use RuntimeException;

final class Migration028DigestSplitting
{
    public const VERSION = 44;

    public static function migrationScope(): MigrationScope
    {
        return MigrationScope::MothershipOnly;
    }

    public function apply(PDO $pdo, MigrationTarget $target): void
    {
        // 1. Add subject_filter to email_subscriptions
        if (!$this->columnExists($pdo, 'email_subscriptions', 'subject_filter')) {
            try {
                $pdo->exec(
                    'ALTER TABLE email_subscriptions 
                     ADD COLUMN subject_filter VARCHAR(255) DEFAULT NULL 
                     AFTER display_name'
                );
            } catch (PDOException $e) {
                if (!self::columnAlreadyExists($e)) {
                    throw new RuntimeException('Migration028 add subject_filter failed: ' . $e->getMessage(), 0, $e);
                }
            }
        }

        // 2. Add digest_split_config to email_subscriptions
        if (!$this->columnExists($pdo, 'email_subscriptions', 'digest_split_config')) {
            try {
                $pdo->exec(
                    'ALTER TABLE email_subscriptions 
                     ADD COLUMN digest_split_config JSON DEFAULT NULL 
                     AFTER cleanup_config'
                );
            } catch (PDOException $e) {
                if (!self::columnAlreadyExists($e)) {
                    throw new RuntimeException('Migration028 add digest_split_config failed: ' . $e->getMessage(), 0, $e);
                }
            }
        }

        // 3. Add parent_email_id to emails
        if (!$this->columnExists($pdo, 'emails', 'parent_email_id')) {
            try {
                $pdo->exec(
                    'ALTER TABLE emails 
                     ADD COLUMN parent_email_id BIGINT UNSIGNED DEFAULT NULL 
                     AFTER message_id'
                );
            } catch (PDOException $e) {
                if (!self::columnAlreadyExists($e)) {
                    throw new RuntimeException('Migration028 add parent_email_id failed: ' . $e->getMessage(), 0, $e);
                }
            }
        }

        // 4. Add index for parent_email_id on emails
        $idx = 'idx_emails_parent_email_id';
        if (!$this->indexExists($pdo, 'emails', $idx)) {
            try {
                $pdo->exec(
                    'ALTER TABLE emails ADD INDEX ' . $idx . ' (parent_email_id)'
                );
            } catch (PDOException $e) {
                if (!self::indexAlreadyExists($e)) {
                    throw new RuntimeException('Migration028 add index idx_emails_parent_email_id failed: ' . $e->getMessage(), 0, $e);
                }
            }
        }
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $stmt->execute([$table, $index]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private static function columnAlreadyExists(PDOException $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'Duplicate column') || str_contains($msg, '1060');
    }

    private static function indexAlreadyExists(PDOException $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'Duplicate key name') || str_contains($msg, '1061');
    }
}
