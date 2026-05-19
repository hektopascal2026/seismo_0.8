<?php

declare(strict_types=1);

namespace Seismo\Repository;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;

/**
 * Leg family table — bounded reads, transactional upserts, satellite-safe entryTable().
 * Leg page lists rows with **`event_date` descending** (newest first; tie-break `id` DESC).
 */
final class CalendarEventRepository
{
    public const MAX_LIMIT = 200;

    public const LEG_PAGE_SOURCES = ['parliament_ch'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Events ordered for the Leg page (newest calendar date first; `NULL` dates last).
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
            // DB session is UTC but Leg labels use Europe/Zurich — compute cutoff in
            // PHP (avoids CONVERT_TZ portability issues). **30‑day rolling window**
            // matches 0.4 `controllers/calendar.php`: `event_date >= DATE_SUB(curdate(),
            // INTERVAL 30 DAY)`. “Show past” removes this floor.
            $where .= ' AND (event_date IS NULL OR event_date >= ?)';
            $bind[] = self::zurichLegUpcomingCutoff();
        }
        if ($eventType !== null && $eventType !== '') {
            $where .= ' AND event_type = ?';
            $bind[] = $eventType;
        }

        $sql = "SELECT * FROM {$table}
            WHERE {$where}
            ORDER BY (event_date IS NULL), event_date DESC, id DESC
            LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset;

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
     * upcoming-only filter". Capped at no LIMIT because counts are cheap and
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
            $where .= ' AND (event_date IS NULL OR event_date >= ?)';
            $bind[] = self::zurichLegUpcomingCutoff();
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

    /** Same as 0.4 non–show-past filter: thirty calendar days back in Zurich. */
    private const LEG_UPCOMING_LOOKBACK_DAYS = 30;

    /**
     * Earliest `event_date` included in Leg’s default view (without “Show past”).
     */
    private static function zurichLegUpcomingCutoff(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Europe/Zurich')))
            ->modify('-' . self::LEG_UPCOMING_LOOKBACK_DAYS . ' days')
            ->format('Y-m-d');
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
