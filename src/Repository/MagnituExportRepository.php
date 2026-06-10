<?php
/**
 * Read-only repository for the Magnitu API and the public export endpoints.
 *
 * The dashboard's {@see EntryRepository} returns rows in a wrapper shape tuned
 * for {@see \views/partials/dashboard_entry_loop.php}. The Magnitu sync
 * contract and the JSON/Markdown export surface want a flatter, per-family
 * row shape, so we keep that work here rather than overloading EntryRepository.
 *
 * Rules enforced:
 *
 *   - **Raw output.** No HTML escaping, no presentation concerns. Callers
 *     (controllers, formatters) decide how to render.
 *   - **Satellite-safe.** Every entry-source table is wrapped with
 *     {@see entryTable()} / {@see entryDbSchemaExpr()}. `entry_scores` is
 *     always local and never wrapped.
 *   - **Bounded.** Every list method takes an explicit `$limit`, hard-capped
 *     at {@see self::MAX_LIMIT} (50 rows per call — full LONGTEXT bodies;
 *     sync clients paginate with `since`).
 *   - **Leg included.** `calendar_event` rows are exported like other families
 *     (`listCalendarEventsSince`, shared JSON shape via
 *     {@see \Seismo\Controller\MagnituController::shapeCalendarEvent()}).
 */

declare(strict_types=1);

namespace Seismo\Repository;

use PDO;
use PDOException;
use Seismo\Service\ResearcherLookback;
use Seismo\Core\Fetcher\ScraperListingUrl;
use Seismo\Core\Lex\LexCardPreview;
use Seismo\Core\Mail\EmailDigestExportPolicy;

final class MagnituExportRepository
{
    /**
     * Hard cap on any single `list*()` call. Keeps worst-case PHP memory bounded
     * when fetching full email/feed LONGTEXT columns (see ingest MAX_BODY_BYTES).
     */
    public const MAX_LIMIT = 200;

    /** Researcher builder “entry limit (per module)” — higher than export API cap. */
    public const BRIEFING_MAX_LIMIT = 200;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Feed items (RSS, Substack, scraper, parl_press — same table, source_type differs).
     *
     * @param ?string $since ISO-8601 or `Y-m-d H:i:s`; NULL means "no lower bound".
     * @return array<int, array<string, mixed>>
     */
    public function listFeedItemsSince(
        ?string $since,
        int $limit,
        int $limitCap = self::MAX_LIMIT,
        bool $ascending = false,
    ): array {
        return $this->listFeedItemsQuery($since, $limit, null, $limitCap, $ascending);
    }

    /**
     * Feed items for one admin module partition (Feeds / Media / Scraper).
     *
     * Predicates match {@see EntryRepository::fetchFeedItemsForModule()}.
     *
     * @param string $module `feeds` | `media` | `scraper`
     * @return array<int, array<string, mixed>>
     */
    public function listFeedItemsForModule(
        ?string $since,
        int $limit,
        string $module,
        int $limitCap = self::MAX_LIMIT,
    ): array {
        if (!in_array($module, ['feeds', 'media', 'scraper'], true)) {
            return [];
        }

        return $this->listFeedItemsQuery($since, $limit, $module, $limitCap);
    }

