<?php
/**
 * Migration 017 — per-subscription email body processors + derived display titles (schema 33).
 *
 * `email_subscriptions.body_processor` selects a named ingest helper (e.g. europarl_press).
 * `emails.derived_title` stores a headline when the subject is generic digest chrome.
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;

final class Migration017EmailBodyProcessor
{
    public const VERSION = 33;

    public static function migrationScope(): MigrationScope
    {
        return MigrationScope::MothershipOnly;
    }

    public function apply(PDO $pdo, MigrationTarget $target): void
    {
        if (!$this->columnExists($pdo, 'email_subscriptions', 'body_processor')) {
            try {
                $pdo->exec(
                    'ALTER TABLE email_subscriptions ADD COLUMN body_processor VARCHAR(64) DEFAULT NULL AFTER strip_listing_boilerplate'
                );
            } catch (PDOException $e) {
                if (!str_contains($e->getMessage(), '1060') || !str_contains($e->getMessage(), 'body_processor')) {
                    throw $e;
                }
            }
        }

        if (!$this->columnExists($pdo, 'emails', 'derived_title')) {
            try {
                $pdo->exec(
                    'ALTER TABLE emails ADD COLUMN derived_title VARCHAR(500) DEFAULT NULL AFTER subject'
                );
            } catch (PDOException $e) {
                if (!str_contains($e->getMessage(), '1060') || !str_contains($e->getMessage(), 'derived_title')) {
                    throw $e;
                }
            }
        }

        $pdo->exec(
            "UPDATE email_subscriptions
             SET body_processor = 'europarl_press'
             WHERE match_type = 'domain' AND LOWER(match_value) = 'ep.europa.eu'
               AND (body_processor IS NULL OR body_processor = '')
               AND removed_at IS NULL"
        );
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
