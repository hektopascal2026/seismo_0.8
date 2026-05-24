<?php
/**
 * Polymorphic read repository for the unified dashboard timeline.
 *
 * Returns the same wrapper shape that views/partials/dashboard_entry_loop.php
 * has consumed since 0.4:
 *
 *   [
 *     'type'         => 'feed'|'substack'|'scraper'|'email'|'lex'|'calendar',
 *     'entry_type'   => 'feed_item'|'email'|'lex_item'|'calendar_event',
 *     'entry_id'     => int,
 *     'date'         => int (unix timestamp — sort + day separators),
 *     'clock_label'  => string (bottom-right card clock; always matches date),
 *     'data'         => array (raw row from the source table — NOT escaped),
 *     'score'        => ?array (entry_scores row, local DB),
 *     'is_favourite' => bool (entry_favourites presence, local DB),
 *   ]
 *
 * Design rules enforced here:
 *
 *   - Bounded: every read method takes $limit/$offset, hard-capped at
 *     MAX_LIMIT so a runaway `?limit=1000000` URL can't OOM the shared host.
 *   - Satellite-safe: every entry-source table goes through entryTable()
 *     so a satellite reads cross-DB from the mothership. Score and favourite
 *     tables stay local (never wrapped).
 *   - Raw output: rows are returned unescaped, as MariaDB stores them.
 *     Escaping is the view's job (e(), or seismo_highlight_search_term()).
 *   - Resilient: missing entry tables (e.g. calendar_events before Leg is
 *     migrated, no fetched_emails yet) are treated as "no rows" not fatals.
 *     Fatal-hiding is limited to table-missing errors so real schema bugs
 *     still surface.
 */

declare(strict_types=1);

namespace Seismo\Repository;

use PDO;
use PDOException;
use Seismo\Core\Fetcher\ScraperListingUrl;
use Seismo\Core\Lex\LexCardPreview;

final class EntryRepository
{
    /** Short labels for Swiss case-law Lex `source` keys (Jus row merged into Lex pills). */
    private const LEX_SOURCE_LABELS = [
        'ch_bger'  => 'BGer',
        'ch_bge'   => 'BGE',
        'ch_bvger' => 'BVGE',
    ];

    /** Max body characters loaded into PHP for dashboard cards (emails + feeds). */
    private const TIMELINE_BODY_CHARS = 2000;

    /**
     * Explicit feed_items column list for JOIN queries — never `fi.*` so joined
     * `feeds` columns can never collide with item fields in associative fetchers.
     *
     * `content` is SUBSTRINGed so a MEDIUMTEXT newsletter body cannot OOM the
     * dashboard merge (see {@see TIMELINE_BODY_CHARS}).
     */
    private const SQL_FEED_ITEMS_JOIN_SELECT = 'fi.id, fi.feed_id, fi.guid, fi.title, fi.link, fi.description,
            SUBSTRING(fi.content, 1, ' . self::TIMELINE_BODY_CHARS . ') AS content,
            fi.author, fi.published_date, fi.content_hash, fi.hidden, fi.cached_at';

    /**
     * Dashboard / search reads for lex_items — corpus excerpt only (not full LONGTEXT).
     */
    private const SQL_LEX_ITEMS_TIMELINE_SELECT = 'id, celex, title, description, document_date, document_type,
            eurlex_url, work_uri, source, fetched_at, created_at,
            SUBSTRING(content, 1, ' . LexCardPreview::TIMELINE_EXCERPT_CHARS . ') AS content_excerpt';

    /**
     * Dashboard / search reads for calendar_events — omits full `content` corpus.
     */
    private const SQL_CALENDAR_EVENTS_TIMELINE_SELECT = 'id, source, external_id, title, description, event_date,
            event_end_date, event_type, status, council, url, metadata, fetched_at, created_at';

    /** @var array<int, string> */
    private const EMAIL_TIMELINE_BODY_COLUMNS = ['text_body', 'html_body', 'body_text', 'body_html'];

    /** @var array<int, string> */
    private const EMAIL_TIMELINE_SCALAR_COLUMNS = [
        'id', 'subject', 'derived_title', 'from_email', 'from_name', 'from_addr',
        'metadata', 'hidden', 'date_utc', 'date_received', 'date_sent', 'created_at',
    ];

    /**
     * Hard cap on the final timeline size.
     *
     * Per-source queries each take up to {@see mergePerSourceFetchCap()}
     * ({@see MAX_LIMIT} rows each) before merge+sort. Heavy columns (`content`,
     * email bodies) are truncated or omitted in SQL so worst-case PHP memory stays
     * bounded on a 128 MB shared host.
     */
    public const MAX_LIMIT = 200;

    /** Unified `emails` table (Slice 4 migration) — ordering preference. */
    private const SQL_EMAIL_VISIBLE = 'e.hidden = 0';
    private const EMAIL_DATE_COLUMNS = ['date_utc', 'date_received', 'created_at', 'date_sent'];

    /**
     * Per-table memo for {@see resolveEmailDateColumns()}.
     *
     * @var array<string, array<int, string>>
     */
    private array $cachedEmailDateColumns = [];

    /** Memo for {@see sqlEmailTimelineSelect()}. */
    private ?string $cachedEmailTimelineSelect = null;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Merged newest-first timeline across every entry family.
     *
     * **Paging caveat.** Per-source fetches use {@see mergePerSourceFetchCap()}
     * ({@see MAX_LIMIT} rows each). The final page
     * is still sliced from the globally sorted pool — relevance sort pushes
     * unscored rows after Magnitu-scored ones (see Settings → sort-by-relevance).
     *
     * Slice 1 has no pagination UI, so `DashboardController` clamps
     * `MAX_OFFSET = 0` in practice. When paging UI returns we'll switch to
     * cursor-based paging (e.g. `?since_id=<id>` per family) rather than
     * offset, which side-steps the skew problem entirely.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLatestTimeline(int $limit, int $offset = 0, ?TimelineFilter $filter = null, bool $sortByRelevance = false): array
    {
        $limit  = $this->clampLimit($limit);
        $offset = max(0, $offset);

        // Pull the widest bounded slice per SQL family so newcomers are not dropped
        // inside a family before the global merge/sort/slice — except where
        // TimelineFilter / hidden rows / Feed disabled excludes them.
        $perSource = $this->mergePerSourceFetchCap($limit, $offset);
        $f        = $filter;

        $items = [];
        foreach ($this->fetchFeedItems($perSource, $f) as $row) {
            $items[] = $this->wrapFeedItem($row);
        }
        foreach ($this->fetchEmails($perSource, $f) as $row) {
            $items[] = $this->wrapEmail($row);
        }
        foreach ($this->fetchLexItems($perSource, $f) as $row) {
            $items[] = $this->wrapLexItem($row);
        }
        if ($f === null || !$f->excludeCalendar) {
            foreach ($this->fetchCalendarEvents($perSource) as $row) {
                $items[] = $this->wrapCalendarEvent($row);
            }
        }

        $this->attachScores($items);
        $this->sortMergedTimeline($items, $sortByRelevance);
        $items = $this->sliceFairMergedTimeline($items, $offset, $limit, $sortByRelevance);
        $this->attachFavourites($items);
        $this->attachEmailSubscriptionDisplayNames($items);

        return $items;
    }