    /**
     * @param ?string $moduleScope null = all feed_items; else feeds|media|scraper
     * @return array<int, array<string, mixed>>
     */
    private function listFeedItemsQuery(
        ?string $since,
        int $limit,
        ?string $moduleScope,
        int $limitCap = self::MAX_LIMIT,
        bool $ascending = false,
    ): array {
        $limit = $this->clampLimit($limit, $limitCap);
        $sql = 'SELECT fi.id, fi.title, fi.description, fi.content, fi.link, fi.author,
                       fi.published_date, fi.cached_at,
                       f.title       AS feed_title,
                       f.category    AS feed_category,
                       f.source_type AS source_type,
                       (SELECT MIN(scx.id) FROM ' . entryTable('scraper_configs') . ' scx WHERE scx.url = f.url AND IFNULL(scx.disabled, 0) = 0) AS scraper_config_id
                  FROM ' . entryTable('feed_items') . ' fi
                  JOIN ' . entryTable('feeds') . ' f ON fi.feed_id = f.id
                 WHERE f.disabled = 0
                   AND fi.hidden = 0'
            . $this->feedModuleSqlExtra($moduleScope);
        $params = [];
        $lookback = ResearcherLookback::feedItemsSinceClause($since);
        $sql .= $lookback['sql'];
        foreach ($lookback['params'] as $p) {
            $params[] = $p;
        }
        $sql .= $ascending
            ? ' ORDER BY (fi.published_date IS NULL), fi.published_date ASC, fi.id ASC LIMIT ' . $limit
            : ' ORDER BY fi.published_date DESC, fi.id DESC LIMIT ' . $limit;

        return $this->selectOrEmpty($sql, $params);
    }

