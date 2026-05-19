<?php
/**
 * Migration 006 — `email_subscriptions.show_in_magnitu` (Slice 8).
 *
 * 0.4 stores a per-subscription visibility flag for the Magnitu pipeline.
 * The consolidated base schema omitted it; add it for module-owned mail admin.
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;

final class Migration006EmailSubscriptionsShowInMagnitu
{
    public const VERSION = 22;

    public function apply(PDO $pdo): void
    {
        if ($this->columnExists($pdo, 'email_subscriptions', 'show_in_magnitu')) {
            return;
        }
        $pdo->exec(
            'ALTER TABLE email_subscriptions
             ADD COLUMN show_in_magnitu TINYINT(1) NOT NULL DEFAULT 1 AFTER disabled'
        );
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?
                 LIMIT 1'
            );
            $stmt->execute([$table, $column]);
        } catch (PDOException $e) {
            return false;
        }

        return (bool)$stmt->fetchColumn();
    }
}
