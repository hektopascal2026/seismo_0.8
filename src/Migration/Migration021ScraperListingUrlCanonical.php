<?php
/**
 * Migration 021 — canonical scraper listing URLs; merge duplicate feeds (schema 37).
 *
 * Trailing slashes on listing URLs created two `feeds` rows (e.g. INTERPOL #72 vs #78).
 * Normalizes `scraper_configs` and scraper `feeds`, merges items onto the oldest feed id.
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use Seismo\Core\Fetcher\ScraperListingUrl;

final class Migration021ScraperListingUrlCanonical
{
    public const VERSION = 37;

    public static function migrationScope(): MigrationScope
    {
        return MigrationScope::MothershipOnly;
    }

    public function apply(PDO $pdo, MigrationTarget $target): void
    {
        $this->normalizeScraperConfigUrls($pdo);
        $this->normalizeAndDedupeScraperFeeds($pdo);
    }

    private function normalizeScraperConfigUrls(PDO $pdo): void
    {
        $t    = 'scraper_configs';
        $stmt = $pdo->query("SELECT id, url FROM {$t}");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $upd  = $pdo->prepare("UPDATE {$t} SET url = ? WHERE id = ?");
        foreach ($rows as $row) {
            $canonical = ScraperListingUrl::normalize((string)($row['url'] ?? ''));
            if ($canonical === '' || $canonical === (string)$row['url']) {
                continue;
            }
            $upd->execute([$canonical, (int)$row['id']]);
        }
    }

    private function normalizeAndDedupeScraperFeeds(PDO $pdo): void
    {
        $feeds = 'feeds';
        $fi    = 'feed_items';

        $configCanonical = [];
        $cfgStmt         = $pdo->query('SELECT url FROM scraper_configs');
        foreach ($cfgStmt->fetchAll(PDO::FETCH_COLUMN) as $rawUrl) {
            $canonical = ScraperListingUrl::normalize((string)$rawUrl);
            if ($canonical !== '') {
                $configCanonical[$canonical] = true;
            }
        }

        $feedStmt = $pdo->query(
            "SELECT id, url, source_type, category FROM {$feeds}"
        );
        /** @var array<string, list<int>> $groups canonical url => feed ids */
        $groups = [];
        foreach ($feedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $url = (string)($row['url'] ?? '');
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                continue;
            }
            $isScraperFeed = ($row['source_type'] ?? '') === 'scraper'
                || (string)($row['category'] ?? '') === 'scraper'
                || isset($configCanonical[ScraperListingUrl::normalize($url)]);
            if (!$isScraperFeed) {
                continue;
            }
            $canonical = ScraperListingUrl::normalize($url);
            $groups[$canonical][] = (int)$row['id'];
        }

        $updUrl = $pdo->prepare("UPDATE {$feeds} SET url = ? WHERE id = ?");
        $disDup = $pdo->prepare("UPDATE {$feeds} SET disabled = 1 WHERE id = ?");
        $delDup = $pdo->prepare(
            "DELETE d FROM {$fi} d
             INNER JOIN {$fi} k ON k.feed_id = ? AND k.guid = d.guid
             WHERE d.feed_id = ?"
        );
        $moveItems = $pdo->prepare("UPDATE {$fi} SET feed_id = ? WHERE feed_id = ?");

        foreach ($groups as $canonical => $ids) {
            sort($ids, SORT_NUMERIC);
            $keeper = $ids[0];
            $updUrl->execute([$canonical, $keeper]);

            foreach (array_slice($ids, 1) as $dupId) {
                $delDup->execute([$keeper, $dupId]);
                $moveItems->execute([$keeper, $dupId]);
                $disDup->execute([$dupId]);
            }
        }
    }
}
