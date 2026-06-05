<?php
/**
 * Migration 027 — partition mail subscriptions into Mail vs Newsletter admin modules (schema 43).
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;
use RuntimeException;

final class Migration027EmailSubscriptionModuleScope
{
    public const VERSION = 43;

    public static function migrationScope(): MigrationScope
    {
        return MigrationScope::MothershipOnly;
    }

    public function apply(PDO $pdo, MigrationTarget $target): void
    {
        if (!$this->columnExists($pdo, 'email_subscriptions', 'module_scope')) {
            try {
                $pdo->exec(
                    "ALTER TABLE email_subscriptions
                     ADD COLUMN module_scope ENUM('mail','newsletter') NOT NULL DEFAULT 'mail'
                     AFTER category"
                );
            } catch (PDOException $e) {
                if (!self::columnAlreadyExists($e)) {
                    throw new RuntimeException('Migration027 add column failed: ' . $e->getMessage(), 0, $e);
                }
            }
        }

        $idx = 'idx_email_subscriptions_module_scope';
        if (!$this->indexExists($pdo, 'email_subscriptions', $idx)) {
            try {
                $pdo->exec(
                    'ALTER TABLE email_subscriptions ADD INDEX ' . $idx . ' (module_scope)'
                );
            } catch (PDOException $e) {
                if (!self::indexAlreadyExists($e)) {
                    throw new RuntimeException('Migration027 add index failed: ' . $e->getMessage(), 0, $e);
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