    /**
     * Full-text-ish search across all entry families (LIKE %term%).
     * Empty `$q` returns [] — callers should use getLatestTimeline instead.
     *
     * `$filter` uses the same {@see TimelineFilter} rules as the newest timeline
     * (including native `filters[feed][]` / `filter_form` GET shapes from the
     * dashboard filter form).
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchTimeline(string $q, int $limit, int $offset = 0, ?TimelineFilter $filter = null, bool $sortByRelevance = false): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $limit  = $this->clampLimit($limit);
        $offset = max(0, $offset);
        $perSource = $this->mergePerSourceFetchCap($limit, $offset);
        // Escape LIKE wildcards in user input so "%" and "_" are literal (MariaDB default escape \).
        $term = '%' . $this->escapeLikePattern($q) . '%';
        $f    = $filter;

        $items = [];
        foreach ($this->fetchFeedItemsSearch($term, $perSource, $f) as $row) {
            $items[] = $this->wrapFeedItem($row);
        }
        foreach ($this->searchEmailRows($term, $perSource, $f) as $row) {
            $items[] = $this->wrapEmail($row);
        }
        foreach ($this->fetchLexItemsSearch($term, $perSource, $f) as $row) {
            $items[] = $this->wrapLexItem($row);
        }
        if ($f === null || !$f->excludeCalendar) {
            foreach ($this->fetchCalendarEventsSearch($term, $perSource) as $row) {
                $items[] = $this->wrapCalendarEvent($row);
            }
        }

        $this->attachScores($items);
        $this->sortMergedTimeline($items, $sortByRelevance);
        $items = $this->sliceFairMergedTimeline($items, $offset, $limit, $sortByRelevance);
        $this->attachFavourites($items);
        $this->attachEmailSubscriptionDisplayNames($items);

        return $items;
    }

    /**
     * Dashboard **Highlights**: entries whose current score (Magnitu **or** recipe)
     * is at/above the configured alert threshold.
     *
     * Both score sources are surfaced — `entry_scores` has a unique PK on
     * `(entry_type, entry_id)` and the precedence rule in
     * {@see EntryScoreRepository::upsertRecipeScore()} guarantees there is at most
     * one row per entry, so widening the filter cannot duplicate rows. Recipe
     * scores are intentionally included so an item can reach Highlights from
     * Seismo alone, without waiting for **Magnitu v3** to pull, score, and push
     * back. Magnitu's ML output remains authoritative once it arrives (the
     * UPSERT in `EntryScoreRepository` overwrites the recipe row).
     *
     * Default order is entry date (newest first). Pass `$sortByRelevance = true`
     * for highest score first (then `scored_at`, id). The score query stays on
     * local `entry_scores` only so a missing optional family table or email
     * date-column mismatch cannot zero the list. Chronological mode loads every
     * qualifying score row (capped), hydrates, sorts in PHP, then applies
     * `$offset` / `$limit`. Relevance mode uses SQL `ORDER BY relevance_score`
     * so a generous `$limit` (see {@see MAX_LIMIT}) still returns the strongest
     * rows — ordering by `scored_at` alone would drop strong scores when a
     * batch rescore touches many rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHighlightsTimeline(
        float $alertThreshold,
        int $limit,
        int $offset = 0,
        bool $sortByRelevance = false
    ): array {
        $limit  = $this->clampLimit($limit);
        $offset = max(0, $offset);
        $alertThreshold = max(0.0, min(1.0, $alertThreshold));
        try {
            if ($sortByRelevance) {
                $stmt = $this->pdo->prepare(
                    'SELECT es.entry_type, es.entry_id, es.relevance_score, es.predicted_label, es.explanation, es.score_source
                     FROM entry_scores es
                     WHERE es.score_source IN (\'magnitu\', \'recipe\')
                       AND es.relevance_score >= ?
                       AND es.entry_type IN (\'feed_item\',\'email\',\'lex_item\',\'calendar_event\')
                     ORDER BY es.relevance_score DESC, es.scored_at DESC, es.id DESC
                     LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset
                );
                $stmt->execute([$alertThreshold]);
            } else {
                $stmt = $this->pdo->prepare(
                    'SELECT es.entry_type, es.entry_id, es.relevance_score, es.predicted_label, es.explanation, es.score_source
                     FROM entry_scores es
                     WHERE es.score_source IN (\'magnitu\', \'recipe\')
                       AND es.relevance_score >= ?
                       AND es.entry_type IN (\'feed_item\',\'email\',\'lex_item\',\'calendar_event\')
                     ORDER BY es.id DESC
                     LIMIT ' . (int)$this->highlightsChronologicalFetchCap()
                );
                $stmt->execute([$alertThreshold]);
            }
            /** @var array<int, array<string, mixed>> $scoreRows */
            $scoreRows = $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return [];
            }
            error_log('EntryRepository getHighlightsTimeline: ' . $e->getMessage());

            return [];
        }

        $items = $this->hydrateTimelineFromHighlightScoreRowsPreservingOrder($scoreRows);
        if (!$sortByRelevance) {
            $this->sortMergedTimeline($items, false);
            $items = array_slice($items, $offset, $limit);
        }

        return $items;
    }

    /**
     * Upper bound on score rows loaded before PHP chronological sort on Highlights.
     */
    private function highlightsChronologicalFetchCap(): int
    {
        return self::MAX_LIMIT * 10;
    }

    /**
     * @param array<int, array<string, mixed>> $scoreRows
     * @return array<int, array<string, mixed>>
     */
    private function hydrateTimelineFromHighlightScoreRowsPreservingOrder(array $scoreRows): array
    {
        /** @var array<string, array<string, mixed>> $best */
        $best = [];
        /** @var list<string> $orderedKeys */
        $orderedKeys = [];
        foreach ($scoreRows as $row) {
            $t = (string)($row['entry_type'] ?? '');
            $id = (int)($row['entry_id'] ?? 0);
            if ($t === '' || $id <= 0) {
                continue;
            }
            $k = $t . ':' . $id;
            if (!isset($best[$k]) || (float)$row['relevance_score'] > (float)$best[$k]['relevance_score']) {
                $best[$k] = $row;
            }
            if (!in_array($k, $orderedKeys, true)) {
                $orderedKeys[] = $k;
            }
        }
        if ($best === []) {
            return [];
        }

        $idsByType = [
            'feed_item'       => [],
            'email'           => [],
            'lex_item'        => [],
            'calendar_event'  => [],
        ];
        foreach ($best as $k => $_row) {
            $parts = explode(':', $k, 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$t, $idStr] = $parts;
            $id = (int)$idStr;
            if ($id <= 0 || !isset($idsByType[$t])) {
                continue;
            }
            $idsByType[$t][] = $id;
        }
        foreach ($idsByType as $t => $ids) {
            $idsByType[$t] = array_values(array_unique($ids));
        }

        $items = [];
        foreach ($this->fetchFeedRowsByIds($idsByType['feed_item']) as $row) {
            $w = $this->wrapFeedItem($row);
            $k = 'feed_item:' . $w['entry_id'];
            if (isset($best[$k])) {
                $w['score'] = $best[$k];
                $items[]  = $w;
            }
        }
        foreach ($this->fetchEmailRowsByIds($idsByType['email']) as $row) {
            $w = $this->wrapEmail($row);
            $k = 'email:' . $w['entry_id'];
            if (isset($best[$k])) {
                $w['score'] = $best[$k];
                $items[]  = $w;
            }
        }
        foreach ($this->fetchLexRowsByIds($idsByType['lex_item']) as $row) {
            $w = $this->wrapLexItem($row);
            $k = 'lex_item:' . $w['entry_id'];
            if (isset($best[$k])) {
                $w['score'] = $best[$k];
                $items[]  = $w;
            }
        }
        foreach ($this->fetchCalendarRowsByIds($idsByType['calendar_event']) as $row) {
            if (!$this->calendarRowVisibleInTimeline($row)) {
                continue;
            }
            $w = $this->wrapCalendarEvent($row);
            $k = 'calendar_event:' . $w['entry_id'];
            if (isset($best[$k])) {
                $w['score'] = $best[$k];
                $items[]  = $w;
            }
        }

        // Preserve score-query ordering (relevance DESC, then scored_at, id).
        $rank = array_flip($orderedKeys);
        usort(
            $items,
            static function (array $a, array $b) use ($rank): int {
                $ka = (string)($a['entry_type'] ?? '') . ':' . (string)($a['entry_id'] ?? '');
                $kb = (string)($b['entry_type'] ?? '') . ':' . (string)($b['entry_id'] ?? '');
                return ($rank[$ka] ?? PHP_INT_MAX) <=> ($rank[$kb] ?? PHP_INT_MAX);
            }
        );

        $hydratedKeys = [];
        foreach ($items as $item) {
            $hydratedKeys[(string)($item['entry_type'] ?? '') . ':' . (string)($item['entry_id'] ?? '')] = true;
        }
        $dropped = array_values(array_filter(
            $orderedKeys,
            static fn (string $k): bool => !isset($hydratedKeys[$k])
        ));
        if ($dropped !== []) {
            $sample = implode(', ', array_slice($dropped, 0, 8));
            $more   = count($dropped) > 8 ? ' …' : '';
            error_log(
                'EntryRepository highlights: ' . count($dropped)
                . ' scored row(s) could not be hydrated: ' . $sample . $more
            );
        }

        $this->attachFavourites($items);
        $this->attachEmailSubscriptionDisplayNames($items);

        return $items;
    }

    /**
     * After {@see attachScores()}, order the merged multi-family window either
     * by entry date (default) or by relevance score then date (Magnitu setting).
     * In relevance mode, items without `relevance_score` are treated as `-1.0`
     * so they appear after higher-scored rows (disable sort-by-relevance in
     * Settings for strictly newest-first across unscored ingestion).
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function sortMergedTimeline(array &$items, bool $byRelevance): void
    {
        if ($byRelevance) {
            usort(
                $items,
                static function (array $a, array $b): int {
                    $sa = isset($a['score']['relevance_score']) ? (float)$a['score']['relevance_score'] : -1.0;
                    $sb = isset($b['score']['relevance_score']) ? (float)$b['score']['relevance_score'] : -1.0;
                    if (($sb <=> $sa) !== 0) {
                        return $sb <=> $sa;
                    }

                    return ($b['date'] ?? 0) <=> ($a['date'] ?? 0);
                }
            );

            return;
        }

        usort(
            $items,
            static fn (array $a, array $b): int => ($b['date'] ?? 0) <=> ($a['date'] ?? 0)
        );
    }

    /**
     * After global merge sort, take the visible window. For the first page we
     * reserve roughly even slots across the four entry families so Lex / mail / Leg
     * are not squeezed off the dashboard when feeds dominate
     * chronologically — while still filling remaining slots in global order.
     *
     * Deeper pages use a plain slice (still bounded by the per-family merge pool).
     *
     * @param array<int, array<string, mixed>> $sortedItems
     * @return array<int, array<string, mixed>>
     */
    private function sliceFairMergedTimeline(
        array $sortedItems,
        int $offset,
        int $limit,
        bool $sortByRelevance
    ): array {
        // Chronological mode: strict newest-first slice — family quotas reorder
        // items away from the card-clock order the user sees on the bottom right.
        if (!$sortByRelevance) {
            return array_slice($sortedItems, $offset, $limit);
        }

        if ($offset > 0) {
            return array_slice($sortedItems, $offset, $limit);
        }
        if ($limit < 4 || $sortedItems === []) {
            return array_slice($sortedItems, 0, $limit);
        }

        $families = ['feed_item', 'email', 'lex_item', 'calendar_event'];
        $inPool   = array_fill_keys($families, 0);
        foreach ($sortedItems as $it) {
            $et = (string)($it['entry_type'] ?? '');
            if (isset($inPool[$et])) {
                $inPool[$et]++;
            }
        }
        $withData = [];
        foreach ($families as $f) {
            if ($inPool[$f] > 0) {
                $withData[] = $f;
            }
        }
        if ($withData === []) {
            return [];
        }

        $nFam  = count($withData);
        $base  = intdiv($limit, $nFam);
        $extra = $limit % $nFam;
        $quota = array_fill_keys($families, 0);
        foreach ($withData as $i => $f) {
            $quota[$f] = min($inPool[$f], $base + ($i < $extra ? 1 : 0));
        }

        $key = static fn (array $i): string => ($i['entry_type'] ?? '') . ':' . (int)($i['entry_id'] ?? 0);

        $picked     = [];
        $pickedKeys = [];
        foreach ($sortedItems as $it) {
            $et = (string)($it['entry_type'] ?? '');
            if (!isset($quota[$et]) || $quota[$et] <= 0) {
                continue;
            }
            $k = $key($it);
            if (isset($pickedKeys[$k])) {
                continue;
            }
            $picked[] = $it;
            $pickedKeys[$k] = true;
            $quota[$et]--;
        }
        foreach ($sortedItems as $it) {
            if (count($picked) >= $limit) {
                break;
            }
            $k = $key($it);
            if (isset($pickedKeys[$k])) {
                continue;
            }
            $picked[]       = $it;
            $pickedKeys[$k] = true;
        }

        $this->sortMergedTimeline($picked, $sortByRelevance);

        return $picked;
    }

    /**
     * All starred entries, merged and sorted by entry date (newest first).
     *
     * Loads up to {@see self::FAVOURITES_MAX_PAIRS} favourite rows from the
     * local `entry_favourites` table (most recently starred first). If a user
     * exceeds that cap, older stars are omitted until we add paging — a
     * deliberate shared-host guard.
     *
     * `$offset` is wired for API symmetry; {@see DashboardController} clamps
     * `MAX_OFFSET = 0` until cursor-based paging exists, so deep offsets do
     * not apply yet.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getFavouritesTimeline(int $limit, int $offset = 0, ?TimelineFilter $filter = null): array
    {
        $limit  = $this->clampLimit($limit);
        $offset = max(0, $offset);

        try {
            $stmt = $this->pdo->query(
                'SELECT entry_type, entry_id FROM entry_favourites
                 ORDER BY created_at DESC, id DESC
                 LIMIT ' . (int)self::FAVOURITES_MAX_PAIRS
            );
            $pairs = $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }

        $byType = [
            'feed_item'        => [],
            'email'            => [],
            'lex_item'         => [],
            'calendar_event'   => [],
        ];
        foreach ($pairs as $row) {
            $t = (string)($row['entry_type'] ?? '');
            $id = (int)($row['entry_id'] ?? 0);
            if (!isset($byType[$t]) || $id <= 0) {
                continue;
            }
            $byType[$t][] = $id;
        }
        foreach ($byType as $t => $ids) {
            $byType[$t] = array_values(array_unique($ids));
        }

        $items = [];
        foreach ($this->fetchFeedRowsByIds($byType['feed_item']) as $row) {
            $items[] = $this->wrapFeedItem($row);
        }
        foreach ($this->fetchEmailRowsByIds($byType['email']) as $row) {
            $items[] = $this->wrapEmail($row);
        }
        foreach ($this->fetchLexRowsByIds($byType['lex_item']) as $row) {
            $items[] = $this->wrapLexItem($row);
        }
        foreach ($this->fetchCalendarRowsByIds($byType['calendar_event']) as $row) {
            if (!$this->calendarRowVisibleInTimeline($row)) {
                continue;
            }
            $items[] = $this->wrapCalendarEvent($row);
        }

        foreach ($items as &$it) {
            $it['is_favourite'] = true;
        }
        unset($it);

        if ($filter !== null && $filter->isActive()) {
            $items = array_values(array_filter(
                $items,
                fn (array $it): bool => $this->itemMatchesTimelineFilter($it, $filter)
            ));
        }

        usort($items, static fn ($a, $b) => ($b['date'] ?? 0) <=> ($a['date'] ?? 0));
        $items = array_slice($items, $offset, $limit);

        $this->attachScores($items);
        $this->attachEmailSubscriptionDisplayNames($items);

        return $items;
    }

    /**
     * Safety cap on how many (entry_type, entry_id) pairs we hydrate for the
     * favourites view. Unlikely to bite real users; keeps memory bounded.
     */
    private const FAVOURITES_MAX_PAIRS = 5000;

    /**
     * Total timeline size approximation (sum of per-family counts).
     * Bounded for the same reason the list is: counts are cheap but we
     * still don't want to scan unbounded partitions on each page load.
     */
    public function countLatestTimelineApprox(): int
    {
        $total = 0;
        $total += $this->countOrZero('SELECT COUNT(*) FROM ' . entryTable('feed_items'));
        $total += $this->countOrZero('SELECT COUNT(*) FROM ' . entryTable('emails'));
        $total += $this->countOrZero('SELECT COUNT(*) FROM ' . entryTable('lex_items'));
        $total += $this->countOrZero('SELECT COUNT(*) FROM ' . entryTable('calendar_events'));
        return $total;
    }

    // ------------------------------------------------------------------
    // Per-family fetchers. Each returns raw rows, newest-first, bounded.
    // ------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchFeedItems(int $limit, ?TimelineFilter $filter = null): array
    {
        if ($filter !== null && $filter->excludeAllFeedItems) {
            return [];
        }
        $extra = $this->feedSqlFilter($filter);
        $sql = '
            SELECT ' . self::SQL_FEED_ITEMS_JOIN_SELECT . ',
                   ' . $this->sqlFeedMetaColumns() . '
            FROM ' . entryTable('feed_items') . ' fi
            JOIN ' . entryTable('feeds') . ' f ON fi.feed_id = f.id
            WHERE f.disabled = 0
              AND fi.hidden = 0
              ' . $extra['sql'] . '
            ORDER BY fi.published_date DESC, fi.cached_at DESC
            LIMIT ' . (int)$limit;

        return $extra['params'] === []
            ? $this->selectOrEmpty($sql)
            : $this->selectPreparedOrEmpty($sql, $extra['params']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchEmails(int $limit, ?TimelineFilter $filter = null): array
    {
        if ($filter !== null && $filter->excludeAllEmails) {
            return [];
        }
        $emailTags = $filter !== null && $filter->emailTags !== []
            ? $filter->emailTags
            : [];

        $dateCols = $this->resolveEmailDateColumns('emails');
        if ($dateCols === []) {
            $orderBy = 'ORDER BY e.id DESC';
        } elseif (count($dateCols) === 1) {
            $orderBy = 'ORDER BY e.`' . $dateCols[0] . '` DESC';
        } else {
            $coalesce = implode(
                ', ',
                array_map(static fn (string $c) => '`e`.`' . $c . '`', $dateCols)
            );
            $orderBy = 'ORDER BY COALESCE(' . $coalesce . ') DESC';
        }

        if ($emailTags !== []) {
            $st   = entryTable('sender_tags');
            $ph   = implode(',', array_fill(0, count($emailTags), '?'));
            $sql  = 'SELECT ' . $this->sqlEmailTimelineSelect() . ', (
                    SELECT st0.tag FROM ' . $st . ' st0
                    WHERE st0.from_email = e.from_email
                      AND st0.removed_at IS NULL
                      AND st0.tag IN (' . $ph . ')
                    ORDER BY st0.tag ASC
                    LIMIT 1
                ) AS sender_tag
                FROM ' . entryTable('emails') . ' e
                WHERE ' . self::SQL_EMAIL_VISIBLE . '
                  AND EXISTS (
                    SELECT 1 FROM ' . $st . ' stf
                    WHERE stf.from_email = e.from_email
                      AND stf.removed_at IS NULL
                      AND stf.tag IN (' . $ph . ')
                )
                ' . $orderBy . '
                LIMIT ' . (int)$limit;
            $params = array_merge($emailTags, $emailTags);

            return $this->selectPreparedOrEmpty($sql, $params);
        }

        $excludedTags = $filter !== null && $filter->excludedEmailTags !== []
            ? $filter->excludedEmailTags
            : [];
        if ($excludedTags !== []) {
            $st   = entryTable('sender_tags');
            $ph   = implode(',', array_fill(0, count($excludedTags), '?'));
            $sql  = 'SELECT ' . $this->sqlEmailTimelineSelect() . ', (
                    SELECT st0.tag FROM ' . $st . ' st0
                    WHERE st0.from_email = e.from_email
                      AND st0.removed_at IS NULL
                    ORDER BY st0.tag ASC
                    LIMIT 1
                ) AS sender_tag
                FROM ' . entryTable('emails') . ' e
                WHERE ' . self::SQL_EMAIL_VISIBLE . '
                  AND NOT EXISTS (
                    SELECT 1 FROM ' . $st . ' x
                    WHERE x.from_email = e.from_email
                      AND x.removed_at IS NULL
                      AND x.tag IN (' . $ph . ')
                )
                ' . $orderBy . '
                LIMIT ' . (int)$limit;

            return $this->selectPreparedOrEmpty($sql, $excludedTags);
        }

        $st  = entryTable('sender_tags');
        $sql = 'SELECT ' . $this->sqlEmailTimelineSelect() . ', (
                SELECT st0.tag FROM ' . $st . ' st0
                WHERE st0.from_email = e.from_email
                  AND st0.removed_at IS NULL
                ORDER BY st0.tag ASC
                LIMIT 1
            ) AS sender_tag
            FROM ' . entryTable('emails') . ' e
            WHERE ' . self::SQL_EMAIL_VISIBLE . '
            ' . $orderBy . '
            LIMIT ' . (int)$limit;

        return $this->selectOrEmpty($sql);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchLexItems(int $limit, ?TimelineFilter $filter = null): array
    {
        if ($filter !== null && $filter->excludeAllLexItems) {
            return [];
        }
        $clauses = [];
        $params  = [];
        if ($filter !== null && $filter->lexSources !== []) {
            $ph       = implode(',', array_fill(0, count($filter->lexSources), '?'));
            $clauses[] = 'source IN (' . $ph . ')';
            $params    = array_merge($params, $filter->lexSources);
        }
        if ($filter === null || $filter->lexSources === []) {
            $excl = $filter !== null ? $filter->effectiveExcludedLexSources() : [];
            if ($excl !== []) {
                $ph        = implode(',', array_fill(0, count($excl), '?'));
                $clauses[] = 'source NOT IN (' . $ph . ')';
                $params    = array_merge($params, $excl);
            }
        }
        $where = $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);
        $sql   = 'SELECT ' . self::SQL_LEX_ITEMS_TIMELINE_SELECT . ' FROM ' . entryTable('lex_items') . $where . '
                ORDER BY document_date DESC, created_at DESC
                LIMIT ' . (int)$limit;

        return $params === []
            ? $this->selectOrEmpty($sql)
            : $this->selectPreparedOrEmpty($sql, $params);
    }

    /**
     * Parliamentary activity on the dashboard — same visibility rules as the Leg page
     * ({@see CalendarEventRepository::legFeedVisibilityWhereClause()}).
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * @param array<string, mixed> $row
     */
    private function calendarRowVisibleInTimeline(array $row): bool
    {
        return (new CalendarEventRepository($this->pdo))->rowVisibleInDefaultLegFeed($row);
    }

    private function fetchCalendarEvents(int $limit): array
    {
        $cal = new CalendarEventRepository($this->pdo);
        $sql = 'SELECT ' . self::SQL_CALENDAR_EVENTS_TIMELINE_SELECT . ' FROM ' . entryTable('calendar_events') . '
                WHERE ' . $cal->legFeedVisibilityWhereClause() . '
                ORDER BY ' . $cal->legFeedAtOrderExpression() . '
                LIMIT ' . (int)$limit;

        return $this->selectPreparedOrEmpty($sql, $cal->legFeedVisibilityBindParams());
    }

    // ------------------------------------------------------------------
    // Wrappers — convert a raw row into the dashboard loop's expected shape.
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function wrapFeedItem(array $row): array
    {
        $sourceType = (string)($row['feed_source_type'] ?? '');
        $category   = (string)($row['feed_category']    ?? '');

        if ($sourceType === 'substack') {
            $type = 'substack';
        } elseif ($sourceType === 'scraper' || $category === 'scraper') {
            $type = 'scraper';
        } else {
            $type = 'feed';
        }

        $wrapper = [
            'type'         => $type,
            'entry_type'   => 'feed_item',
            'entry_id'     => (int)($row['id'] ?? 0),
            'date'         => seismo_feed_item_timeline_unix($row),
            'data'         => $row,
            'score'        => null,
            'is_favourite' => false,
        ];
        $wrapper['clock_label'] = seismo_format_wrapper_card_clock($wrapper);

        return $wrapper;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function wrapEmail(array $row): array
    {
        $wrapper = [
            'type'         => 'email',
            'entry_type'   => 'email',
            'entry_id'     => (int)($row['id'] ?? 0),
            'date'         => seismo_email_timeline_unix($row),
            'data'         => $row,
            'score'        => null,
            'is_favourite' => false,
        ];
        $wrapper['clock_label'] = seismo_format_wrapper_card_clock($wrapper);

        return $wrapper;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function wrapLexItem(array $row): array
    {
        $wrapper = [
            'type'         => 'lex',
            'entry_type'   => 'lex_item',
            'entry_id'     => (int)($row['id'] ?? 0),
            'date'         => seismo_lex_item_timeline_unix($row),
            'data'         => $row,
            'score'        => null,
            'is_favourite' => false,
        ];
        $wrapper['clock_label'] = seismo_format_wrapper_card_clock($wrapper);

        return $wrapper;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function wrapCalendarEvent(array $row): array
    {
        $wrapper = [
            'type'         => 'calendar',
            'entry_type'   => 'calendar_event',
            'entry_id'     => (int)($row['id'] ?? 0),
            'date'         => seismo_calendar_event_timeline_unix($row),
            'data'         => $row,
            'score'        => null,
            'is_favourite' => false,
        ];
        $wrapper['clock_label'] = seismo_format_wrapper_card_clock($wrapper);

        return $wrapper;
    }

    // ------------------------------------------------------------------
    // Search + favourites-by-id helpers (Slice 1.5)
    // ------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchFeedItemsSearch(string $term, int $limit, ?TimelineFilter $filter = null): array
    {
        if ($filter !== null && $filter->excludeAllFeedItems) {
            return [];
        }
        $extra = $this->feedSqlFilter($filter);
        $sql = '
            SELECT ' . self::SQL_FEED_ITEMS_JOIN_SELECT . ',
                   ' . $this->sqlFeedMetaColumns() . '
            FROM ' . entryTable('feed_items') . ' fi
            JOIN ' . entryTable('feeds') . ' f ON fi.feed_id = f.id
            WHERE f.disabled = 0
              AND fi.hidden = 0
              ' . $extra['sql'] . '
              AND (
                    fi.title LIKE ?
                 OR fi.description LIKE ?
                 OR fi.content LIKE ?
              )
            ORDER BY fi.published_date DESC, fi.cached_at DESC
            LIMIT ' . (int)$limit;
        $p = array_merge($extra['params'], [$term, $term, $term]);

        return $this->selectPreparedOrEmpty($sql, $p);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchEmailRows(string $term, int $limit, ?TimelineFilter $filter = null): array
    {
        $cols = $this->resolveEmailSearchColumns('emails');
        if ($cols === []) {
            return [];
        }
        if ($filter !== null && $filter->excludeAllEmails) {
            return [];
        }
        $parts = [];
        $params = [];
        foreach ($cols as $c) {
            $parts[] = '`e`.`' . str_replace('`', '``', $c) . '` LIKE ?';
            $params[] = $term;
        }
        $where = '(' . implode(' OR ', $parts) . ')';
        $orderBy = $this->buildEmailOrderByClause('emails');

        $emailTags = $filter !== null && $filter->emailTags !== []
            ? $filter->emailTags
            : [];

        if ($emailTags !== []) {
            $st  = entryTable('sender_tags');
            $ph  = implode(',', array_fill(0, count($emailTags), '?'));
            $sql = 'SELECT ' . $this->sqlEmailTimelineSelect() . ', (
                    SELECT st0.tag FROM ' . $st . ' st0
                    WHERE st0.from_email = e.from_email
                      AND st0.removed_at IS NULL
                      AND st0.tag IN (' . $ph . ')
                    ORDER BY st0.tag ASC
                    LIMIT 1
                ) AS sender_tag
                FROM ' . entryTable('emails') . ' e
                WHERE ' . self::SQL_EMAIL_VISIBLE . '
                  AND EXISTS (
                    SELECT 1 FROM ' . $st . ' stf
                    WHERE stf.from_email = e.from_email
                      AND stf.removed_at IS NULL
                      AND stf.tag IN (' . $ph . ')
                )
                AND ' . $where . '
                ' . $orderBy . '
                LIMIT ' . (int)$limit;
            $params = array_merge($emailTags, $emailTags, $params);

            return $this->selectPreparedOrEmpty($sql, $params);
        }

        $excludedTags = $filter !== null && $filter->excludedEmailTags !== []
            ? $filter->excludedEmailTags
            : [];
        if ($excludedTags !== []) {
            $st  = entryTable('sender_tags');
            $ph  = implode(',', array_fill(0, count($excludedTags), '?'));
            $sql = 'SELECT ' . $this->sqlEmailTimelineSelect() . ', (
                    SELECT st0.tag FROM ' . $st . ' st0
                    WHERE st0.from_email = e.from_email
                      AND st0.removed_at IS NULL
                    ORDER BY st0.tag ASC
                    LIMIT 1
                ) AS sender_tag
                FROM ' . entryTable('emails') . ' e
                WHERE ' . self::SQL_EMAIL_VISIBLE . '
                  AND NOT EXISTS (
                    SELECT 1 FROM ' . $st . ' x
                    WHERE x.from_email = e.from_email
                      AND x.removed_at IS NULL
                      AND x.tag IN (' . $ph . ')
                )
                AND ' . $where . '
                ' . $orderBy . '
                LIMIT ' . (int)$limit;
            $params = array_merge($excludedTags, $params);

            return $this->selectPreparedOrEmpty($sql, $params);
        }

        $st  = entryTable('sender_tags');
        $sql = 'SELECT ' . $this->sqlEmailTimelineSelect() . ', (
                SELECT st0.tag FROM ' . $st . ' st0
                WHERE st0.from_email = e.from_email
                  AND st0.removed_at IS NULL
                ORDER BY st0.tag ASC
                LIMIT 1
            ) AS sender_tag
            FROM ' . entryTable('emails') . ' e
            WHERE ' . self::SQL_EMAIL_VISIBLE . '
              AND ' . $where . '
            ' . $orderBy . '
            LIMIT ' . (int)$limit;

        return $this->selectPreparedOrEmpty($sql, $params);
    }

    /**
     * Subset of columns we are willing to search on an email table.
     *
     * @var array<int, string>
     */
    private const EMAIL_SEARCH_COLUMNS = [
        'subject', 'text_body', 'html_body', 'from_email', 'from_name',
        'from_addr', 'body_text', 'body_html',
    ];

    /** @var array<string, array<int, string>> memo: table name -> columns */
    private array $emailSearchColumnsCache = [];

    /**
     * @return array<int, string>
     */
    private function resolveEmailSearchColumns(string $table): array
    {
        if (isset($this->emailSearchColumnsCache[$table])) {
            return $this->emailSearchColumnsCache[$table];
        }
        $placeholders = implode(', ', array_fill(0, count(self::EMAIL_SEARCH_COLUMNS), '?'));
        $sql = 'SELECT COLUMN_NAME
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = ' . entryDbSchemaExpr() . '
                  AND TABLE_NAME = ?
                  AND COLUMN_NAME IN (' . $placeholders . ')';
        $params = array_merge([$table], self::EMAIL_SEARCH_COLUMNS);
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $present = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return $this->emailSearchColumnsCache[$table] = [];
        }
        $presentSet = array_flip(array_map('strval', $present));
        $ordered = [];
        foreach (self::EMAIL_SEARCH_COLUMNS as $col) {
            if (isset($presentSet[$col])) {
                $ordered[] = $col;
            }
        }
        return $this->emailSearchColumnsCache[$table] = $ordered;
    }

    private function buildEmailOrderByClause(string $table): string
    {
        $dateCols = $this->resolveEmailDateColumns($table);
        if ($dateCols === []) {
            return 'ORDER BY e.id DESC';
        }
        if (count($dateCols) === 1) {
            return 'ORDER BY e.`' . $dateCols[0] . '` DESC';
        }
        $coalesce = implode(
            ', ',
            array_map(static fn (string $c) => '`e`.`' . $c . '`', $dateCols)
        );

        return 'ORDER BY COALESCE(' . $coalesce . ') DESC';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchLexItemsSearch(string $term, int $limit, ?TimelineFilter $filter = null): array
    {
        if ($filter !== null && $filter->excludeAllLexItems) {
            return [];
        }
        $params   = [$term, $term, $term];
        $lexWhere = '';
        if ($filter !== null && $filter->lexSources !== []) {
            $ph        = implode(',', array_fill(0, count($filter->lexSources), '?'));
            $lexWhere .= ' AND source IN (' . $ph . ') ';
            $params    = array_merge($params, $filter->lexSources);
        }
        if ($filter === null || $filter->lexSources === []) {
            $excl = $filter !== null ? $filter->effectiveExcludedLexSources() : [];
            if ($excl !== []) {
                $ph        = implode(',', array_fill(0, count($excl), '?'));
                $lexWhere .= ' AND source NOT IN (' . $ph . ') ';
                $params    = array_merge($params, $excl);
            }
        }
        $sql = 'SELECT ' . self::SQL_LEX_ITEMS_TIMELINE_SELECT . ' FROM ' . entryTable('lex_items') . '
                WHERE (title LIKE ? OR description LIKE ? OR content LIKE ?)
                ' . $lexWhere . '
                ORDER BY document_date DESC, created_at DESC
                LIMIT ' . (int)$limit;

        return $this->selectPreparedOrEmpty($sql, $params);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchCalendarEventsSearch(string $term, int $limit): array
    {
        $cal = new CalendarEventRepository($this->pdo);
        $sql = 'SELECT ' . self::SQL_CALENDAR_EVENTS_TIMELINE_SELECT . ' FROM ' . entryTable('calendar_events') . '
                WHERE (' . $cal->legFeedVisibilityWhereClause() . ')
                  AND (title LIKE ? OR description LIKE ? OR content LIKE ?)
                ORDER BY ' . $cal->legFeedAtOrderExpression() . '
                LIMIT ' . (int)$limit;
        $bind = array_merge($cal->legFeedVisibilityBindParams(), [$term, $term, $term]);

        return $this->selectPreparedOrEmpty($sql, $bind);
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function fetchFeedRowsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->chunkIds($ids, 400) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $sql = '
                SELECT ' . self::SQL_FEED_ITEMS_JOIN_SELECT . ',
                       ' . $this->sqlFeedMetaColumns() . '
                FROM ' . entryTable('feed_items') . ' fi
                JOIN ' . entryTable('feeds') . ' f ON fi.feed_id = f.id
                WHERE fi.id IN (' . $ph . ')
                  AND fi.hidden = 0';
            foreach ($this->selectPreparedOrEmpty($sql, array_map('intval', $chunk)) as $row) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function fetchEmailRowsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->chunkIds($ids, 400) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st  = entryTable('sender_tags');
            $sql = 'SELECT ' . $this->sqlEmailTimelineSelect() . ', (
                    SELECT st0.tag FROM ' . $st . ' st0
                    WHERE st0.from_email = e.from_email
                      AND st0.removed_at IS NULL
                    ORDER BY st0.tag ASC
                    LIMIT 1
                ) AS sender_tag
                FROM ' . entryTable('emails') . ' e
                WHERE ' . self::SQL_EMAIL_VISIBLE . '
                  AND e.id IN (' . $ph . ')';
            foreach ($this->selectPreparedOrEmpty($sql, array_map('intval', $chunk)) as $row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function fetchLexRowsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->chunkIds($ids, 400) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $sql = 'SELECT ' . self::SQL_LEX_ITEMS_TIMELINE_SELECT . ' FROM ' . entryTable('lex_items') . '
                    WHERE id IN (' . $ph . ')';
            foreach ($this->selectPreparedOrEmpty($sql, array_map('intval', $chunk)) as $row) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function fetchCalendarRowsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->chunkIds($ids, 400) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $sql = 'SELECT ' . self::SQL_CALENDAR_EVENTS_TIMELINE_SELECT . ' FROM ' . entryTable('calendar_events') . '
                    WHERE id IN (' . $ph . ')';
            foreach ($this->selectPreparedOrEmpty($sql, array_map('intval', $chunk)) as $row) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, array<int, int>>
     */
    private function chunkIds(array $ids, int $chunk): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($n) => $n > 0)));
        if ($ids === []) {
            return [];
        }
        return array_chunk($ids, max(1, $chunk));
    }

    /**
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function selectPreparedOrEmpty(string $sql, array $params): array
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
        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------
    // Score + favourite joins. Both live in local tables (never wrapped).
    // ------------------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function attachScores(array &$items): void
    {
        if ($items === []) {
            return;
        }
        $pairs = $this->collectEntryKeys($items);
        if ($pairs === []) {
            return;
        }
        // Row-value IN: pulls only the scores we actually need instead of
        // scanning entry_scores end-to-end. entry_scores is PK'd on
        // (entry_type, entry_id), so MariaDB uses the index directly.
        [$placeholders, $flat] = $this->rowValueInClause($pairs);
        $sql = 'SELECT entry_type, entry_id, relevance_score, predicted_label,
                       explanation, score_source
                FROM entry_scores
                WHERE (entry_type, entry_id) IN (' . $placeholders . ')';
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($flat);
            $rows = $stmt->fetchAll();
        } catch (PDOException $e) {
            return;
        }
        $map = [];
        foreach ($rows as $row) {
            $map[$row['entry_type'] . ':' . $row['entry_id']] = $row;
        }
        foreach ($items as &$item) {
            $key = $item['entry_type'] . ':' . $item['entry_id'];
            if (isset($map[$key])) {
                $item['score'] = $map[$key];
            }
        }
        unset($item);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function attachFavourites(array &$items): void
    {
        if ($items === []) {
            return;
        }
        $pairs = $this->collectEntryKeys($items);
        if ($pairs === []) {
            return;
        }
        [$placeholders, $flat] = $this->rowValueInClause($pairs);
        $sql = 'SELECT entry_type, entry_id
                FROM entry_favourites
                WHERE (entry_type, entry_id) IN (' . $placeholders . ')';
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($flat);
            $rows = $stmt->fetchAll();
        } catch (PDOException $e) {
            return;
        }
        $set = [];
        foreach ($rows as $row) {
            $set[$row['entry_type'] . ':' . $row['entry_id']] = true;
        }
        foreach ($items as &$item) {
            $key = $item['entry_type'] . ':' . $item['entry_id'];
            if (isset($set[$key])) {
                $item['is_favourite'] = true;
            }
        }
        unset($item);
    }

    /**
     * Adds `subscription_display_name` onto email `data` from `email_subscriptions`
     * (same match semantics as {@see EmailSubscriptionRepository::matchesAddress}).
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function attachEmailSubscriptionDisplayNames(array &$items): void
    {
        $hasEmail = false;
        foreach ($items as $it) {
            if (($it['type'] ?? '') === 'email') {
                $hasEmail = true;
                break;
            }
        }
        if (!$hasEmail) {
            return;
        }
        try {
            $subs = (new EmailSubscriptionRepository($this->pdo))
                ->listActive(EmailSubscriptionRepository::MAX_LIMIT, 0);
        } catch (\Throwable) {
            return;
        }
        foreach ($items as &$it) {
            if (($it['type'] ?? '') !== 'email') {
                continue;
            }
            $from = trim((string)($it['data']['from_email'] ?? ''));
            if ($from === '') {
                continue;
            }
            $ui = EmailSubscriptionRepository::resolveSubscriptionUiForFromEmail($from, $subs);
            if ($ui['display_name'] !== null && $ui['display_name'] !== '') {
                $it['data']['subscription_display_name'] = $ui['display_name'];
            }
            if (!empty($ui['strip_listing_boilerplate'])) {
                $it['data']['subscription_strip_listing_boilerplate'] = true;
            }
        }
        unset($it);
    }

    // ------------------------------------------------------------------
    // Slice 8 — module pages (Feeds / Scraper / Mail) single-family timelines.
    // ------------------------------------------------------------------

    /**
     * RSS + Substack `feed_items` only (excludes scraper-linked feeds).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRssModuleTimeline(int $limit, int $offset): array
    {
        return $this->buildModuleFeedTimeline('rss_substack', $limit, $offset);
    }

    /**
     * Scraper-backed feed items (matches dashboard “Scraper” filter semantics).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getScraperModuleTimeline(int $limit, int $offset): array
    {
        return $this->buildModuleFeedTimeline('scraper', $limit, $offset);
    }

    /**
     * Newest emails only (same row shape as the merged dashboard).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getEmailModuleTimeline(int $limit, int $offset): array
    {
        $limit  = $this->clampLimit($limit);
        $offset = max(0, $offset);
        $rows   = $this->fetchEmailsPaged($limit, $offset);
        $items  = [];
        foreach ($rows as $row) {
            $items[] = $this->wrapEmail($row);
        }
        $this->attachScores($items);
        $this->attachFavourites($items);
        $this->attachEmailSubscriptionDisplayNames($items);

        return $items;
    }

    /**
     * Mail module timeline rows whose `from_email` matches an `email_subscriptions` rule
     * (same semantics as {@see \Seismo\Repository\EmailSubscriptionRepository::matchesAddress}).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getEmailModuleTimelineForSubscription(string $matchType, string $matchValue, int $limit, int $offset): array
    {
        $limit  = $this->clampLimit($limit);
        $offset = max(0, $offset);
        $rows   = $this->fetchEmailsMatchingSubscription($matchType, $matchValue, $limit, $offset);
        $items  = [];
        foreach ($rows as $row) {
            $items[] = $this->wrapEmail($row);
        }
        $this->attachScores($items);
        $this->attachFavourites($items);
        $this->attachEmailSubscriptionDisplayNames($items);

        return $items;
    }

    /**
     * Newest stored email for a subscription match (for Subscriptions table "Latest" link).
     *
     * @return ?array{email_id: int, subject: ?string}
     */
    public function peekLatestEmailForSubscription(string $matchType, string $matchValue): ?array
    {
        $rows = $this->fetchEmailsMatchingSubscription($matchType, $matchValue, 1, 0);
        if ($rows === []) {
            return null;
        }
        $r = $rows[0];

        return [
            'email_id' => (int)$r['id'],
            'subject'  => isset($r['subject']) && $r['subject'] !== null && $r['subject'] !== ''
                ? (string)$r['subject']
                : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildModuleFeedTimeline(string $mode, int $limit, int $offset): array
    {
        $limit  = $this->clampLimit($limit);
        $offset = max(0, $offset);
        $rows   = $this->fetchFeedItemsForModule($mode, $limit, $offset);
        $items  = [];
        foreach ($rows as $row) {
            $items[] = $this->wrapFeedItem($row);
        }
        $this->attachScores($items);
        $this->attachFavourites($items);

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchFeedItemsForModule(string $mode, int $limit, int $offset): array
    {
        $fi = entryTable('feed_items');
        $f  = entryTable('feeds');
        $sc = entryTable('scraper_configs');
        if ($mode === 'rss_substack') {
            $extra = " AND (f.source_type IN ('rss', 'substack', 'parl_press'))
                AND (IFNULL(f.category, '') <> 'scraper')
                AND NOT EXISTS (SELECT 1 FROM {$sc} sc WHERE "
            . ScraperListingUrl::sqlColumnsEqual('sc.url', 'f.url') . " AND sc.disabled = 0)";
        } elseif ($mode === 'scraper') {
            $extra = " AND (
                f.source_type = 'scraper'
                OR IFNULL(f.category, '') = 'scraper'
                OR EXISTS (SELECT 1 FROM {$sc} sc2 WHERE "
            . ScraperListingUrl::sqlColumnsEqual('sc2.url', 'f.url') . " AND sc2.disabled = 0)
            )";
        } else {
            return [];
        }

        $sql = "
            SELECT " . self::SQL_FEED_ITEMS_JOIN_SELECT . ",
                   " . $this->sqlFeedMetaColumns() . "
            FROM {$fi} fi
            JOIN {$f} f ON fi.feed_id = f.id
            WHERE f.disabled = 0
              AND fi.hidden = 0
              {$extra}
            ORDER BY fi.published_date DESC, fi.cached_at DESC
            LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset;

        return $this->selectOrEmpty($sql);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchEmailsPaged(int $limit, int $offset): array
    {
        $table = getEmailTableName();
        $emailT = entryTable($table);
        $dateCols = $this->resolveEmailDateColumns($table);
        if ($dateCols === []) {
            $orderBy = 'ORDER BY e.id DESC';
        } elseif (count($dateCols) === 1) {
            $orderBy = 'ORDER BY e.`' . $dateCols[0] . '` DESC';
        } else {
            $coalesce = implode(
                ', ',
                array_map(static fn (string $c) => '`e`.`' . $c . '`', $dateCols)
            );
            $orderBy = 'ORDER BY COALESCE(' . $coalesce . ') DESC';
        }
        $st = entryTable('sender_tags');
        $sql = 'SELECT ' . $this->sqlEmailTimelineSelect() . ', st.tag AS sender_tag
                FROM ' . $emailT . ' e
                LEFT JOIN ' . $st . ' st
                  ON st.from_email = e.from_email AND st.removed_at IS NULL
                WHERE ' . self::SQL_EMAIL_VISIBLE . '
                ' . $orderBy . '
                LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;

        return $this->selectOrEmpty($sql);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchEmailsMatchingSubscription(string $matchType, string $matchValue, int $limit, int $offset): array
    {
        $matchType = strtolower(trim($matchType));
        if ($matchType !== 'domain' && $matchType !== 'email') {
            return [];
        }
        $limit  = $this->clampLimit($limit);
        $offset = max(0, $offset);

        if ($matchType === 'email') {
            $param = strtolower(trim($matchValue));
            if ($param === '') {
                return [];
            }
            $whereSql = 'LOWER(TRIM(COALESCE(e.from_email, \'\'))) = ?';
        } else {
            $param = strtolower(ltrim(trim($matchValue), '@'));
            if ($param === '') {
                return [];
            }
            $hostExpr = 'LOWER(TRIM(SUBSTRING_INDEX(COALESCE(e.from_email, \'\'), \'@\', -1)))';
            $whereSql  = '(' . $hostExpr . ' = ? OR ' . $hostExpr . ' LIKE CONCAT(\'%.\', ?))';
        }

        $table    = getEmailTableName();
        $emailT   = entryTable($table);
        $dateCols = $this->resolveEmailDateColumns($table);
        if ($dateCols === []) {
            $orderBy = 'ORDER BY e.id DESC';
        } elseif (count($dateCols) === 1) {
            $orderBy = 'ORDER BY e.`' . $dateCols[0] . '` DESC';
        } else {
            $coalesce = implode(
                ', ',
                array_map(static fn (string $c) => '`e`.`' . $c . '`', $dateCols)
            );
            $orderBy = 'ORDER BY COALESCE(' . $coalesce . ') DESC';
        }
        $st  = entryTable('sender_tags');
        $sql = 'SELECT ' . $this->sqlEmailTimelineSelect() . ', st.tag AS sender_tag
                FROM ' . $emailT . ' e
                LEFT JOIN ' . $st . ' st
                  ON st.from_email = e.from_email AND st.removed_at IS NULL
                WHERE ' . self::SQL_EMAIL_VISIBLE . '
                  AND ' . $whereSql . '
                ' . $orderBy . '
                LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;

        try {
            $stmt = $this->pdo->prepare($sql);
            $bind = $matchType === 'email' ? [$param] : [$param, $param];
            $stmt->execute($bind);
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return [];
            }
            throw $e;
        }

        return $stmt->fetchAll();
    }

    /**
     * Pull (entry_type, entry_id) pairs out of the wrapped timeline, skipping
     * rows with missing/invalid keys. Deduped so repeats (unlikely, but
     * cheap to guard) don't bloat the IN clause.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array{0: string, 1: int}>
     */
    private function collectEntryKeys(array $items): array
    {
        $seen = [];
        $pairs = [];
        foreach ($items as $item) {
            $type = (string)($item['entry_type'] ?? '');
            $id   = (int)($item['entry_id'] ?? 0);
            if ($type === '' || $id <= 0) {
                continue;
            }
            $key = $type . ':' . $id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $pairs[] = [$type, $id];
        }
        return $pairs;
    }

    /**
     * Build a "(?, ?), (?, ?), ..." placeholder string and the flat parameter
     * array for a row-value IN clause.
     *
     * @param array<int, array{0: string, 1: int}> $pairs
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function rowValueInClause(array $pairs): array
    {
        $placeholders = implode(', ', array_fill(0, count($pairs), '(?, ?)'));
        $flat = [];
        foreach ($pairs as [$type, $id]) {
            $flat[] = $type;
            $flat[] = $id;
        }
        return [$placeholders, $flat];
    }

    /**
     * Feed title / category / source columns plus optional `scraper_config_id`
     * (MIN id for `scraper_configs.url` = `feeds.url`, disabled = 0).
     */
    private function sqlFeedMetaColumns(): string
    {
        return 'f.title       AS feed_title,
                   f.category    AS feed_category,
                   f.source_type AS feed_source_type,
                   f.url         AS feed_url,
                   f.title       AS feed_name,
                   ' . $this->sqlScraperConfigIdExpr() . ' AS scraper_config_id';
    }

    private function sqlScraperConfigIdExpr(): string
    {
        $sc = entryTable('scraper_configs');

        return '(SELECT MIN(scx.id) FROM ' . $sc . ' scx WHERE scx.url = f.url AND IFNULL(scx.disabled, 0) = 0)';
    }

    /**
     * @param list<string> $tokens
     * @return array{
     *   plain: list<string>,
     *   sc_ids: list<int>,
     *   sf_ids: list<int>,
     *   legacy_scraper_bucket: bool,
     *   parl_mm: bool,
     *   parl_sda: bool
     * }
     */
    private static function partitionFeedCategoryTokens(array $tokens): array
    {
        $plain   = [];
        $scIds   = [];
        $sfIds   = [];
        $legacyS = false;
        $parlMm  = false;
        $parlSda = false;
        foreach ($tokens as $raw) {
            if (!is_string($raw)) {
                continue;
            }
            $t = trim($raw);
            if ($t === '') {
                continue;
            }
            if ($t === 'parl_mm') {
                $parlMm = true;

                continue;
            }
            if ($t === 'parl_sda') {
                $parlSda = true;

                continue;
            }
            if ($t === 'scraper') {
                $legacyS = true;

                continue;
            }
            if (preg_match('/^sc:(\d+)$/', $t, $m)) {
                $n = (int)$m[1];
                if ($n > 0) {
                    $scIds[] = $n;
                }

                continue;
            }
            if (preg_match('/^sf:(\d+)$/', $t, $m)) {
                $n = (int)$m[1];
                if ($n > 0) {
                    $sfIds[] = $n;
                }

                continue;
            }
            $plain[] = $t;
        }

        return [
            'plain'                 => array_values(array_unique($plain)),
            'sc_ids'                => array_values(array_unique($scIds)),
            'sf_ids'                => array_values(array_unique($sfIds)),
            'legacy_scraper_bucket' => $legacyS,
            'parl_mm'               => $parlMm,
            'parl_sda'              => $parlSda,
        ];
    }

    /**
     * @param list<string> $included
     * @return array{sql: string, params: list<mixed>}
     */
    private function feedSqlCategoryInclusionClause(array $included): array
    {
        $p      = self::partitionFeedCategoryTokens($included);
        $parts  = [];
        $params = [];
        if ($p['parl_mm']) {
            $parts[] = "fi.guid LIKE 'parl_mm:%'";
        }
        if ($p['parl_sda']) {
            $parts[] = "(fi.guid LIKE 'parl_sda:%'
                OR LOWER(TRIM(IFNULL(f.category, ''))) = 'parl_sda'
                OR fi.link LIKE '%/services/news/%')";
        }
        if ($p['plain'] !== []) {
            $ph     = implode(',', array_fill(0, count($p['plain']), '?'));
            $parts[] = 'f.category IN (' . $ph . ')';
            $params  = array_merge($params, $p['plain']);
        }
        if ($p['sc_ids'] !== []) {
            $sc  = entryTable('scraper_configs');
            $ph  = implode(',', array_fill(0, count($p['sc_ids']), '?'));
            $parts[] = 'EXISTS (SELECT 1 FROM ' . $sc . ' scb WHERE scb.url = f.url AND IFNULL(scb.disabled, 0) = 0 AND scb.id IN (' . $ph . '))';
            $params = array_merge($params, $p['sc_ids']);
        }
        if ($p['sf_ids'] !== []) {
            $ph     = implode(',', array_fill(0, count($p['sf_ids']), '?'));
            $parts[] = 'f.id IN (' . $ph . ')';
            $params  = array_merge($params, $p['sf_ids']);
        }
        if ($p['legacy_scraper_bucket']) {
            $sc     = entryTable('scraper_configs');
            $parts[] = "(f.source_type = 'scraper' OR IFNULL(f.category, '') = 'scraper'
                OR EXISTS (SELECT 1 FROM {$sc} scw WHERE scw.url = f.url AND IFNULL(scw.disabled, 0) = 0))";
        }
        if ($parts === []) {
            return ['sql' => '', 'params' => []];
        }

        return ['sql' => implode(' OR ', $parts), 'params' => $params];
    }

    /**
     * @param list<string> $excluded
     * @return array{sql: string, params: list<mixed>}
     */
    private function feedSqlCategoryExclusionClause(array $excluded): array
    {
        $p      = self::partitionFeedCategoryTokens($excluded);
        $parts  = [];
        $params = [];
        if ($p['parl_mm']) {
            $parts[] = "fi.guid NOT LIKE 'parl_mm:%'";
        }
        if ($p['parl_sda']) {
            $parts[] = "fi.guid NOT LIKE 'parl_sda:%'";
        }
        if ($p['plain'] !== []) {
            $ph     = implode(',', array_fill(0, count($p['plain']), '?'));
            $parts[] = '(f.category IS NULL OR f.category NOT IN (' . $ph . '))';
            $params  = array_merge($params, $p['plain']);
        }
        if ($p['sc_ids'] !== []) {
            $sc  = entryTable('scraper_configs');
            $ph  = implode(',', array_fill(0, count($p['sc_ids']), '?'));
            $parts[] = 'NOT EXISTS (SELECT 1 FROM ' . $sc . ' scb WHERE scb.url = f.url AND IFNULL(scb.disabled, 0) = 0 AND scb.id IN (' . $ph . '))';
            $params = array_merge($params, $p['sc_ids']);
        }
        if ($p['sf_ids'] !== []) {
            $ph     = implode(',', array_fill(0, count($p['sf_ids']), '?'));
            $parts[] = 'f.id NOT IN (' . $ph . ')';
            $params  = array_merge($params, $p['sf_ids']);
        }
        if ($p['legacy_scraper_bucket']) {
            $sc     = entryTable('scraper_configs');
            $parts[] = 'NOT (f.source_type = \'scraper\' OR IFNULL(f.category, \'\') = \'scraper\'
                OR EXISTS (SELECT 1 FROM ' . $sc . ' scw WHERE scw.url = f.url AND IFNULL(scw.disabled, 0) = 0))';
        }
        if ($parts === []) {
            return ['sql' => '', 'params' => []];
        }

        return ['sql' => ' AND (' . implode(' AND ', $parts) . ')', 'params' => $params];
    }

    /**
     * Extra WHERE clause fragments for `feeds` / `feed_items` when tag filters
     * are active (Slice 4).
     *
     * @return array{sql: string, params: list<mixed>}
     */
    private function feedSqlFilter(?TimelineFilter $filter): array
    {
        if ($filter === null) {
            return ['sql' => '', 'params' => []];
        }
        $sql    = [];
        $params = [];
        if ($filter->feedCategories !== []) {
            $frag = $this->feedSqlCategoryInclusionClause($filter->feedCategories);
            if ($frag['sql'] !== '') {
                $sql[]  = ' AND (' . $frag['sql'] . ')';
                $params = array_merge($params, $frag['params']);
            }
        }
        if ($filter->feedCategories === [] && $filter->excludedFeedCategories !== []) {
            $frag = $this->feedSqlCategoryExclusionClause($filter->excludedFeedCategories);
            if ($frag['sql'] !== '') {
                $sql[]  = $frag['sql'];
                $params = array_merge($params, $frag['params']);
            }
        }
        if ($filter->feedSourceKinds !== []) {
            $frag = $this->feedSqlSourceKindOrClause($filter->feedSourceKinds);
            if ($frag['sql'] !== '') {
                $sql[]  = ' AND (' . $frag['sql'] . ')';
                $params = array_merge($params, $frag['params']);
            }
        }

        return ['sql' => implode('', $sql), 'params' => $params];
    }

    /**
     * OR of rss / substack / scraper predicates (multi-select feed type).
     *
     * @param list<string> $kinds
     * @return array{sql: string, params: list<mixed>}
     */
    private function feedSqlSourceKindOrClause(array $kinds): array
    {
        $parts  = [];
        $params = [];
        $sc     = entryTable('scraper_configs');
        foreach ($kinds as $kind) {
            if ($kind === 'substack') {
                $parts[] = "f.source_type = 'substack'";
            } elseif ($kind === 'scraper') {
                $parts[] = "(f.source_type = 'scraper' OR f.category = 'scraper'
                    OR EXISTS (SELECT 1 FROM {$sc} sc WHERE "
            . ScraperListingUrl::sqlColumnsEqual('sc.url', 'f.url') . " AND sc.disabled = 0))";
            } elseif ($kind === 'rss') {
                $parts[] = "(f.source_type NOT IN ('substack','scraper')
                    AND (f.category IS NULL OR f.category != 'scraper')
                    AND NOT EXISTS (SELECT 1 FROM {$sc} sc WHERE "
            . ScraperListingUrl::sqlColumnsEqual('sc.url', 'f.url') . " AND sc.disabled = 0))";
            }
        }
        if ($parts === []) {
            return ['sql' => '', 'params' => []];
        }

        return ['sql' => implode(' OR ', $parts), 'params' => $params];
    }

    /**
     * @param array<string, mixed> $data Raw feed row merged into `wrapFeedItem()['data']`.
     * @param list<string>         $included Category strings plus `sc:<id>` / `sf:<feedId>` tokens.
     */
    private function feedItemMatchesFeedCategoryInclusions(array $data, array $included): bool
    {
        $p = self::partitionFeedCategoryTokens($included);
        if ($p['plain'] === [] && $p['sc_ids'] === [] && $p['sf_ids'] === [] && !$p['legacy_scraper_bucket']
            && !$p['parl_mm'] && !$p['parl_sda']) {
            return false;
        }
        $guid = (string)($data['guid'] ?? '');
        if ($p['parl_mm'] && str_starts_with($guid, 'parl_mm:')) {
            return true;
        }
        if ($p['parl_sda']) {
            if (str_starts_with($guid, 'parl_sda:')) {
                return true;
            }
            if (strtolower(trim((string)($data['feed_category'] ?? ''))) === 'parl_sda') {
                return true;
            }
            $link = (string)($data['link'] ?? '');
            if ($link !== '' && str_contains($link, '/services/news/')) {
                return true;
            }
        }
        $cat = (string)($data['feed_category'] ?? '');
        if ($p['plain'] !== [] && $cat !== '' && in_array($cat, $p['plain'], true)) {
            return true;
        }
        $scId = (int)($data['scraper_config_id'] ?? 0);
        if ($scId > 0 && in_array($scId, $p['sc_ids'], true)) {
            return true;
        }
        $fid = (int)($data['feed_id'] ?? 0);
        if ($fid > 0 && in_array($fid, $p['sf_ids'], true)) {
            return true;
        }

        return $p['legacy_scraper_bucket'] && $this->feedItemIsScraperBucket($data);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $excluded
     */
    private function feedItemExcludedByFeedCategories(array $data, array $excluded): bool
    {
        $p = self::partitionFeedCategoryTokens($excluded);
        $guid = (string)($data['guid'] ?? '');
        if ($p['parl_mm'] && str_starts_with($guid, 'parl_mm:')) {
            return true;
        }
        if ($p['parl_sda'] && str_starts_with($guid, 'parl_sda:')) {
            return true;
        }
        if ($p['legacy_scraper_bucket'] && $this->feedItemIsScraperBucket($data)) {
            return true;
        }
        $cat = (string)($data['feed_category'] ?? '');
        if ($p['plain'] !== [] && $cat !== '' && in_array($cat, $p['plain'], true)) {
            return true;
        }
        $scId = (int)($data['scraper_config_id'] ?? 0);
        if ($scId > 0 && in_array($scId, $p['sc_ids'], true)) {
            return true;
        }
        $fid = (int)($data['feed_id'] ?? 0);

        return $fid > 0 && in_array($fid, $p['sf_ids'], true);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function feedItemIsScraperBucket(array $data): bool
    {
        $st  = (string)($data['feed_source_type'] ?? '');
        $cat = (string)($data['feed_category'] ?? '');
        if ($st === 'scraper' || $cat === 'scraper') {
            return true;
        }

        return (int)($data['scraper_config_id'] ?? 0) > 0;
    }

    private function itemMatchesTimelineFilter(array $item, TimelineFilter $filter): bool
    {
        $et = (string)($item['entry_type'] ?? '');

        $data = $item['data'] ?? [];
        if (!is_array($data)) {
            $data = [];
        }

        if ($filter->excludeCalendar && $et === 'calendar_event') {
            return false;
        }

        if ($filter->excludeAllFeedItems && $et === 'feed_item') {
            return false;
        }
        if ($filter->excludeAllEmails && $et === 'email') {
            return false;
        }
        if ($filter->excludeAllLexItems && $et === 'lex_item') {
            return false;
        }

        if ($filter->feedCategories !== [] && $et === 'feed_item') {
            if (!$this->feedItemMatchesFeedCategoryInclusions($data, $filter->feedCategories)) {
                return false;
            }
        }
        if ($filter->feedCategories === [] && $filter->excludedFeedCategories !== [] && $et === 'feed_item') {
            if ($this->feedItemExcludedByFeedCategories($data, $filter->excludedFeedCategories)) {
                return false;
            }
        }
        if ($filter->feedSourceKinds !== [] && $et === 'feed_item') {
            if (!$this->feedItemMatchesSourceKindFilters($data, $filter->feedSourceKinds)) {
                return false;
            }
        }
        if ($filter->lexSources !== [] && $et === 'lex_item') {
            $src = (string)($data['source'] ?? '');
            if (!in_array($src, $filter->lexSources, true)) {
                return false;
            }
        }
        if ($filter->lexSources === []) {
            $exLex = $filter->effectiveExcludedLexSources();
            if ($exLex !== [] && $et === 'lex_item') {
                $src = (string)($data['source'] ?? '');
                if (in_array($src, $exLex, true)) {
                    return false;
                }
            }
        }
        if ($filter->emailTags !== [] && $et === 'email') {
            if (!in_array((string)($data['sender_tag'] ?? ''), $filter->emailTags, true)) {
                return false;
            }
        }
        if ($filter->emailTags === [] && $filter->excludedEmailTags !== [] && $et === 'email') {
            $tg = (string)($data['sender_tag'] ?? '');
            if ($tg !== '' && in_array($tg, $filter->excludedEmailTags, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mirrors {@see feedSqlSourceKindOrClause()} for hydrated feed rows (favourites).
     *
     * @param array<string, mixed> $data Wrapped feed row (`feed_source_type`, `feed_category`, `feed_url`).
     * @param list<string>         $kinds
     */
    private function feedItemMatchesSourceKindFilters(array $data, array $kinds): bool
    {
        $st    = (string)($data['feed_source_type'] ?? '');
        $cat   = (string)($data['feed_category'] ?? '');
        $cfgId = (int)($data['scraper_config_id'] ?? 0);
        foreach ($kinds as $kind) {
            if ($kind === 'substack' && $st === 'substack') {
                return true;
            }
            if ($kind === 'scraper' && ($st === 'scraper' || $cat === 'scraper' || $cfgId > 0)) {
                return true;
            }
            if ($kind === 'rss' && $st !== 'substack' && $st !== 'scraper' && $cat !== 'scraper' && $cfgId === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Distinct values for dashboard tag pills (bounded).
     *
     * `feed_categories` lists checkbox **values**: normal `feeds.category` strings,
     * plus `sc:<scraper_config.id>` and `sf:<feeds.id>` for per-feed sources (scraper
     * orphans and `parl_press` rows — label is `feeds.title` from Add feed).
     * The literal category `scraper` is never returned; `parl_mm` / `parl_sda` category
     * strings are omitted when those feeds have their own `sf:` pills.
     *
     * @return array{
     *   feed_categories: list<string>,
     *   feed_category_labels: array<string, string>,
     *   lex_sources: list<string>,
     *   lex_source_labels: array<string, string>,
     *   email_tags: list<string>,
     * }
     */
    public function getFilterPillOptions(): array
    {
        $cats = $this->selectDistinctFeedCategories();
        $cats = array_values(array_filter($cats, static fn (string $c): bool => $c !== 'scraper'));
        $parlCategoryHide = $this->selectParlPressFeedCategories();
        $cats = array_values(array_filter(
            $cats,
            static fn (string $c): bool => !in_array($c, $parlCategoryHide, true)
                && !in_array($c, ['parl_mm', 'parl_sda'], true)
        ));
        if ($cats !== []) {
            sort($cats);
        }

        $labels = [];
        foreach ($cats as $c) {
            $labels[$c] = $c;
        }
        $tokens = $cats;
        foreach ($this->selectScraperConfigFilterEntries() as $row) {
            $tokens[]              = $row['token'];
            $labels[$row['token']] = $row['label'];
        }
        foreach ($this->selectOrphanScraperFeedEntries() as $row) {
            $tokens[]              = $row['token'];
            $labels[$row['token']] = $row['label'];
        }
        foreach ($this->selectParlPressFeedFilterEntries() as $row) {
            $tokens[]              = $row['token'];
            $labels[$row['token']] = $row['label'];
        }

        $lex = $this->selectDistinctLexSources();
        foreach (TimelineFilter::JUS_LEX_SOURCES as $jusSrc) {
            if (!in_array($jusSrc, $lex, true)) {
                $lex[] = $jusSrc;
            }
        }
        sort($lex);
        $lexLabels = [];
        foreach ($lex as $src) {
            $lexLabels[$src] = self::LEX_SOURCE_LABELS[$src] ?? $src;
        }

        return [
            'feed_categories'       => $tokens,
            'feed_category_labels'  => $labels,
            'lex_sources'           => $lex,
            'lex_source_labels'     => $lexLabels,
            'email_tags'            => $this->selectDistinctEmailTags(),
        ];
    }

    /**
     * @return list<array{token: string, label: string}>
     */
    private function selectScraperConfigFilterEntries(): array
    {
        $t   = entryTable('scraper_configs');
        $sql = 'SELECT id,
                       TRIM(COALESCE(NULLIF(name, \'\'), CONCAT(\'Scraper \', id))) AS display_label
                FROM ' . $t . '
                WHERE IFNULL(disabled, 0) = 0
                ORDER BY display_label ASC
                LIMIT 50';
        $rows = $this->selectOrEmpty($sql);
        $out  = [];
        foreach ($rows as $r) {
            $id = (int)($r['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $label = trim((string)($r['display_label'] ?? ''));
            if ($label === '') {
                $label = 'Scraper ' . $id;
            }
            $out[] = ['token' => 'sc:' . $id, 'label' => $label];
        }

        return $out;
    }

    /**
     * Scraper-type feeds with no matching `scraper_configs` row (same URL).
     *
     * @return list<array{token: string, label: string}>
     */
    private function selectOrphanScraperFeedEntries(): array
    {
        $f  = entryTable('feeds');
        $sc = entryTable('scraper_configs');
        $sql = 'SELECT f.id,
                       TRIM(COALESCE(NULLIF(f.title, \'\'), CONCAT(\'Feed \', f.id))) AS display_label
                FROM ' . $f . ' f
                WHERE f.disabled = 0
                  AND (f.source_type = \'scraper\' OR IFNULL(f.category, \'\') = \'scraper\')
                  AND NOT EXISTS (
                      SELECT 1 FROM ' . $sc . ' sc
                      WHERE ' . ScraperListingUrl::sqlColumnsEqual('sc.url', 'f.url') . ' AND IFNULL(sc.disabled, 0) = 0
                  )
                ORDER BY display_label ASC
                LIMIT 50';
        $rows = $this->selectOrEmpty($sql);
        $out  = [];
        foreach ($rows as $r) {
            $id = (int)($r['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $label = trim((string)($r['display_label'] ?? ''));
            if ($label === '') {
                $label = 'Feed ' . $id;
            }
            $out[] = ['token' => 'sf:' . $id, 'label' => $label];
        }

        return $out;
    }

    /**
     * Dashboard pills: one `sf:<feeds.id>` per enabled `parl_press` feed ({@see feeds.title}).
     *
     * @return list<array{token: string, label: string}>
     */
    private function selectParlPressFeedFilterEntries(): array
    {
        $f   = entryTable('feeds');
        $sql = 'SELECT f.id,
                       TRIM(COALESCE(NULLIF(f.title, \'\'), CONCAT(\'Feed \', f.id))) AS display_label
                FROM ' . $f . ' f
                WHERE f.disabled = 0
                  AND f.source_type = \'parl_press\'
                ORDER BY display_label ASC
                LIMIT 50';
        $rows = $this->selectOrEmpty($sql);
        $out  = [];
        foreach ($rows as $r) {
            $id = (int)($r['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $label = trim((string)($r['display_label'] ?? ''));
            if ($label === '') {
                $label = 'Feed ' . $id;
            }
            $out[] = ['token' => 'sf:' . $id, 'label' => $label];
        }

        return $out;
    }

    /**
     * Category strings used on `parl_press` feeds — hidden from generic category pills
     * because those feeds already have per-feed `sf:` tokens.
     *
     * @return list<string>
     */
    private function selectParlPressFeedCategories(): array
    {
        $sql = 'SELECT DISTINCT TRIM(category) AS category FROM ' . entryTable('feeds') . "
            WHERE disabled = 0
              AND source_type = 'parl_press'
              AND category IS NOT NULL
              AND TRIM(category) <> ''
            ORDER BY category ASC
            LIMIT 50";
        $rows = $this->selectOrEmpty($sql);
        $out  = [];
        foreach ($rows as $r) {
            $c = trim((string)($r['category'] ?? ''));
            if ($c !== '') {
                $out[] = $c;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function selectDistinctFeedCategories(): array
    {
        $sql = 'SELECT DISTINCT category FROM ' . entryTable('feeds') . '
            WHERE disabled = 0
              AND category IS NOT NULL
              AND category != \'\'
              AND category != \'unsortiert\'
            ORDER BY category ASC
            LIMIT 50';
        $rows = $this->selectOrEmpty($sql);
        $out  = [];
        foreach ($rows as $r) {
            $c = trim((string)($r['category'] ?? ''));
            if ($c !== '') {
                $out[] = $c;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function selectDistinctLexSources(): array
    {
        $sql = 'SELECT DISTINCT source FROM ' . entryTable('lex_items') . '
            ORDER BY source ASC
            LIMIT 50';
        $rows = $this->selectOrEmpty($sql);
        $out  = [];
        foreach ($rows as $r) {
            $s = trim((string)($r['source'] ?? ''));
            if ($s === '' || $s === 'parl_mm') {
                continue;
            }
            if (in_array($s, TimelineFilter::JUS_LEX_SOURCES, true)) {
                continue;
            }
            $out[] = $s;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function selectDistinctEmailTags(): array
    {
        $sql = 'SELECT DISTINCT tag FROM ' . entryTable('sender_tags') . '
            WHERE tag IS NOT NULL
              AND tag != \'\'
              AND tag != \'unclassified\'
              AND (removed_at IS NULL)
            ORDER BY tag ASC
            LIMIT 50';

        $rows = $this->selectOrEmpty($sql);
        $out  = [];
        foreach ($rows as $r) {
            $t = trim((string)($r['tag'] ?? ''));
            if ($t !== '') {
                $out[] = $t;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Infrastructure.
    // ------------------------------------------------------------------

    /**
     * Lightweight email column list for dashboard timelines — SUBSTRINGs body
     * columns and skips `raw_headers` so LONGTEXT newsletters cannot OOM PHP.
     */
    private function sqlEmailTimelineSelect(): string
    {
        if ($this->cachedEmailTimelineSelect !== null) {
            return $this->cachedEmailTimelineSelect;
        }

        $table = getEmailTableName();
        $candidates = array_merge(self::EMAIL_TIMELINE_SCALAR_COLUMNS, self::EMAIL_TIMELINE_BODY_COLUMNS);
        $placeholders = implode(', ', array_fill(0, count($candidates), '?'));
        $sql = 'SELECT COLUMN_NAME
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = ' . entryDbSchemaExpr() . '
                  AND TABLE_NAME = ?
                  AND COLUMN_NAME IN (' . $placeholders . ')';
        $params = array_merge([$table], $candidates);
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $present = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return $this->cachedEmailTimelineSelect = 'e.*';
        }

        $presentSet = array_flip(array_map('strval', $present));
        $parts = [];
        foreach (self::EMAIL_TIMELINE_SCALAR_COLUMNS as $col) {
            if (isset($presentSet[$col])) {
                $parts[] = 'e.`' . $col . '`';
            }
        }
        $bodyChars = self::TIMELINE_BODY_CHARS;
        foreach (self::EMAIL_TIMELINE_BODY_COLUMNS as $col) {
            if (isset($presentSet[$col])) {
                $parts[] = 'SUBSTRING(e.`' . $col . '`, 1, ' . $bodyChars . ') AS `' . $col . '`';
            }
        }

        if ($parts === []) {
            return $this->cachedEmailTimelineSelect = 'e.*';
        }

        return $this->cachedEmailTimelineSelect = implode(', ', $parts);
    }

    /**
     * Subset of EMAIL_DATE_COLUMNS that physically exist on the resolved
     * email table, in declaration order. Queried once per request from
     * INFORMATION_SCHEMA so the `emails` vs `fetched_emails` column split
     * doesn't 500 the dashboard.
     *
     * In satellite mode we look up the mothership schema via
     * entryDbSchemaExpr() so we see the mothership's columns, not the
     * local (scoring-only) schema.
     *
     * @return array<int, string>
     */
    private function resolveEmailDateColumns(string $table): array
    {
        if (isset($this->cachedEmailDateColumns[$table])) {
            return $this->cachedEmailDateColumns[$table];
        }
        $placeholders = implode(', ', array_fill(0, count(self::EMAIL_DATE_COLUMNS), '?'));
        $sql = 'SELECT COLUMN_NAME
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = ' . entryDbSchemaExpr() . '
                  AND TABLE_NAME = ?
                  AND COLUMN_NAME IN (' . $placeholders . ')';
        $params = array_merge([$table], self::EMAIL_DATE_COLUMNS);
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $present = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            // INFORMATION_SCHEMA is always readable on MariaDB, so this
            // shouldn't fire. Fall back to the full candidate list — if a
            // column doesn't exist we'd previously 500; now we still might
            // if the fallback is wrong. Better than silently dropping all
            // email rows, and the whole path dies in Slice 4 anyway.
            return $this->cachedEmailDateColumns[$table] = self::EMAIL_DATE_COLUMNS;
        }
        $presentSet = array_flip(array_map('strval', $present));
        $ordered = [];
        foreach (self::EMAIL_DATE_COLUMNS as $col) {
            if (isset($presentSet[$col])) {
                $ordered[] = $col;
            }
        }
        return $this->cachedEmailDateColumns[$table] = $ordered;
    }

    /**
     * Escape `\`, `%`, and `_` for use inside SQL LIKE patterns (MariaDB
     * default escape character is backslash).
     */
    private function escapeLikePattern(string $q): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
    }

    private function clampLimit(int $limit): int
    {
        if ($limit < 1) {
            return 1;
        }
        if ($limit > self::MAX_LIMIT) {
            return self::MAX_LIMIT;
        }
        return $limit;
    }

    /**
     * Newest-first rows per SQL family merged into {@see getLatestTimeline()}
     * and {@see searchTimeline()}. Always use {@see MAX_LIMIT}: a smaller cap
     * starves quieter families before the global sort (feeds crowd out Lex/mail).
     */
    private function mergePerSourceFetchCap(int $limit, int $offset): int
    {
        return self::MAX_LIMIT;
    }

    /**
     * Run a SELECT and return rows, or [] when the underlying table is
     * missing. Other PDO errors are re-thrown — silent data loss is worse
     * than a 500 surface.
     *
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
        return $stmt->fetchAll();
    }

    private function countOrZero(string $sql): int
    {
        try {
            return (int)$this->pdo->query($sql)->fetchColumn();
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return 0;
            }
            throw $e;
        }
    }
}
