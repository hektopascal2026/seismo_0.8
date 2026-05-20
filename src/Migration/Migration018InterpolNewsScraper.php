<?php
/**
 * Migration 018 — fix INTERPOL scraper to ingest News articles, not Events (schema 34).
 *
 * Listing: https://www.interpol.int/News-and-Events
 * Articles: /News-and-Events/News/{year}/{slug} (link pattern excludes /Events/).
 *
 * Idempotent: only updates rows whose URL is on interpol.int News-and-Events.
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;

final class Migration018InterpolNewsScraper
{
    public const VERSION = 34;

    public const LISTING_URL = 'https://www.interpol.int/News-and-Events';

    public const LINK_PATTERN = '/News-and-Events/News/';

    public static function migrationScope(): MigrationScope
    {
        return MigrationScope::MothershipOnly;
    }

    public function apply(PDO $pdo, MigrationTarget $target): void
    {
        $stmt = $pdo->prepare(
            'UPDATE scraper_configs
                SET url = ?,
                    link_pattern = ?
              WHERE LOWER(TRIM(url)) LIKE ?
                 OR LOWER(TRIM(url)) LIKE ?'
        );
        $stmt->execute([
            self::LISTING_URL,
            self::LINK_PATTERN,
            '%interpol.int/news-and-events%',
            '%interpol.int/en/news-and-events%',
        ]);
    }
}
