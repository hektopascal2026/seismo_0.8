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
        $sc    = 'scraper_configs';
        $fi    = 'feed_items';
        $urlEq = ScraperListingUrl::sqlColumnsEqual('sc.url', 'f.url');
        $sql   = "SELECT f.id, f.url FROM {$feeds} f
            WHERE f.source_type = 'scraper'
               OR IFNULL(f.category, '') = 'scraper'
               OR EXISTS (SELECT 1 FROM {$sc} sc WHERE {$urlEq})";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /** @var array<string, list<int>> $groups canonical url => feed ids */
        $groups = [];
        foreach ($rows as $row) {
            $url = (string)($row['url'] ?? '');
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                continue;
            }
            $canonical = ScraperListingUrl::normalize($url);
            $groups[$canonical][] = (int)$row['id'];
        }

        $updUrl = $pdo->prepare("UPDATE {$feeds} SET url = ? WHERE id = ?");
        $disDup = $pdo->prepare("UPDATE {$feeds} SET disabled = 1 WHERE id = ?");

        foreach ($groups as $canonical => $ids) {
            sort($ids, SORT_NUMERIC);
            $keeper = $ids[0];
            $updUrl->execute([$canonical, $keeper]);

            foreach (array_slice($ids, 1) as $dupId) {
                $pdo->prepare(
                    "DELETE d FROM {$fi} d
                     INNER JOIN {$fi} k ON k.feed_id = ? AND k.guid = d.guid
                     WHERE d.feed_id = ?"
                )->execute([$keeper, $dupId]);

                $pdo->prepare("UPDATE {$fi} SET feed_id = ? WHERE feed_id = ?")
                    ->execute([$keeper, $dupId]);

                $disDup->execute([$dupId]);
            }
        }
    }
}
