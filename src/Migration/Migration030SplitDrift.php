<?php
/**
 * Migration 030 — Support for split_drift column in email_subscriptions (schema 46).
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;
use RuntimeException;

final class Migration030SplitDrift
{
    public const VERSION = 46;

    public static function migrationScope(): MigrationScope
    {
        return MigrationScope::MothershipOnly;
    }

    public function apply(PDO $pdo, MigrationTarget $target): void
    {
        if (!$this->columnExists($pdo, 'email_subscriptions', 'split_drift')) {
            try {
                $pdo->exec(
                    'ALTER TABLE email_subscriptions 
                     ADD COLUMN split_drift TINYINT(1) NOT NULL DEFAULT 0 
                     AFTER auto_detected'
                );
            } catch (PDOException $e) {
                if (!self::columnAlreadyExists($e)) {
                    throw new RuntimeException('Migration030 add split_drift failed: ' . $e->getMessage(), 0, $e);
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
