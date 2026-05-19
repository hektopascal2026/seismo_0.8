<?php
/**
 * Migration 010 — `email_subscriptions.strip_listing_boilerplate` (schema 26).
 *
 * When enabled, dashboard email cards strip fixed “listing page + dateline” lines
 * for that subscription (display-only; stored mail text unchanged).
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;

final class Migration010EmailSubscriptionStripListing
{
    public const VERSION = 26;

    public function apply(PDO $pdo): void
    {
        if ($this->columnExists($pdo, 'email_subscriptions', 'strip_listing_boilerplate')) {
            return;
        }
        try {
            $pdo->exec(
                'ALTER TABLE email_subscriptions ADD COLUMN strip_listing_boilerplate TINYINT(1) NOT NULL DEFAULT 0 AFTER show_in_magnitu'
            );
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), '1060') && str_contains($e->getMessage(), 'strip_listing_boilerplate')) {
                return;
            }
            if (str_contains($e->getMessage(), '1054') && str_contains($e->getMessage(), 'show_in_magnitu')) {
                $pdo->exec(
                    'ALTER TABLE email_subscriptions ADD COLUMN strip_listing_boilerplate TINYINT(1) NOT NULL DEFAULT 0'
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
