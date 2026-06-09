<?php
/**
 * Migration 032 — `email_subscriptions.hydrate_webview` (schema 48).
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;

final class Migration032EmailSubscriptionHydrateWebview
{
    public const VERSION = 48;

    public static function migrationScope(): MigrationScope
    {
        return MigrationScope::MothershipOnly;
    }

    public function apply(PDO $pdo, MigrationTarget $target): void
    {
        if ($this->columnExists($pdo, 'email_subscriptions', 'hydrate_webview')) {
            return;
        }
        try {
            $pdo->exec(
                'ALTER TABLE email_subscriptions ADD COLUMN hydrate_webview TINYINT(1) NOT NULL DEFAULT 0 AFTER strip_listing_boilerplate'
            );
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), '1060') && str_contains($e->getMessage(), 'hydrate_webview')) {
                return;
            }
            if (str_contains($e->getMessage(), '1054') && str_contains($e->getMessage(), 'strip_listing_boilerplate')) {
                $pdo->exec(
                    'ALTER TABLE email_subscriptions ADD COLUMN hydrate_webview TINYINT(1) NOT NULL DEFAULT 0'
                );

                return;
            }
            throw $e;
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
