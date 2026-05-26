<?php

declare(strict_types=1);

namespace Seismo\Repository;

use DateTimeImmutable;
use PDO;
use PDOException;
use Seismo\Core\Fetcher\ArticleLinkNormalizer;
use Seismo\Core\Fetcher\ScraperListingUrl;
use Seismo\Core\PlainTextNormalizer;

/**
 * RSS / Substack / scraper rows in `feed_items` + `feeds` metadata.
 * All entry-source SQL goes through entryTable().
 */
final class FeedItemRepository
{
    public const MAX_LIMIT = 200;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Active RSS + Substack feeds (not scraper-only rows).
     *
     * @return list<array<string, mixed>>
     */
    public function listFeedsForRssRefresh(int $limit, int $offset): array
    {
        $limit  = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);
        $sql = 'SELECT * FROM ' . entryTable('feeds') . '
            WHERE disabled = 0
              AND source_type IN (\'rss\', \'substack\')
            ORDER BY id ASC
            LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;

        return $this->selectOrEmpty($sql);
    }

    /**
     * RSS + Substack feeds with {@see listFeedsForRssRefresh()} filters, for
     * chunked refresh: stable cursor by primary key.
     *
     * @return list<array<string, mixed>>
     */
    public function listFeedsForRssRefreshAfterId(int $afterId, int $limit): array
    {
        $limit   = max(1, min($limit, self::MAX_LIMIT));
        $afterId = max(0, $afterId);
        $sql = 'SELECT * FROM ' . entryTable('feeds') . '
            WHERE disabled = 0
              AND source_type IN (\'rss\', \'substack\')
              AND id > ?
            ORDER BY id ASC
            LIMIT ' . (int)$limit;

        return $this->selectAssocList($sql, [$afterId]);
    }

    /**
     * RSS + Substack feeds in one `feeds.category` (module-targeted refresh).
     *
     * @return list<array<string, mixed>>
     */
    public function listFeedsForRssRefreshInCategory(string $category, int $limit, int $offset): array
    {
        $limit    = max(1, min($limit, self::MAX_LIMIT));
        $offset   = max(0, $offset);
        $category = trim($category);
        $sql      = 'SELECT * FROM ' . entryTable('feeds') . "
            WHERE disabled = 0
              AND source_type IN ('rss', 'substack')
              AND IFNULL(category, '') = ?
            ORDER BY id ASC
            LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset;

        return $this->selectAssocList($sql, [$category]);
    }

    /**
     * Scraper-backed feeds in one `feeds.category` (same row shape as {@see listFeedsForScraperRefresh()}).
     *
     * @return list<array<string, mixed>>
     */
    public function listFeedsForScraperRefreshInCategory(string $category, int $limit, int $offset): array
    {
        $limit    = max(1, min($limit, self::MAX_LIMIT));
        $offset   = max(0, $offset);
        $category = trim($category);
        $feeds    = entryTable('feeds');
        $sc       = entryTable('scraper_configs');
        $urlEq    = ScraperListingUrl::sqlColumnsEqual('sc2.url', 'f.url');
        $sql      = "SELECT f.*,
            (SELECT sc2.link_pattern FROM {$sc} sc2
                WHERE {$urlEq} AND sc2.disabled = 0 ORDER BY sc2.id ASC LIMIT 1) AS scraper_link_pattern,
            (SELECT sc3.date_selector FROM {$sc} sc3
                WHERE " . ScraperListingUrl::sqlColumnsEqual('sc3.url', 'f.url') . " AND sc3.disabled = 0 ORDER BY sc3.id ASC LIMIT 1) AS scraper_date_selector,
            (SELECT sc4.exclude_selectors FROM {$sc} sc4
                WHERE " . ScraperListingUrl::sqlColumnsEqual('sc4.url', 'f.url') . " AND sc4.disabled = 0 ORDER BY sc4.id ASC LIMIT 1) AS scraper_exclude_selectors
            FROM {$feeds} f
            WHERE f.disabled = 0
              AND IFNULL(f.category, '') = ?
              AND EXISTS (
                SELECT 1 FROM {$sc} sc0 WHERE " . ScraperListingUrl::sqlColumnsEqual('sc0.url', 'f.url') . " AND sc0.disabled = 0
              )
            ORDER BY f.id ASC
            LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset;

        return $this->selectAssocList($sql, [$category]);
    }

    /**
     * Swiss Parliament press list (`source_type = parl_press`) — one logical
     * feed row; refreshed by {@see \Seismo\Service\CoreRunner::ID_PARL_PRESS}.
     *
     * @return list<array<string, mixed>>
     */
    public function listFeedsForParlPressRefresh(int $limit, int $offset): array
    {
        $limit  = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);
        $sql = 'SELECT * FROM ' . entryTable('feeds') . "
            WHERE disabled = 0
              AND source_type = 'parl_press'
            ORDER BY id ASC
            LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset;

        return $this->selectOrEmpty($sql);
    }

