<?php
/**
 * Migration 024 — optional RSS article hydration per feed (schema 40).
 *
 * When enabled, CoreRunner follows thin feed_items links and stores readable
 * text via {@see \Seismo\Core\Fetcher\RssArticleHydrator}.
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;
use RuntimeException;

final class Migration024FeedsExtractFullText
{
    public const VERSION = 40;

    public static function migrationScope(): MigrationScope
    {
        return MigrationScope::MothershipOnly;
    }

    public function apply(PDO $pdo, MigrationTarget $target): void
    {
        try {
            $pdo->exec(
                'ALTER TABLE feeds
                 ADD COLUMN extract_full_text TINYINT(1) NOT NULL DEFAULT 0
                 AFTER disabled'
            );
        } catch (PDOException $e) {
            if (!self::columnAlreadyExists($e)) {
                throw new RuntimeException('Migration024: ' . $e->getMessage(), 0, $e);
            }
        }
    }

    private static function columnAlreadyExists(PDOException $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'Duplicate column')
            || str_contains($msg, '1060');
    }
}
