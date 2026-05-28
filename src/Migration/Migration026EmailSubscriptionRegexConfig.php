<?php
/**
 * Migration 026 — per-subscription static email cleanup config (schema 42).
 *
 * Adds `email_subscriptions.cleanup_config` JSON column for holding Gemini-generated
 * regex and webview keyword rules, parsed and applied locally at runtime.
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;
use RuntimeException;

final class Migration026EmailSubscriptionRegexConfig
{
    public const VERSION = 42;

    public static function migrationScope(): MigrationScope
    {
        return MigrationScope::MothershipOnly;
    }

    public function apply(PDO $pdo, MigrationTarget $target): void
    {
        if (!$this->columnExists($pdo, 'email_subscriptions', 'cleanup_config')) {
            try {
                $pdo->exec(
                    'ALTER TABLE email_subscriptions 
                     ADD COLUMN cleanup_config JSON DEFAULT NULL 
                     AFTER body_processor'
                );
            } catch (PDOException $e) {
                if (!self::columnAlreadyExists($e)) {
                    throw new RuntimeException('Migration026 add column failed: ' . $e->getMessage(), 0, $e);
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

    private static function columnAlreadyExists(PDOException $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'Duplicate column') || str_contains($msg, '1060');
    }
}