    /**
     * Feeds that should be scraped: must have a **live** `scraper_configs` row
     * (same URL, not disabled). Orphan `feeds` rows with `source_type = 'scraper'`
     * but no config (e.g. after deleting a source in the Scraper UI) are **excluded** —
     * otherwise refresh would still ingest them and look like "deleted sources came back".
     *
     * Includes `scraper_link_pattern`, `scraper_date_selector`, and `scraper_exclude_selectors`
     * from the first matching enabled `scraper_configs` row.
     *
     * @return list<array<string, mixed>>
     */
    public function listFeedsForScraperRefresh(int $limit, int $offset): array
    {
        $limit  = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);
        $feeds = entryTable('feeds');
        $sc    = entryTable('scraper_configs');
        $urlEq = ScraperListingUrl::sqlColumnsEqual('sc2.url', 'f.url');
        $sql = "SELECT f.*,
            (SELECT sc2.link_pattern FROM {$sc} sc2
                WHERE {$urlEq} AND sc2.disabled = 0 ORDER BY sc2.id ASC LIMIT 1) AS scraper_link_pattern,
            (SELECT sc3.date_selector FROM {$sc} sc3
                WHERE " . ScraperListingUrl::sqlColumnsEqual('sc3.url', 'f.url') . " AND sc3.disabled = 0 ORDER BY sc3.id ASC LIMIT 1) AS scraper_date_selector,
            (SELECT sc4.exclude_selectors FROM {$sc} sc4
                WHERE " . ScraperListingUrl::sqlColumnsEqual('sc4.url', 'f.url') . " AND sc4.disabled = 0 ORDER BY sc4.id ASC LIMIT 1) AS scraper_exclude_selectors
            FROM {$feeds} f
            WHERE f.disabled = 0
              AND EXISTS (
                SELECT 1 FROM {$sc} sc0 WHERE " . ScraperListingUrl::sqlColumnsEqual('sc0.url', 'f.url') . " AND sc0.disabled = 0
              )
            ORDER BY f.id ASC
            LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset;

