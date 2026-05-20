<?php
/**
 * Migration 020 — soft-dismiss for emails (schema 36).
 *
 * `feed_items.hidden` already exists; emails get the same flag so ingest reruns
 * do not resurrect dismissed rows.
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;

final class Migration020EmailsHidden
{
    public const VERSION = 36;

    public static function migrationScope(): MigrationScope
    {
        return MigrationScope::MothershipOnly;
    }

    public function apply(PDO $pdo, MigrationTarget $target): void
    {
        if (!$this->columnExists($pdo, 'emails', 'hidden')) {
            try {
                $pdo->exec(
                    'ALTER TABLE emails ADD COLUMN hidden TINYINT(1) NOT NULL DEFAULT 0 AFTER created_at'
                );
            } catch (PDOException $e) {
                if (!str_contains($e->getMessage(), '1060') || !str_contains($e->getMessage(), 'hidden')) {
                    throw $e;
                }
            }
            try {
                $pdo->exec('ALTER TABLE emails ADD INDEX idx_hidden (hidden)');
            } catch (PDOException $e) {
                if (!str_contains($e->getMessage(), '1061')) {
                    throw $e;
                }
            }
        }
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