    /**
     * @param ?string $module `feeds` | `media` | `scraper`, or null for no extra filter.
     */
    private function feedModuleSqlExtra(?string $module): string
    {
        if ($module === null) {
            return '';
        }

        $sc = entryTable('scraper_configs');

        return match ($module) {
            'feeds' => " AND (f.source_type IN ('rss', 'substack', 'parl_press'))
                AND (IFNULL(f.category, '') NOT IN ('scraper', 'media'))
                AND NOT EXISTS (SELECT 1 FROM {$sc} sc WHERE "
                . ScraperListingUrl::sqlColumnsEqual('sc.url', 'f.url') . ' AND sc.disabled = 0)',
            'media' => " AND IFNULL(f.category, '') = 'media' ",
            'scraper' => " AND (
                f.source_type = 'scraper'
                OR IFNULL(f.category, '') = 'scraper'
                OR EXISTS (SELECT 1 FROM {$sc} sc2 WHERE "
                . ScraperListingUrl::sqlColumnsEqual('sc2.url', 'f.url') . " AND sc2.disabled = 0)
            )",
            default => '',
        };
    }

    /**
     * Emails from the unified `emails` table (post Slice 4 migration).
     *
     * Joins sender_tags (left) for classification. The 0.4 subscription-based
     * hiding (`esShouldHideEmail` and friends) is intentionally NOT ported in
     * Slice 5 — those helpers are tied to legacy sender-management flows that
     * graduate in a later slice. Consumers that need the same suppression
     * behaviour should filter on `source_category` at the consumer side.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listEmailsSince(
        ?string $since,
        int $limit,
        int $limitCap = self::MAX_LIMIT,
        bool $ascending = false,
    ): array {
        $limit = $this->clampLimit($limit, $limitCap);
        $table = entryTable(getEmailTableName());

        // Pick the best available date column for ordering. Old installs that
        // never ran the Slice-4 merge may still have `date_received` only;
        // post-merge we prefer `date_utc`. Fail soft on missing table.
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = ' . entryDbSchemaExpr() . '
                    AND TABLE_NAME = ?'
            );
            $stmt->execute([getEmailTableName()]);
            $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return [];
        }
        if ($cols === []) {
            return [];
        }

        $dateCol = $this->pickColumn($cols, ['date_utc', 'date_received', 'created_at', 'date_sent']);
        if ($dateCol === null) {
            return [];
        }
        $textBodyCol = $this->pickColumn($cols, ['text_body', 'body_text']);
        $htmlBodyCol = $this->pickColumn($cols, ['html_body', 'body_html']);
        $fromEmailCol = $this->pickColumn($cols, ['from_email', 'from_addr']);
        $fromNameExpr = in_array('from_name', $cols, true) ? 'e.from_name' : "''";
        $derivedSel   = in_array('derived_title', $cols, true) ? 'e.derived_title' : "'' AS derived_title";
        $metaSel      = in_array('metadata', $cols, true) ? 'e.metadata' : 'NULL AS metadata';

        if ($textBodyCol === null || $htmlBodyCol === null || $fromEmailCol === null) {
            return [];
        }

        $sql = "SELECT e.id,
                       e.subject,
                       {$derivedSel},
                       e.`{$fromEmailCol}` AS from_email,
                       {$fromNameExpr}     AS from_name,
                       e.`{$textBodyCol}`  AS text_body,
                       e.`{$htmlBodyCol}`  AS html_body,
                       e.`{$dateCol}`      AS entry_date,
                       {$metaSel},
                       COALESCE(st.tag, 'unclassified') AS sender_tag
                  FROM {$table} e
             LEFT JOIN " . entryTable('sender_tags') . " st
                    ON st.from_email = e.`{$fromEmailCol}`
                   AND st.removed_at IS NULL
                   AND st.disabled = 0";
        $params = [];
        $hiddenClause = in_array('hidden', $cols, true) ? 'e.hidden = 0' : '1=1';
        $exportableClause = in_array('parent_email_id', $cols, true)
            ? ' AND ' . EmailDigestExportPolicy::sqlExportableEmail('e')
            : '';
        $ingestCol = $this->pickColumn($cols, ['created_at', 'date_received']);
        if ($since !== null && $since !== '') {
            if ($ingestCol !== null && $ingestCol !== $dateCol) {
                $sql .= " WHERE {$hiddenClause}{$exportableClause} AND (e.`{$dateCol}` >= ? OR e.`{$ingestCol}` >= ?)";
                $params[] = $since;
                $params[] = $since;
            } else {
                $sql .= " WHERE {$hiddenClause}{$exportableClause} AND e.`{$dateCol}` >= ?";
                $params[] = $since;
            }
        } else {
            $sql .= " WHERE {$hiddenClause}{$exportableClause}";
        }
        $sql .= $ascending
            ? " ORDER BY e.`{$dateCol}` ASC, e.id ASC LIMIT {$limit}"
            : " ORDER BY e.`{$dateCol}` DESC, e.id DESC LIMIT {$limit}";

        return $this->selectOrEmpty($sql, $params);
    }

    /**
     * Lex items — every Lex source is one `lex_items` table with a `source`
     * column (`eu`, `ch`, `de`, `fr`, `ch_bger`, `ch_bge`, `ch_bvger`).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listLexItemsSince(
        ?string $since,
        int $limit,
        int $limitCap = self::MAX_LIMIT,
        bool $ascending = false,
    ): array {
        $limit = $this->clampLimit($limit, $limitCap);
        $sql = 'SELECT id, celex, title, description, content, document_date, document_type, eurlex_url, source
                  FROM ' . entryTable('lex_items');
        $params = [];
        if ($since !== null && $since !== '') {
            $sql .= ' WHERE (document_date >= ? OR fetched_at >= ?)';
            $params[] = $since;
            $params[] = $since;
        }
        $sql .= $ascending
            ? ' ORDER BY (document_date IS NULL), document_date ASC, id ASC LIMIT ' . $limit
            : ' ORDER BY document_date DESC, id DESC LIMIT ' . $limit;

        return $this->selectOrEmpty($sql, $params);
    }

    /**
     * Leg / parliamentary business (`calendar_events`).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listCalendarEventsSince(
        ?string $since,
        int $limit,
        int $limitCap = self::MAX_LIMIT,
        bool $ascending = false,
    ): array {
        $limit = $this->clampLimit($limit, $limitCap);
        $table = entryTable('calendar_events');
        $sql   = "SELECT id, source, title, description, content, event_date, event_end_date,
                         event_type, status, council, url
                    FROM {$table}
                   WHERE 1=1";
        $params = [];
        if ($since !== null && $since !== '') {
            $sql .= ' AND (
                (event_date IS NOT NULL AND event_date >= DATE(?))
                OR fetched_at >= ?
            )';
            $params[] = $since;
            $params[] = $since;
        }
        $sql .= $ascending
            ? ' ORDER BY (event_date IS NULL), event_date ASC, id ASC LIMIT ' . $limit
            : ' ORDER BY (event_date IS NULL), event_date DESC, id DESC LIMIT ' . $limit;

        return $this->selectOrEmpty($sql, $params);
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function listFeedItemsByIds(array $ids, ?string $since, bool $includeFullBody = true): array
    {
        $ids = $this->normalizePositiveIds($ids);
        if ($ids === []) {
            return [];
        }

        $contentCol = $includeFullBody ? 'fi.content' : 'NULL AS content';
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'SELECT fi.id, fi.title, fi.description, ' . $contentCol . ', fi.link, fi.author,
                       fi.published_date, fi.cached_at,
                       f.title       AS feed_title,
                       f.category    AS feed_category,
                       f.source_type AS source_type,
                       (SELECT MIN(scx.id) FROM ' . entryTable('scraper_configs') . ' scx WHERE scx.url = f.url AND IFNULL(scx.disabled, 0) = 0) AS scraper_config_id
                  FROM ' . entryTable('feed_items') . ' fi
                  JOIN ' . entryTable('feeds') . ' f ON fi.feed_id = f.id
                 WHERE f.disabled = 0
                   AND fi.hidden = 0
                   AND fi.id IN (' . $placeholders . ')';
        $params = $ids;
        $lookback = ResearcherLookback::feedItemsSinceClause($since);
        $sql .= $lookback['sql'];
        foreach ($lookback['params'] as $p) {
            $params[] = $p;
        }

        return $this->selectOrEmpty($sql, $params);
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function listEmailsByIds(array $ids, ?string $since, bool $includeFullBody = true): array
    {
        $ids = $this->normalizePositiveIds($ids);
        if ($ids === []) {
            return [];
        }

        $table = entryTable(getEmailTableName());
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = ' . entryDbSchemaExpr() . '
                    AND TABLE_NAME = ?'
            );
            $stmt->execute([getEmailTableName()]);
            $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return [];
        }
        if ($cols === []) {
            return [];
        }

        $dateCol = $this->pickColumn($cols, ['date_utc', 'date_received', 'created_at', 'date_sent']);
        $textBodyCol = $this->pickColumn($cols, ['text_body', 'body_text']);
        $htmlBodyCol = $this->pickColumn($cols, ['html_body', 'body_html']);
        $fromEmailCol = $this->pickColumn($cols, ['from_email', 'from_addr']);
        if ($dateCol === null || $textBodyCol === null || $htmlBodyCol === null || $fromEmailCol === null) {
            return [];
        }

        $fromNameExpr = in_array('from_name', $cols, true) ? 'e.from_name' : "''";
        $derivedCol   = in_array('derived_title', $cols, true) ? 'e.derived_title' : "'' AS derived_title";
        $metaCol      = in_array('metadata', $cols, true) ? 'e.metadata' : 'NULL AS metadata';
        if ($includeFullBody) {
            $textSel = "e.`{$textBodyCol}` AS text_body";
            $htmlSel = "e.`{$htmlBodyCol}` AS html_body";
        } else {
            $textSel = "LEFT(e.`{$textBodyCol}`, 512) AS text_body";
            $htmlSel = "'' AS html_body";
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT e.id,
                       e.subject,
                       {$derivedCol},
                       e.`{$fromEmailCol}` AS from_email,
                       {$fromNameExpr}     AS from_name,
                       {$textSel},
                       {$htmlSel},
                       e.`{$dateCol}`      AS entry_date,
                       {$metaCol},
                       COALESCE(st.tag, 'unclassified') AS sender_tag
                  FROM {$table} e
             LEFT JOIN " . entryTable('sender_tags') . " st
                    ON st.from_email = e.`{$fromEmailCol}`
                   AND st.removed_at IS NULL
                   AND st.disabled = 0
                 WHERE e.id IN ({$placeholders})";
        $params = $ids;
        $hiddenClause = in_array('hidden', $cols, true) ? ' AND e.hidden = 0' : '';
        $exportableClause = in_array('parent_email_id', $cols, true)
            ? ' AND ' . EmailDigestExportPolicy::sqlExportableEmail('e')
            : '';
        $sql .= $hiddenClause . $exportableClause;
        $ingestCol = $this->pickColumn($cols, ['created_at', 'date_received']);
        if ($since !== null && $since !== '') {
            if ($ingestCol !== null && $ingestCol !== $dateCol) {
                $sql .= " AND (e.`{$dateCol}` >= ? OR e.`{$ingestCol}` >= ?)";
                $params[] = $since;
                $params[] = $since;
            } else {
                $sql .= " AND e.`{$dateCol}` >= ?";
                $params[] = $since;
            }
        }

        return $this->selectOrEmpty($sql, $params);
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function listLexItemsByIds(array $ids, ?string $since, bool $includeFullBody = true): array
    {
        $ids = $this->normalizePositiveIds($ids);
        if ($ids === []) {
            return [];
        }

        $contentCol   = $includeFullBody ? 'content' : 'NULL AS content';
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'SELECT id, celex, title, description, ' . $contentCol . ', document_date, document_type, eurlex_url, source
                  FROM ' . entryTable('lex_items') . '
                 WHERE id IN (' . $placeholders . ')';
        $params = $ids;
        if ($since !== null && $since !== '') {
            $sql .= ' AND (document_date >= ? OR fetched_at >= ?)';
            $params[] = $since;
            $params[] = $since;
        }

        return $this->selectOrEmpty($sql, $params);
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function listCalendarEventsByIds(array $ids, ?string $since, bool $includeFullBody = true): array
    {
        $ids = $this->normalizePositiveIds($ids);
        if ($ids === []) {
            return [];
        }

        $contentCol   = $includeFullBody ? 'content' : 'NULL AS content';
        $table        = entryTable('calendar_events');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT id, source, title, description, {$contentCol}, event_date, event_end_date,
                       event_type, status, council, url
                  FROM {$table}
                 WHERE id IN ({$placeholders})";
        $params = $ids;
        if ($since !== null && $since !== '') {
            $sql .= ' AND (
                (event_date IS NOT NULL AND event_date >= DATE(?))
                OR fetched_at >= ?
            )';
            $params[] = $since;
            $params[] = $since;
        }

        return $this->selectOrEmpty($sql, $params);
    }

    /**
     * Fetch entry_scores rows for a list of (entry_type, entry_id) pairs.
     *
     * Local-only — `entry_scores` never lives on the mothership in satellite
     * mode. Returns a map keyed by `"type:id"` for O(1) lookup by callers.
     *
     * @param array<int, array{0: string, 1: int}> $pairs
     * @return array<string, array<string, mixed>>
     */
    public function scoresByEntryKey(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($pairs), '(?, ?)'));
        $flat = [];
        foreach ($pairs as [$t, $id]) {
            $flat[] = $t;
            $flat[] = (int)$id;
        }
        $sql = 'SELECT entry_type, entry_id, relevance_score, predicted_label, explanation, score_source, model_version
                  FROM entry_scores
                 WHERE (entry_type, entry_id) IN (' . $placeholders . ')';
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($flat);
            $rows = $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[$row['entry_type'] . ':' . $row['entry_id']] = $row;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Lightweight readers for the in-app labelling queue
    // (`MagnituLabelUiController::gatherEntries`).
    //
    // The main `list*Since` methods above ship the **full** row to satisfy
    // the Magnitu v3 sync contract: `feed_items.content`, both email body
    // columns, full `calendar_events.content`. The label UI only renders
    // title + description trimmed to 220 chars + source meta, so eagerly
    // loading several hundred KB per row for 4×280 rows can OOM the page
    // on a 128 MB shared host (symptom: blank label page).
    //
    // These `listFor Labeling*` helpers return the same row shape the
    // `MagnituController::shape*()` helpers consume — just with `content`
    // omitted / blanked and email bodies SUBSTRINGed in SQL. They also
    // accept an `$offset` so the controller can paginate past the newest
    // 280-per-family window.
    // ------------------------------------------------------------------