        return $this->selectOrEmpty($sql);
    }

    /**
     * Scraper feeds (same contract as {@see listFeedsForScraperRefresh()}) with
     * `id > afterId` for chunked refresh.
     *
     * @return list<array<string, mixed>>
     */
    public function listFeedsForScraperRefreshAfterId(int $afterId, int $limit): array
    {
        $limit   = max(1, min($limit, self::MAX_LIMIT));
        $afterId = max(0, $afterId);
        $feeds = entryTable('feeds');
        $sc    = entryTable('scraper_configs');
        $sql = "SELECT f.*,
            (SELECT sc2.link_pattern FROM {$sc} sc2
                WHERE " . ScraperListingUrl::sqlColumnsEqual('sc2.url', 'f.url') . " AND sc2.disabled = 0 ORDER BY sc2.id ASC LIMIT 1) AS scraper_link_pattern,
            (SELECT sc3.date_selector FROM {$sc} sc3
                WHERE " . ScraperListingUrl::sqlColumnsEqual('sc3.url', 'f.url') . " AND sc3.disabled = 0 ORDER BY sc3.id ASC LIMIT 1) AS scraper_date_selector,
            (SELECT sc4.exclude_selectors FROM {$sc} sc4
                WHERE " . ScraperListingUrl::sqlColumnsEqual('sc4.url', 'f.url') . " AND sc4.disabled = 0 ORDER BY sc4.id ASC LIMIT 1) AS scraper_exclude_selectors
            FROM {$feeds} f
            WHERE f.disabled = 0
              AND f.id > ?
              AND EXISTS (
                SELECT 1 FROM {$sc} sc0 WHERE " . ScraperListingUrl::sqlColumnsEqual('sc0.url', 'f.url') . " AND sc0.disabled = 0
              )
            ORDER BY f.id ASC
            LIMIT " . (int)$limit;

        return $this->selectAssocList($sql, [$afterId]);
    }

    /**
     * When a `scraper_configs` row is deleted, disable matching `feeds` rows with the
     * same URL so they are not left as orphan scraper-type feeds that could
     * confuse the Feeds UI or a future looser query.
     * (URL match is the same key used in {@see listFeedsForScraperRefresh}.)
     */
    public function disableFeedsByUrl(string $url): int
    {
        if (isSatellite()) {
            throw new \RuntimeException('FeedItemRepository::disableFeedsByUrl must not run on a satellite.');
        }
        $url = ScraperListingUrl::normalize(trim($url));
        if ($url === '') {
            return 0;
        }
        $table = entryTable('feeds');
        $sql   = 'UPDATE ' . $table . ' SET disabled = 1 WHERE '
            . ScraperListingUrl::sqlColumnEqualsParam('url');
        $stmt  = $this->pdo->prepare($sql);
        $stmt->execute([$url]);

        return $stmt->rowCount();
    }

    /**
     * Make sure a `feeds` row exists for a scraper target URL and is enabled, so
     * {@see listFeedsForScraperRefresh()} picks it up. Adding a scraper source
     * via `?action=scraper` only writes `scraper_configs`; without a matching
     * `feeds` row, the core scraper refresh would silently skip the new target
     * (feed_items.feed_id is a NOT NULL FK to feeds.id, so the row is also
     * required for persistence). Idempotent: re-running keeps the existing id,
     * updates the display fields, and re-enables a previously disabled row.
     *
     * @return int feeds.id of the matched / created row
     */
    public function ensureScraperFeed(string $url, string $name, ?string $category): int
    {
        if (isSatellite()) {
            throw new \RuntimeException('FeedItemRepository::ensureScraperFeed must not run on a satellite.');
        }
        $url = ScraperListingUrl::normalize(trim($url));
        if ($url === '' || !$this->isNavigableHttpUrl($url)) {
            throw new \InvalidArgumentException('Scraper feed URL must be a navigable http(s) URL.');
        }
        $title = trim($name);
        if ($title === '') {
            $title = $url;
        }
        $cat = $category === null ? '' : trim($category);
        if ($cat === '') {
            $cat = 'scraper';
        }

        $table = entryTable('feeds');
        $sel = $this->pdo->prepare(
            'SELECT id FROM ' . $table . ' WHERE ' . ScraperListingUrl::sqlColumnEqualsParam('url') . ' ORDER BY id ASC LIMIT 1'
        );
        $sel->execute([$url]);
        $existingId = (int)($sel->fetchColumn() ?: 0);

        if ($existingId > 0) {
            $upd = $this->pdo->prepare("UPDATE {$table}
                SET url = ?,
                    source_type = 'scraper',
                    title = ?,
                    category = ?,
                    disabled = 0
                WHERE id = ?");
            $upd->execute([$url, $title, $cat, $existingId]);

            return $existingId;
        }

        $ins = $this->pdo->prepare("INSERT INTO {$table}
                (url, source_type, title, description, link, category, disabled,
                 consecutive_failures, last_error, last_error_at, last_fetched)
            VALUES (?, 'scraper', ?, NULL, NULL, ?, 0, 0, NULL, NULL, NULL)");
        $ins->execute([$url, $title, $cat]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Self-heal: ensure every enabled `scraper_configs` row has an enabled `feeds`
     * row with the same URL. Recovers scraper sources that were added through the
     * Slice 8 UI before {@see ensureScraperFeed()} existed (they ended up as
     * orphan `scraper_configs` rows that `core:scraper` could not see). Called
     * once at the start of each `core:scraper` run; idempotent.
     *
     * @return int number of new feeds rows inserted (re-enables are not counted)
     */
    public function backfillScraperFeeds(): int
    {
        if (isSatellite()) {
            return 0;
        }
        $feeds = entryTable('feeds');
        $sc    = entryTable('scraper_configs');
        try {
            // 1) Create a feeds row for every scraper_configs URL that has none.
            $insertSql = "INSERT INTO {$feeds}
                    (url, source_type, title, category, disabled,
                     consecutive_failures, last_error, last_error_at, last_fetched)
                SELECT sc.url,
                       'scraper',
                       sc.name,
                       IFNULL(NULLIF(sc.category, ''), 'scraper'),
                       0, 0, NULL, NULL, NULL
                FROM {$sc} sc
                WHERE NOT EXISTS (
                    SELECT 1 FROM {$feeds} f
                    WHERE " . ScraperListingUrl::sqlColumnsEqual('f.url', 'sc.url') . "
                )";
            $ins = $this->pdo->prepare($insertSql);
            $ins->execute();
            $created = $ins->rowCount();

            // 2) Re-enable feeds rows whose URL has a live scraper_configs match
            //    (e.g. a previously deleted source was re-added through the UI).
            $reenableSql = "UPDATE {$feeds} f
                SET f.disabled = 0
                WHERE f.disabled = 1
                  AND EXISTS (
                      SELECT 1 FROM {$sc} sc
                      WHERE " . ScraperListingUrl::sqlColumnsEqual('sc.url', 'f.url') . " AND sc.disabled = 0
                  )";
            $upd = $this->pdo->prepare($reenableSql);
            $upd->execute();

            return $created;
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return 0;
            }
            throw $e;
        }
    }

    /**
     * @param list<array<string, mixed>> $rows Normalised feed item dicts:
     *        guid, title, link, description, content, author, published_date (Y-m-d H:i:s|null)
     */
    public function upsertFeedItems(int $feedId, array $rows): int
    {
        if (isSatellite()) {
            throw new \RuntimeException('FeedItemRepository::upsertFeedItems must not run on a satellite.');
        }
        if ($rows === []) {
            return 0;
        }

        $table = entryTable('feed_items');
        $sql = 'INSERT INTO ' . $table . ' (
            feed_id, guid, title, link, link_normalized, description, content, author,
            published_date, content_hash, hidden, cached_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, UTC_TIMESTAMP()
        )
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            link = VALUES(link),
            link_normalized = VALUES(link_normalized),
            description = VALUES(description),
            content = VALUES(content),
            author = VALUES(author),
            published_date = IF(
                VALUES(content_hash) = feed_items.content_hash,
                feed_items.published_date,
                VALUES(published_date)
            ),
            content_hash = VALUES(content_hash),
            cached_at = IF(
                VALUES(content_hash) = feed_items.content_hash,
                feed_items.cached_at,
                UTC_TIMESTAMP()
            )';

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare($sql);
            $upgradeStmt = $this->pdo->prepare(
                'UPDATE ' . $table . '
                 SET feed_id = ?,
                     title = ?,
                     link = ?,
                     link_normalized = ?,
                     description = ?,
                     content = ?,
                     author = ?,
                     content_hash = ?,
                     cached_at = UTC_TIMESTAMP()
                 WHERE id = ?'
            );
            $findByLinkStmt = $this->pdo->prepare(
                'SELECT id, CHAR_LENGTH(content) AS content_len
                 FROM ' . $table . '
                 WHERE link_normalized = ? AND hidden = 0
                 LIMIT 1'
            );
            $n = 0;
            foreach ($rows as $row) {
                $guid = (string)($row['guid'] ?? '');
                $title = trim((string)($row['title'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $link = trim((string)($row['link'] ?? ''));
                if ($link === '' || !$this->isNavigableHttpUrl($link)) {
                    continue;
                }
                if ($guid === '') {
                    $guid = substr(sha1($link . "\0" . $title), 0, 32);
                }
                $desc = PlainTextNormalizer::forIngest((string)($row['description'] ?? ''));
                $content = PlainTextNormalizer::forIngest((string)($row['content'] ?? ''));
                if ($content === '' && $desc !== '') {
                    $content = $desc;
                }
                $pub = $row['published_date'] ?? null;
                $pubStr = null;
                if ($pub instanceof DateTimeImmutable) {
                    $pubStr = $pub->format('Y-m-d H:i:s');
                } elseif (is_string($pub) && $pub !== '') {
                    $pubStr = $pub;
                }
                $hash = (string)($row['content_hash'] ?? '');
                if ($hash === '') {
                    $hash = substr(sha1($link . "\0" . $content), 0, 32);
                }
                $linkNorm = mb_substr(ArticleLinkNormalizer::normalize($link), 0, 500);
                if ($linkNorm !== '') {
                    $findByLinkStmt->execute([$linkNorm]);
                    $existing = $findByLinkStmt->fetch(PDO::FETCH_ASSOC);
                    if (is_array($existing) && (int)($existing['id'] ?? 0) > 0) {
                        $existingLen = (int)($existing['content_len'] ?? 0);
                        if (strlen($content) > $existingLen) {
                            $upgradeStmt->execute([
                                $feedId,
                                $title,
                                $link,
                                $linkNorm !== '' ? $linkNorm : null,
                                $desc,
                                $content,
                                (string)($row['author'] ?? ''),
                                $hash,
                                (int)$existing['id'],
                            ]);
                        }
                        continue;
                    }
                }
                $stmt->execute([
                    $feedId,
                    $guid,
                    $title,
                    $link,
                    $linkNorm !== '' ? $linkNorm : null,
                    $desc,
                    $content,
                    (string)($row['author'] ?? ''),
                    $pubStr,
                    $hash,
                ]);
                $n++;
            }
            $this->pdo->commit();

            return $n;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function touchFeedSuccess(int $feedId): void
    {
        if (isSatellite()) {
            return;
        }
        $sql = 'UPDATE ' . entryTable('feeds') . '
            SET last_fetched = UTC_TIMESTAMP(),
                consecutive_failures = 0,
                last_error = NULL,
                last_error_at = NULL
            WHERE id = ?';
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$feedId]);
        } catch (PDOException $e) {
            error_log('FeedItemRepository::touchFeedSuccess: ' . $e->getMessage());
        }
    }

    /**
     * Remove feed_items rows that cannot come from {@see \Seismo\Core\Fetcher\ParlPressFetchService}
     * (guids are `parl_mm:{slug}` or `parl_sda:{slug}` from {@see \Seismo\Core\Fetcher\ParlPressFetchService}).
     * Same feed_id may contain RSS-shaped junk if the row was
     * ever refreshed as `source_type = rss` against the SharePoint URL — 0.4 stored `Untitled`
     * in that case ({@see cacheFeedItems} in 0.4 controllers/rss.php).
     */
    public function deleteAlienParlPressFeedItems(int $feedId): int
    {
        if (isSatellite()) {
            return 0;
        }
        if ($feedId <= 0) {
            return 0;
        }
        $table = entryTable('feed_items');
        try {
            $sql = 'DELETE FROM ' . $table . ' WHERE feed_id = ? AND NOT ('
                . "guid LIKE 'parl\\_mm:%' OR guid LIKE 'parl\\_sda:%'"
                . ')';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$feedId]);

            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log('FeedItemRepository::deleteAlienParlPressFeedItems: ' . $e->getMessage());

            return 0;
        }
    }

    public function touchFeedFailure(int $feedId, string $message): void
    {
        if (isSatellite()) {
            return;
        }
        $msg = mb_substr($message, 0, 2000);
        $sql = 'UPDATE ' . entryTable('feeds') . '
            SET consecutive_failures = consecutive_failures + 1,
                last_error = ?,
                last_error_at = UTC_TIMESTAMP()
            WHERE id = ?';
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$msg, $feedId]);
        } catch (PDOException $e) {
            error_log('FeedItemRepository::touchFeedFailure: ' . $e->getMessage());
        }
    }

    /**
     * Age column used by both `prune()` and `dryRunPrune()`. `cached_at`
     * is the row-insert timestamp (populated by MariaDB default), which
     * is the honest cutoff for retention — `published_date` comes from
     * the feed publisher and can be arbitrarily old or missing.
     */
    private const AGE_COLUMN = 'cached_at';

    /**
     * Delete feed_items older than `$olderThan` unless protected by a
     * keep-predicate. Honours the pre-Slice-5a invariant that
     * soft-deleted rows (`hidden = 1`) are themselves kept — the
     * soft-delete flag is its own retention signal (admin marked them
     * hidden; don't silently hard-delete without their say).
     *
     * @param list<string> $keepPredicates Tokens from
     *        {@see \Seismo\Service\RetentionService}.
     */
    public function prune(DateTimeImmutable $olderThan, array $keepPredicates): int
    {
        if (isSatellite()) {
            throw new \RuntimeException('FeedItemRepository::prune must not run on a satellite.');
        }

        $cutoff = $olderThan->format('Y-m-d H:i:s');
        $where  = $this->buildPruneWhere($keepPredicates);

        try {
            $stmt = $this->pdo->prepare(
                // Multi-table DELETE form: see EmailRepository::prune for the
                // rationale — alias is required by buildPruneWhere() / the
                // RetentionPredicates fragments, and the single-table DELETE
                // syntax rejects aliases (MariaDB 1064).
                'DELETE t FROM ' . entryTable('feed_items') . ' t WHERE ' . $where
            );
            $stmt->execute([$cutoff]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return 0;
            }
            throw $e;
        }
    }

    /**
     * Dry-run counterpart of `prune()`. Same WHERE clause, `SELECT
     * COUNT(*)` instead of DELETE — the two stay in sync by construction
     * because both go through `buildPruneWhere()`.
     *
     * @param list<string> $keepPredicates
     */
    public function dryRunPrune(DateTimeImmutable $olderThan, array $keepPredicates): int
    {
        $cutoff = $olderThan->format('Y-m-d H:i:s');
        $where  = $this->buildPruneWhere($keepPredicates);

        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ' . entryTable('feed_items') . ' t WHERE ' . $where
            );
            $stmt->execute([$cutoff]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return 0;
            }
            throw $e;
        }
    }

    /**
     * @param list<string> $keepPredicates
     */
    private function buildPruneWhere(array $keepPredicates): string
    {
        $keeps = \Seismo\Service\RetentionPredicates::forEntryType('feed_item', $keepPredicates);
        $where = 't.' . self::AGE_COLUMN . ' < ? AND t.hidden = 0';
        if ($keeps !== '') {
            $where .= ' AND NOT (' . $keeps . ')';
        }
        return $where;
    }

    private function isNavigableHttpUrl(string $url): bool
    {
        $u = trim($url);
        if ($u === '' || $u === '#') {
            return false;
        }

        return (bool)preg_match('#^https?://#i', $u);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function selectOrEmpty(string $sql): array
    {
        try {
            $stmt = $this->pdo->query($sql);
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return [];
            }
            throw $e;
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function selectAssocList(string $sql, array $params): array
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return [];
            }
            throw $e;
        }

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
