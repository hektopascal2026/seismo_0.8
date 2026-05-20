<?php
/**
 * Migration 019 — fix ASFINAG Pressemeldungen scraper (schema 35).
 *
 * Listing is Vue/Umbraco API-driven; article URLs are discovered in code via
 * {@see \Seismo\Core\Fetcher\AsfinagPressListingDiscovery}. This migration sets
 * link_pattern, date_selector, and exclude_selectors so article pages scrape cleanly.
 *
 * Idempotent: only updates rows whose URL is on asfinag.at pressemeldungen.
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;

final class Migration019AsfinagPressScraper
{
    public const VERSION = 35;

    public const LISTING_URL = 'https://www.asfinag.at/ueber-uns/presse/pressemeldungen/';

    public const LINK_PATTERN = '/ueber-uns/presse/pressemeldungen/';

    public const DATE_SELECTOR = '.h1-module__preheadline';

    public const EXCLUDE_SELECTORS = <<<'SEL'
#cookieOverlayModal
#page-header
#page-footer
nav.nav
SEL;

    public static function migrationScope(): MigrationScope
    {
        return MigrationScope::MothershipOnly;
    }

    public function apply(PDO $pdo, MigrationTarget $target): void
    {
        $stmt = $pdo->prepare(
            'UPDATE scraper_configs
                SET url = ?,
                    link_pattern = ?,
                    date_selector = ?,
                    exclude_selectors = ?
              WHERE LOWER(TRIM(url)) LIKE ?
                 OR LOWER(TRIM(url)) LIKE ?'
        );
        $stmt->execute([
            self::LISTING_URL,
            self::LINK_PATTERN,
            self::DATE_SELECTOR,
            self::EXCLUDE_SELECTORS,
            '%asfinag.at%pressemeldungen%',
            '%asfinag.at%presse%pressemeldungen%',
        ]);
    }
}
