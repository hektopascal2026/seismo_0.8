<?php

declare(strict_types=1);

namespace Seismo\Repository;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Seismo\Util\ParlChLegSignal;

/**
 * Leg family table — bounded reads, transactional upserts, satellite-safe entryTable().
 * Leg page default view: **New** ingests and fresh **Antwort BR** (`metadata.leg_feed_at` window).
 */
final class CalendarEventRepository
{
    public const MAX_LIMIT = 200;

    public const LEG_PAGE_SOURCES = ['parliament_ch'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Events for the Leg page: default = rows with `leg_feed_at` (or `created_at`) in the ingest window.
     * A new Bundesrat Stellungnahme sets `leg_signal` to `antwort_br` and refreshes `leg_feed_at`.
     *
     * @param list<string> $sources
     * @return list<array<string, mixed>>
     */
    public function listBySources(array $sources, int $limit, int $offset, bool $includePast = false, ?string $eventType = null): array
    {
        $sources = $this->filterSources($sources);
        if ($sources === []) {
            return [];
        }

        $limit = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);

        $placeholders = implode(',', array_fill(0, count($sources), '?'));
        $table = entryTable('calendar_events');

        $where = 'source IN (' . $placeholders . ')';
        $bind = $sources;
        if (!$includePast) {
            $where .= ' AND ' . self::legFeedVisibilitySql();
            $bind[] = self::zurichLegAntwortBrCutoffUtc();
            $bind[] = self::zurichLegNewIngestCutoffUtc();
        }
        if ($eventType !== null && $eventType !== '') {
            $where .= ' AND event_type = ?';
            $bind[] = $eventType;
        }

        $sql = "SELECT * FROM {$table}
            WHERE {$where}
            ORDER BY " . self::legFeedAtSqlExpression() . ' DESC, id DESC
            LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bind);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * Row count for the same filter shape as {@see listBySources()}, used by
     * the Leg view to tell "DB is empty" from "everything is hidden by the
     * new-ingest window". Capped at no LIMIT because counts are cheap and
     * the caller only reads an integer.
     *
     * @param list<string> $sources
     */
    public function countBySources(array $sources, bool $includePast = false, ?string $eventType = null): int
    {
        $sources = $this->filterSources($sources);
        if ($sources === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($sources), '?'));
        $table = entryTable('calendar_events');