    /**
     * Lightweight feed_items for the labelling queue. Skips `fi.content`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listFeedItemsForLabeling(int $limit, int $offset = 0): array
    {
        $limit  = $this->clampLimit($limit);
        $offset = max(0, $offset);
        $sql = 'SELECT fi.id, fi.title, fi.description, fi.link, fi.author,
                       fi.published_date,
                       f.title       AS feed_title,
                       f.category    AS feed_category,
                       f.source_type AS source_type,
                       (SELECT MIN(scx.id) FROM ' . entryTable('scraper_configs') . ' scx WHERE scx.url = f.url AND IFNULL(scx.disabled, 0) = 0) AS scraper_config_id
                  FROM ' . entryTable('feed_items') . ' fi
                  JOIN ' . entryTable('feeds') . ' f ON fi.feed_id = f.id
                 WHERE f.disabled = 0
                   AND fi.hidden = 0
                 ORDER BY fi.published_date DESC
                 LIMIT ' . $limit . ' OFFSET ' . $offset;

        return $this->selectOrEmpty($sql, []);
    }

    /**
     * Lightweight emails for the labelling queue. Truncates body columns to
     * `$bodyChars` (default 800) in SQL so the entire 100 KB newsletter
     * never reaches PHP memory.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listEmailsForLabeling(int $limit, int $offset = 0, int $bodyChars = 800): array
    {
        $limit     = $this->clampLimit($limit);
        $offset    = max(0, $offset);
        $bodyChars = max(64, $bodyChars);
        $table     = entryTable(getEmailTableName());

        try {
            $stmt = $this->pdo->prepare(
                'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = ' . entryDbSchemaExpr() . '
                    AND TABLE_NAME = ?'
            );
            $stmt->execute([getEmailTableName()]);
            $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return [];
        }
        if ($cols === []) {
            return [];
        }

        $dateCol = $this->pickColumn($cols, ['date_utc', 'date_received', 'created_at', 'date_sent']);
        if ($dateCol === null) {
            return [];
        }
        $textBodyCol  = $this->pickColumn($cols, ['text_body', 'body_text']);
        $htmlBodyCol  = $this->pickColumn($cols, ['html_body', 'body_html']);
        $fromEmailCol = $this->pickColumn($cols, ['from_email', 'from_addr']);
        $fromNameExpr = in_array('from_name', $cols, true) ? 'e.from_name' : "''";

        if ($textBodyCol === null || $htmlBodyCol === null || $fromEmailCol === null) {
            return [];
        }

        $exportableClause = in_array('parent_email_id', $cols, true)
            ? EmailDigestExportPolicy::sqlExportableEmail('e')
            : '1=1';
        if (in_array('hidden', $cols, true)) {
            $whereClause = 'WHERE e.hidden = 0 AND ' . $exportableClause;
        } else {
            $whereClause = 'WHERE ' . $exportableClause;
        }
        $sql = "SELECT e.id,
                       e.subject,
                       e.`{$fromEmailCol}`                          AS from_email,
                       {$fromNameExpr}                              AS from_name,
                       SUBSTRING(e.`{$textBodyCol}`, 1, {$bodyChars}) AS text_body,
                       SUBSTRING(e.`{$htmlBodyCol}`, 1, {$bodyChars}) AS html_body,
                       e.`{$dateCol}`                               AS entry_date,
                       COALESCE(st.tag, 'unclassified')             AS sender_tag
                  FROM {$table} e
             LEFT JOIN " . entryTable('sender_tags') . " st
                    ON st.from_email = e.`{$fromEmailCol}`
                   AND st.removed_at IS NULL
                   AND st.disabled = 0
                 {$whereClause}
                 ORDER BY e.`{$dateCol}` DESC
                 LIMIT {$limit} OFFSET {$offset}";

        return $this->selectOrEmpty($sql, []);
    }

    /**
     * Lightweight lex_items for the labelling queue. Skips full `content` corpus;
     * includes a bounded excerpt for {@see LexCardPreview} on the Label page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listLexItemsForLabeling(int $limit, int $offset = 0): array
    {
        $limit  = $this->clampLimit($limit);
        $offset = max(0, $offset);
        $cols = 'id, celex, title, description, document_date, document_type, eurlex_url, source,'
            . ' SUBSTRING(content, 1, ' . LexCardPreview::TIMELINE_EXCERPT_CHARS . ') AS content_excerpt';
        $sql = 'SELECT ' . $cols . ' FROM ' . entryTable('lex_items') . '
                 ORDER BY document_date DESC
                 LIMIT ' . $limit . ' OFFSET ' . $offset;

        return $this->selectOrEmpty($sql, []);
    }

    /**
     * Lightweight calendar_events for the labelling queue. Skips `content`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listCalendarEventsForLabeling(int $limit, int $offset = 0): array
    {
        $limit  = $this->clampLimit($limit);
        $offset = max(0, $offset);
        $table  = entryTable('calendar_events');
        $sql    = "SELECT id, source, title, description, event_date, event_end_date,
                          event_type, status, council, url
                     FROM {$table}
                    ORDER BY (event_date IS NULL), event_date DESC, id DESC
                    LIMIT {$limit} OFFSET {$offset}";

        return $this->selectOrEmpty($sql, []);
    }

    /**
     * Per-type entry counts used by `?action=magnitu_status`.
     *
     * @return array{feed_items:int, emails:int, lex_items:int, calendar_events:int}
     */
    public function getEntryCounts(): array
    {
        return [
            'feed_items' => $this->countOrZero(
                'SELECT COUNT(*) FROM ' . entryTable('feed_items') . ' fi
                 JOIN ' . entryTable('feeds') . ' f ON fi.feed_id = f.id
                 WHERE f.disabled = 0 AND fi.hidden = 0',
            ),
            'emails' => $this->countExportableEmails(),
            'lex_items' => $this->countOrZero(
                'SELECT COUNT(*) FROM ' . entryTable('lex_items'),
            ),
            'calendar_events' => $this->countOrZero(
                'SELECT COUNT(*) FROM ' . entryTable('calendar_events'),
            ),
        ];
    }

