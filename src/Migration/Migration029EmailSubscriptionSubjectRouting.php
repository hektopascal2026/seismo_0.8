<?php
/**
 * Migration 029 — multiple newsletters per sender via subject_filter (schema 45).
 *
 * - Relax UNIQUE(match_type, match_value) → include subject_filter + module_scope
 * - Persist resolved subscription on emails.email_subscription_id
 * - Backfill parent rows and digest children from routing rules
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;
use RuntimeException;
use Seismo\Repository\EmailSubscriptionRepository;

final class Migration029EmailSubscriptionSubjectRouting
{
    public const VERSION = 45;

    public static function migrationScope(): MigrationScope
    {
        return MigrationScope::MothershipOnly;
    }

    public function apply(PDO $pdo, MigrationTarget $target): void
    {
        $this->normalizeSubjectFilterColumn($pdo);
        $this->replaceSubscriptionUniqueKey($pdo);
        $this->addEmailSubscriptionIdColumn($pdo);
        $this->backfillSubscriptionLinks($pdo);
    }

    private function normalizeSubjectFilterColumn(PDO $pdo): void
    {
        if (!$this->columnExists($pdo, 'email_subscriptions', 'subject_filter')) {
            return;
        }

        $pdo->exec(
            "UPDATE email_subscriptions SET subject_filter = '' WHERE subject_filter IS NULL"
        );

        try {
            $pdo->exec(
                "ALTER TABLE email_subscriptions
                 MODIFY COLUMN subject_filter VARCHAR(255) NOT NULL DEFAULT ''"
            );
        } catch (PDOException $e) {
            if (!self::columnAlreadyExists($e)) {
                throw new RuntimeException(
                    'Migration029 normalize subject_filter failed: ' . $e->getMessage(),
                    0,
                    $e
                );
            }
        }
    }

    private function replaceSubscriptionUniqueKey(PDO $pdo): void
    {
        if ($this->indexExists($pdo, 'email_subscriptions', 'uniq_match')) {
            try {
                $pdo->exec('ALTER TABLE email_subscriptions DROP INDEX uniq_match');
            } catch (PDOException $e) {
                if (!self::indexMissing($e)) {
                    throw new RuntimeException(
                        'Migration029 drop uniq_match failed: ' . $e->getMessage(),
                        0,
                        $e
                    );
                }
            }
        }

        if (!$this->indexExists($pdo, 'email_subscriptions', 'uniq_match')) {
            try {
                $pdo->exec(
                    'ALTER TABLE email_subscriptions
                     ADD UNIQUE KEY uniq_match (match_type, match_value, subject_filter, module_scope)'
                );
            } catch (PDOException $e) {
                if (!self::indexAlreadyExists($e)) {
                    throw new RuntimeException(
                        'Migration029 add uniq_match failed: ' . $e->getMessage(),
                        0,
                        $e
                    );
                }
            }
        }
    }

    private function addEmailSubscriptionIdColumn(PDO $pdo): void
    {
        if (!$this->columnExists($pdo, 'emails', 'email_subscription_id')) {
            try {
                $pdo->exec(
                    'ALTER TABLE emails
                     ADD COLUMN email_subscription_id INT DEFAULT NULL
                     AFTER parent_email_id'
                );
            } catch (PDOException $e) {
                if (!self::columnAlreadyExists($e)) {
                    throw new RuntimeException(
                        'Migration029 add email_subscription_id failed: ' . $e->getMessage(),
                        0,
                        $e
                    );
                }
            }
        }

        $idx = 'idx_emails_email_subscription_id';
        if (!$this->indexExists($pdo, 'emails', $idx)) {
            try {
                $pdo->exec('ALTER TABLE emails ADD INDEX ' . $idx . ' (email_subscription_id)');
            } catch (PDOException $e) {
                if (!self::indexAlreadyExists($e)) {
                    throw new RuntimeException(
                        'Migration029 add idx_emails_email_subscription_id failed: ' . $e->getMessage(),
                        0,
                        $e
                    );
                }
            }
        }
    }

    private function backfillSubscriptionLinks(PDO $pdo): void
    {
        if (!$this->columnExists($pdo, 'emails', 'email_subscription_id')) {
            return;
        }

        $repo = new EmailSubscriptionRepository($pdo);
        $repo->backfillEmailSubscriptionIds();

        $t = 'emails';
        $pdo->exec(
            "UPDATE {$t} AS c
             INNER JOIN {$t} AS p ON c.parent_email_id = p.id
             SET c.email_subscription_id = p.email_subscription_id
             WHERE c.parent_email_id IS NOT NULL
               AND p.email_subscription_id IS NOT NULL
               AND (c.email_subscription_id IS NULL OR c.email_subscription_id = 0)"
        );
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

    private static function indexMissing(PDOException $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, "Can't DROP") || str_contains($msg, '1091');
    }
}