        $where = 'source IN (' . $placeholders . ')';
        $bind = $sources;
        if (!$includePast) {
            $where .= ' AND ' . self::legFeedVisibilitySql();
            $bind[] = self::zurichLegAntwortBrCutoffUtc();
            $bind[] = self::zurichLegNewIngestCutoffUtc();
        }
        if ($eventType !== null && $eventType !== '') {
            $where .= ' AND event_type = ?';
            $bind[] = $eventType;
        }

        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bind);

            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return 0;
            }
            throw $e;
        }
    }

    /**
     * Distinct event_type values present for the requested sources (for filter pills).
     *
     * @param list<string> $sources
     * @return list<string>
     */
    public function distinctEventTypes(array $sources): array
    {
        $sources = $this->filterSources($sources);
        if ($sources === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($sources), '?'));
        $table = entryTable('calendar_events');
        $sql = "SELECT DISTINCT event_type FROM {$table}
            WHERE source IN ({$placeholders}) AND event_type IS NOT NULL AND event_type <> ''
            ORDER BY event_type ASC";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($sources);
            $out = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $out[] = (string)$row['event_type'];
            }

            return $out;
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * Max fetched_at per source (for "Refreshed" line).
     *
     * @param list<string> $sources
     * @return array<string, ?DateTimeImmutable>
     */
    public function getLastFetchedBySources(array $sources): array
    {
        $sources = $this->filterSources($sources);
        $out = array_fill_keys($sources, null);
        if ($sources === []) {
            return $out;
        }

        $table = entryTable('calendar_events');
        $placeholders = implode(',', array_fill(0, count($sources), '?'));
        $sql = "SELECT source, MAX(fetched_at) AS m FROM {$table} WHERE source IN ({$placeholders}) GROUP BY source";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($sources);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $src = (string)($row['source'] ?? '');
                $raw = $row['m'] ?? null;
                if ($src !== '' && $raw !== null && $raw !== '') {
                    $out[$src] = new DateTimeImmutable((string)$raw, new DateTimeZone('UTC'));
                }
            }
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return $out;
            }
            throw $e;
        }

        return $out;
    }

    /**
     * Insert/update parliament events. All-or-nothing transaction.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public function upsertBatch(array $rows): int
    {
        if (isSatellite()) {
            throw new \RuntimeException('CalendarEventRepository::upsertBatch must not run on a satellite; entry writes use the mothership pipeline.');
        }

        if ($rows === []) {
            return 0;
        }

        $table = entryTable('calendar_events');
        $existingRows = $this->fetchExistingRowsByKeys($rows);

        $sql = 'INSERT INTO ' . $table . ' (source, external_id, title, description, content, event_date, event_end_date, event_type, status, council, url, metadata)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                description = VALUES(description),
                content = VALUES(content),
                event_date = VALUES(event_date),
                event_end_date = VALUES(event_end_date),
                event_type = VALUES(event_type),
                status = VALUES(status),
                council = VALUES(council),
                url = VALUES(url),
                metadata = VALUES(metadata),
                fetched_at = CURRENT_TIMESTAMP';

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare($sql);
            foreach ($rows as $row) {
                $lookupKey = (string)($row['source'] ?? '') . "\0" . (string)($row['external_id'] ?? '');
                $prior = $existingRows[$lookupKey] ?? null;
                $isInsert = $prior === null;
                $priorMeta = is_array($prior) ? ($prior['metadata'] ?? null) : null;
                $priorContent = is_array($prior) ? (string)($prior['content'] ?? '') : null;
                $priorCreatedAt = is_array($prior) ? (string)($prior['created_at'] ?? '') : null;
                if ((string)($row['source'] ?? '') === 'parliament_ch') {
                    $row = ParlChLegSignal::applyToBusinessRow(
                        $row,
                        is_array($priorMeta) ? $priorMeta : null,
                        $isInsert,
                        $priorContent,
                        $priorCreatedAt !== '' ? $priorCreatedAt : null,
                    );
                }

                $metadata = $row['metadata'] ?? null;
                if (is_array($metadata)) {
                    $metadata = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                $stmt->execute([
                    (string)($row['source'] ?? ''),
                    (string)($row['external_id'] ?? ''),
                    (string)($row['title'] ?? ''),
                    (string)($row['description'] ?? ''),
                    (string)($row['content'] ?? ''),
                    $this->normalizeDate($row['event_date'] ?? null),
                    $this->normalizeDate($row['event_end_date'] ?? null),
                    $row['event_type'] !== null && $row['event_type'] !== '' ? (string)$row['event_type'] : null,
                    (string)($row['status'] ?? 'scheduled'),
                    $row['council'] !== null && $row['council'] !== '' ? (string)$row['council'] : null,
                    (string)($row['url'] ?? ''),
                    is_string($metadata) ? $metadata : null,
                ]);
            }
            $this->pdo->commit();

            return count($rows);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Default retention policy: Leg is **never** auto-pruned (see
     * `core-plugin-architecture.mdc`). Slice 5a wires `prune()` up so
     * the admin can opt into a cutoff via Retention settings if they
     * ever want to; `RetentionService` will otherwise skip this family.
     *
     * @param list<string> $keepPredicates Tokens from
     *        {@see \Seismo\Service\RetentionService}.
     */
    public function prune(DateTimeImmutable $olderThan, array $keepPredicates): int
    {
        if (isSatellite()) {
            throw new \RuntimeException('CalendarEventRepository::prune must not run on a satellite; calendar_events live in the mothership DB.');
        }

        $cutoff = $olderThan->format('Y-m-d H:i:s');
        $where  = $this->buildPruneWhere($keepPredicates);

        try {
            $stmt = $this->pdo->prepare(
                // Multi-table DELETE form: see EmailRepository::prune.
                'DELETE t FROM ' . entryTable('calendar_events') . ' t WHERE ' . $where
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
     * Dry-run counterpart of `prune()`. Same WHERE clause via
     * `buildPruneWhere()` so preview and real run stay in lockstep.
     *
     * @param list<string> $keepPredicates
     */
    public function dryRunPrune(DateTimeImmutable $olderThan, array $keepPredicates): int
    {
        $cutoff = $olderThan->format('Y-m-d H:i:s');
        $where  = $this->buildPruneWhere($keepPredicates);

        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ' . entryTable('calendar_events') . ' t WHERE ' . $where
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
        $keeps = \Seismo\Service\RetentionPredicates::forEntryType('calendar_event', $keepPredicates);
        $where = 't.fetched_at < ?';
        if ($keeps !== '') {
            $where .= ' AND NOT (' . $keeps . ')';
        }
        return $where;
    }

    /**
     * @param list<string> $sources
     * @return list<string>
     */
    private function filterSources(array $sources): array
    {
        $allowed = array_flip(self::LEG_PAGE_SOURCES);
        $out = [];
        foreach ($sources as $s) {
            if (!is_string($s)) {
                continue;
            }
            if (isset($allowed[$s])) {
                $out[] = $s;
            }
        }

        return array_values(array_unique($out));
    }

    /** Default Leg list: newly filed Geschäfte within this many Zurich days. */
    private const LEG_NEW_INGEST_LOOKBACK_DAYS = 30;

    /** Sort/filter timestamp: `metadata.leg_feed_at` when set, else row `created_at`. */
    private static function legFeedAtSqlExpression(): string
    {
        return 'COALESCE(STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(metadata, \'$.leg_feed_at\')), \'%Y-%m-%d %H:%i:%s\'), created_at)';
    }

    /**
     * WHERE clause for default Leg / dashboard calendar merge (bind params via
     * {@see legFeedVisibilityBindParams()}).
     */
    public function legFeedVisibilityWhereClause(): string
    {
        return self::legFeedVisibilitySql();
    }

    public function legFeedAtOrderExpression(): string
    {
        return self::legFeedAtSqlExpression() . ' DESC, id DESC';
    }

    /**
     * @return list<string>
     */
    public function legFeedVisibilityBindParams(): array
    {
        return [
            self::zurichLegAntwortBrCutoffUtc(),
            self::zurichLegNewIngestCutoffUtc(),
        ];
    }

    /**
     * PHP mirror of {@see legFeedVisibilityWhereClause()} for score-driven paths
     * (Highlights, Favourites) that hydrate calendar rows by id.
     *
     * @param array<string, mixed> $row calendar_events row
     */
    public function rowVisibleInDefaultLegFeed(array $row): bool
    {
        $meta = $this->decodeMetadataColumn($row['metadata'] ?? null) ?? [];
        $signal = (string)($meta['leg_signal'] ?? '');
        $status = (string)($row['status'] ?? 'scheduled');

        $feedAt = trim((string)($meta['leg_feed_at'] ?? ''));
        if ($feedAt === '') {
            $feedAt = trim((string)($row['created_at'] ?? ''));
        }
        if ($feedAt === '') {
            return false;
        }

        $feedTs = strtotime($feedAt);
        if ($feedTs === false) {
            return false;
        }

        if ($signal === ParlChLegSignal::SIGNAL_ANTWORT_BR) {
            $cutoff = strtotime(self::zurichLegAntwortBrCutoffUtc());

            return $cutoff !== false && $feedTs >= $cutoff;
        }

        if ($signal === ParlChLegSignal::SIGNAL_NEW && $status !== 'completed') {
            $cutoff = strtotime(self::zurichLegNewIngestCutoffUtc());

            return $cutoff !== false && $feedTs >= $cutoff;
        }

        return false;
    }

    /**
     * Antwort BR uses a shorter window ({@see ParlChLegSignal::ANTWORT_BR_FEED_LOOKBACK_DAYS})
     * keyed off Stellungnahme date, not catalogue refresh time.
     */
    private static function legFeedVisibilitySql(): string
    {
        $feedAt = self::legFeedAtSqlExpression();
        $signal = "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.leg_signal'))";

        // Only explicit signals — legacy rows without `leg_signal` stay under “Show all”.
        return '(' . $signal . " = '" . ParlChLegSignal::SIGNAL_ANTWORT_BR . "' AND {$feedAt} >= ?)"
            . " OR (" . $signal . " = '" . ParlChLegSignal::SIGNAL_NEW . "' AND status <> 'completed' AND {$feedAt} >= ?)";
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array{metadata: ?array, content: string, created_at: string}> key `source\0external_id`
     */
    private function fetchExistingRowsByKeys(array $rows): array
    {
        $pairs = [];
        foreach ($rows as $row) {
            $source = (string)($row['source'] ?? '');
            $ext = (string)($row['external_id'] ?? '');
            if ($source === '' || $ext === '') {
                continue;
            }
            $pairs[$source . "\0" . $ext] = [$source, $ext];
        }
        if ($pairs === []) {
            return [];
        }

        $table = entryTable('calendar_events');
        $out = [];
        foreach (array_chunk($pairs, 50, true) as $chunk) {
            $conditions = [];
            $bind = [];
            foreach ($chunk as [$source, $ext]) {
                $conditions[] = '(source = ? AND external_id = ?)';
                $bind[] = $source;
                $bind[] = $ext;
            }

            $sql = 'SELECT source, external_id, metadata, content, created_at FROM ' . $table
                . ' WHERE ' . implode(' OR ', $conditions);

            try {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($bind);
            } catch (PDOException $e) {
                if (PdoMysqlDiagnostics::isMissingTable($e)) {
                    return [];
                }
                throw $e;
            }

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $key = (string)$row['source'] . "\0" . (string)$row['external_id'];
                $out[$key] = [
                    'metadata'   => $this->decodeMetadataColumn($row['metadata'] ?? null),
                    'content'    => (string)($row['content'] ?? ''),
                    'created_at' => (string)($row['created_at'] ?? ''),
                ];
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeMetadataColumn(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw)) {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Earliest leg-feed instant (UTC) for **New** rows in Leg’s default view.
     */
    private static function zurichLegNewIngestCutoffUtc(): string
    {
        return self::zurichLegCutoffUtc(self::LEG_NEW_INGEST_LOOKBACK_DAYS);
    }

    private static function zurichLegAntwortBrCutoffUtc(): string
    {
        return self::zurichLegCutoffUtc(ParlChLegSignal::ANTWORT_BR_FEED_LOOKBACK_DAYS);
    }

    private static function zurichLegCutoffUtc(int $days): string
    {
        $zurich = new DateTimeZone('Europe/Zurich');
        $cutoff = (new DateTimeImmutable('now', $zurich))
            ->modify('-' . $days . ' days')
            ->setTime(0, 0, 0);

        return $cutoff->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function normalizeDate(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = (string)$v;
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) {
            return $m[1];
        }
        $ts = strtotime($s);
        if ($ts === false) {
            return null;
        }

        return gmdate('Y-m-d', $ts);
    }
}
