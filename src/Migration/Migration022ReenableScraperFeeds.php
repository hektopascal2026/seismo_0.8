<?php
/**
 * Migration 022 — re-enable feeds rows that match live scraper_configs (schema 38).
 *
 * After duplicate-feed cleanup or manual disables, matching feeds may stay off while
 * scraper_configs is still enabled — core:scraper then skips them.
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use Seismo\Core\Fetcher\ScraperListingUrl;

final class Migration022ReenableScraperFeeds
{
    public const VERSION = 38;

    public static function migrationScope(): MigrationScope
    {
        return MigrationScope::MothershipOnly;
    }

    public function apply(PDO $pdo, MigrationTarget $target): void
    {
        $feeds = 'feeds';
        $sc    = 'scraper_configs';
        $urlEq = ScraperListingUrl::sqlColumnsEqual('sc.url', 'f.url');

        $pdo->exec(
            "UPDATE {$feeds} f
             INNER JOIN {$sc} sc ON {$urlEq}
             SET f.disabled = 0,
                 f.source_type = 'scraper',
                 f.url = sc.url
             WHERE sc.disabled = 0"
        );
    }
}