    private function countExportableEmails(): int
    {
        $table = entryTable(getEmailTableName());
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = ' . entryDbSchemaExpr() . '
                    AND TABLE_NAME = ?',
            );
            $stmt->execute([getEmailTableName()]);
            $cols = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (PDOException) {
            return 0;
        }
        if ($cols === []) {
            return 0;
        }
        $hiddenClause = in_array('hidden', $cols, true) ? 'e.hidden = 0' : '1=1';
        $exportableClause = in_array('parent_email_id', $cols, true)
            ? ' AND ' . EmailDigestExportPolicy::sqlExportableEmail('e')
            : '';

        return $this->countOrZero(
            "SELECT COUNT(*) FROM {$table} e WHERE {$hiddenClause}{$exportableClause}",
        );
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    private function clampLimit(int $limit, int $max = self::MAX_LIMIT): int
    {
        if ($limit < 1) {
            return 1;
        }

        return min($limit, max(1, $max));
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function normalizePositiveIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $n = (int)$id;
            if ($n > 0) {
                $out[$n] = $n;
            }
        }

        return array_values($out);
    }

    /**
     * @param array<int, string> $cols
     * @param array<int, string> $preference
     */
    private function pickColumn(array $cols, array $preference): ?string
    {
        foreach ($preference as $p) {
            if (in_array($p, $cols, true)) {
                return $p;
            }
        }
        return null;
    }

    /**
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function selectOrEmpty(string $sql, array $params = []): array
    {
        try {
            if ($params === []) {
                $rows = $this->pdo->query($sql)->fetchAll();
            } else {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll();
            }
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log('MagnituExportRepository query failed: ' . $e->getMessage());
            return [];
        }
    }

    private function countOrZero(string $sql): int
    {
        try {
            $v = $this->pdo->query($sql)->fetchColumn();
            return (int)$v;
        } catch (PDOException $e) {
            return 0;
        }
    }
}
