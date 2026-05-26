<?php

declare(strict_types=1);

namespace Seismo\Repository;

use DateTimeImmutable;
use PDO;
use PDOException;
use Seismo\Core\Lex\LexCardPreview;
use Seismo\Core\Lex\LexPlainText;
use Seismo\Core\PlainTextNormalizer;

/**
 * Lex family table — bounded reads, transactional upserts, satellite-safe entryTable().
 */
final class LexItemRepository
{
    /** Backfill tombstone — non-empty so the row leaves the missing-content queue. */
    public const CONTENT_FETCH_UNAVAILABLE = "\n\n[seismo:content_unavailable]\n\n";

    /**
     * Fedlex OC rows with longer Akoma corpus are treated as already backfilled.
     * Shorter bodies are usually description-promote synopses from legacy `--ch-promote`.
     */
    public const FEDLEX_OC_SYNOPSIS_MAX_BYTES = 2800;

    public const MAX_LIMIT = 200;

    /** Lex Items view filter pills (EU/CH/DE/FR) — not JUS; Parl MM is a `feed_item`. */
    public const LEX_PAGE_SOURCES = ['eu', 'ch', 'de', 'fr'];

    /** All Lex plugin source keys (legislation + Jus) for Sources view status. */
    public const LEX_TRACKED_SOURCES = ['eu', 'ch', 'de', 'fr', 'ch_bger', 'ch_bge', 'ch_bvger'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param list<string> $sources
     * @return list<array<string, mixed>>
     */
    public function listBySources(array $sources, int $limit, int $offset): array
    {
        $sources = $this->filterLexPageSources($sources);
        if ($sources === []) {
            return [];
        }

        $limit = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);

        $table = entryTable('lex_items');
        $placeholders = implode(',', array_fill(0, count($sources), '?'));
        $cols = 'id, celex, title, description, document_date, document_type, eurlex_url, work_uri, source,'
            . ' fetched_at, created_at, SUBSTRING(content, 1, ' . LexCardPreview::TIMELINE_EXCERPT_CHARS . ') AS content_excerpt';
        $sql = "SELECT {$cols} FROM {$table} WHERE source IN ({$placeholders})"
            . ' ORDER BY document_date DESC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($sources);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * Max fetched_at per source (for "Refreshed" line). Keys only for requested sources.
     *
     * @param list<string> $sources
     * @return array<string, ?DateTimeImmutable>
     */
    public function getLastFetchedBySources(array $sources): array
    {
        $sources = $this->filterLexTrackedSources($sources);
        $out = array_fill_keys($sources, null);
        if ($sources === []) {
            return $out;
        }

        $table = entryTable('lex_items');
        $placeholders = implode(',', array_fill(0, count($sources), '?'));
        $sql = "SELECT source, MAX(fetched_at) AS m FROM {$table} WHERE source IN ({$placeholders}) GROUP BY source";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($sources);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $src = (string)($row['source'] ?? '');
                $raw = $row['m'] ?? null;
                if ($src !== '' && $raw !== null && $raw !== '') {
                    $out[$src] = new DateTimeImmutable((string)$raw, new \DateTimeZone('UTC'));
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
     * Per-source counts for rows still missing `content` (diagnostics for backfill CLI).
     *
     * @return array<int, array{source: string, missing: int, no_work_uri: int, has_description: int}>
     */
    public function contentBackfillStatsBySource(): array
    {
        if (isSatellite()) {
            throw new \RuntimeException('LexItemRepository::contentBackfillStatsBySource must not run on a satellite.');
        }

        $table = entryTable('lex_items');
        $sql   = "SELECT source,
                         COUNT(*) AS missing,
                         SUM(CASE WHEN work_uri IS NULL OR TRIM(work_uri) = '' THEN 1 ELSE 0 END) AS no_work_uri,
                         SUM(CASE WHEN description IS NOT NULL AND TRIM(description) <> '' THEN 1 ELSE 0 END) AS has_description
                    FROM {$table}
                   WHERE content IS NULL OR TRIM(content) = ''
                   GROUP BY source
                   ORDER BY missing DESC";

        try {
            $stmt = $this->pdo->query($sql);

            return $stmt === false ? [] : ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * Fedlex official compilation acts (`eli/oc/…`) queued for Akoma XML corpus backfill.
     *
     * Includes empty `content` and legacy rows where `promoteDescriptionToContent` copied
     * the card synopsis into `content` (short body, usually a prefix of `description`).
     *
     * @return list<array<string, mixed>>
     */
    public function listFedlexOcForCorpusBackfill(int $limit): array
    {
        if (isSatellite()) {
            throw new \RuntimeException('LexItemRepository::listFedlexOcForCorpusBackfill must not run on a satellite.');
        }

        $limit = max(1, min($limit, self::MAX_LIMIT));
        $table = entryTable('lex_items');
        $max   = self::FEDLEX_OC_SYNOPSIS_MAX_BYTES;
        $sql   = "SELECT id, celex, work_uri, eurlex_url, source, title, description, content
                    FROM {$table}
                   WHERE source = 'ch'
                     AND celex LIKE 'eli/oc/%'
                     AND (
                           content IS NULL OR TRIM(content) = ''
                           OR (
                               content NOT LIKE '%[seismo:content_unavailable]%'
                               AND CHAR_LENGTH(content) < {$max}
                               AND (
                                   description IS NULL OR TRIM(description) = ''
                                   OR content = description
                                   OR content LIKE CONCAT(description, '%')
                               )
                           )
                     )
                   ORDER BY document_date DESC, id DESC
                   LIMIT " . (int)$limit;

        try {
            $stmt = $this->pdo->query($sql);

            return $stmt === false ? [] : ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * Counts for Fedlex corpus backfill diagnostics (`php bin/lex-backfill-content.php --stats`).
     *
     * @return array{
     *   total_ch: int,
     *   oc_acts: int,
     *   consultations: int,
     *   oc_empty_content: int,
     *   oc_synopsis_only: int,
     *   oc_has_corpus: int,
     *   oc_unavailable: int,
     *   oc_needs_backfill: int
     * }
     */
    public function fedlexCorpusBreakdown(): array
    {
        if (isSatellite()) {
            throw new \RuntimeException('LexItemRepository::fedlexCorpusBreakdown must not run on a satellite.');
        }

        $table = entryTable('lex_items');
        $max   = self::FEDLEX_OC_SYNOPSIS_MAX_BYTES;
        $sql   = "SELECT
                    COUNT(*) AS total_ch,
                    SUM(celex LIKE 'eli/oc/%') AS oc_acts,
                    SUM(celex NOT LIKE 'eli/oc/%') AS consultations,
                    SUM(celex LIKE 'eli/oc/%' AND (content IS NULL OR TRIM(content) = '')) AS oc_empty_content,
                    SUM(
                        celex LIKE 'eli/oc/%'
                        AND content IS NOT NULL AND TRIM(content) <> ''
                        AND content NOT LIKE '%[seismo:content_unavailable]%'
                        AND CHAR_LENGTH(content) < {$max}
                        AND description IS NOT NULL AND TRIM(description) <> ''
                        AND (content = description OR content LIKE CONCAT(description, '%'))
                    ) AS oc_synopsis_only,
                    SUM(
                        celex LIKE 'eli/oc/%'
                        AND content IS NOT NULL
                        AND CHAR_LENGTH(content) >= {$max}
                        AND content NOT LIKE '%[seismo:content_unavailable]%'
                    ) AS oc_has_corpus,
                    SUM(
                        celex LIKE 'eli/oc/%'
                        AND content LIKE '%[seismo:content_unavailable]%'
                    ) AS oc_unavailable
                  FROM {$table}
                 WHERE source = 'ch'";

        try {
            $stmt = $this->pdo->query($sql);
            $row  = $stmt === false ? [] : ($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return [
                    'total_ch' => 0, 'oc_acts' => 0, 'consultations' => 0,
                    'oc_empty_content' => 0, 'oc_synopsis_only' => 0, 'oc_has_corpus' => 0,
                    'oc_unavailable' => 0, 'oc_needs_backfill' => 0,
                ];
            }
            throw $e;
        }

        $empty   = (int)($row['oc_empty_content'] ?? 0);
        $synopsis = (int)($row['oc_synopsis_only'] ?? 0);

        return [
            'total_ch'          => (int)($row['total_ch'] ?? 0),
            'oc_acts'           => (int)($row['oc_acts'] ?? 0),
            'consultations'     => (int)($row['consultations'] ?? 0),
            'oc_empty_content'  => $empty,
            'oc_synopsis_only'  => $synopsis,
            'oc_has_corpus'     => (int)($row['oc_has_corpus'] ?? 0),
            'oc_unavailable'    => (int)($row['oc_unavailable'] ?? 0),
            'oc_needs_backfill' => $empty + $synopsis,
        ];
    }

    /**
     * Rows missing a corpus body — used by {@see LexContentBackfillService}.
     *
     * @param list<string> $sources
     * @return list<array<string, mixed>>
     */
    public function listMissingContentBySources(array $sources, int $limit): array
    {
        if (isSatellite()) {
            throw new \RuntimeException('LexItemRepository::listMissingContentBySources must not run on a satellite.');
        }

        $sources = array_values(array_unique(array_filter(
            array_map(static fn ($s) => is_string($s) ? trim($s) : '', $sources),
            static fn (string $s): bool => $s !== '',
        )));
        if ($sources === []) {
            return [];
        }

        $limit = max(1, min($limit, self::MAX_LIMIT));
        $table = entryTable('lex_items');
        $ph    = implode(',', array_fill(0, count($sources), '?'));
        $sql   = "SELECT id, celex, work_uri, eurlex_url, source, title, description
                    FROM {$table}
                   WHERE source IN ({$ph})
                     AND (content IS NULL OR TRIM(content) = '')
                   ORDER BY document_date DESC, id DESC
                   LIMIT " . (int)$limit;

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($sources);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return [];
            }
            throw $e;
        }
    }

    public static function isContentUnavailable(?string $content): bool
    {
        return $content !== null && str_contains($content, '[seismo:content_unavailable]');
    }

    /**
     * Stop retrying corpus fetch for a row (404, blocked PDF, missing URL, etc.).
     */
    public function markContentUnavailable(int $id): bool
    {
        if (isSatellite() || $id <= 0) {
            return false;
        }

        $table = entryTable('lex_items');
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE ' . $table . ' SET content = ?, fetched_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND (content IS NULL OR TRIM(content) = \'\')'
            );
            $stmt->execute([self::CONTENT_FETCH_UNAVAILABLE, $id]);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Write corpus text for one row. Updates `description` only when a synopsis is supplied.
     */
    public function updateCorpus(int $id, string $content, ?string $description = null): bool
    {
        if (isSatellite()) {
            throw new \RuntimeException('LexItemRepository::updateCorpus must not run on a satellite.');
        }
        if ($id <= 0 || trim($content) === '' || self::isContentUnavailable($content)) {
            return false;
        }
        $content = PlainTextNormalizer::forIngest($content);
        if ($description !== null && $description !== '') {
            $description = PlainTextNormalizer::forIngest($description);
        }

        $table = entryTable('lex_items');
        if ($description !== null && trim($description) !== '') {
            $sql = 'UPDATE ' . $table . ' SET content = ?, description = ?, fetched_at = CURRENT_TIMESTAMP WHERE id = ?';
            $params = [trim($content), trim($description), $id];
        } else {
            $sql = 'UPDATE ' . $table . ' SET content = ?, fetched_at = CURRENT_TIMESTAMP WHERE id = ?';
            $params = [trim($content), $id];
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * One-shot helper: copy existing RSS synopsis bodies into `content` for DE rows.
     */
    public function promoteDescriptionToContent(string $source, int $limit): int
    {
        if (isSatellite()) {
            throw new \RuntimeException('LexItemRepository::promoteDescriptionToContent must not run on a satellite.');
        }

        $limit  = max(1, min($limit, 5000));
        $table  = entryTable('lex_items');
        $sql    = 'UPDATE ' . $table . '
                      SET content = description,
                          description = LEFT(description, ' . LexPlainText::DEFAULT_SYNOPSIS_CHARS . '),
                          fetched_at = CURRENT_TIMESTAMP
                    WHERE source = ?
                      AND description IS NOT NULL
                      AND TRIM(description) <> \'\'
                      AND (content IS NULL OR TRIM(content) = \'\')
                    ORDER BY document_date DESC, id DESC
                    LIMIT ' . (int)$limit;

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$source]);

            return $stmt->rowCount();
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return 0;
            }
            throw $e;
        }
    }

    /**
     * Insert/update Swiss Fedlex rows. Per-row savepoints so one bad row cannot abort the batch.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public function upsertBatch(array $rows): int
    {
        if (isSatellite()) {
            throw new \RuntimeException('LexItemRepository::upsertBatch must not run on a satellite; entry writes use the mothership pipeline.');
        }

        if ($rows === []) {
            return 0;
        }

        $table = entryTable('lex_items');
        $sql = 'INSERT INTO ' . $table . ' (
                celex, title, description, content, document_date, document_type,
                eurlex_url, work_uri, source
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                description = COALESCE(NULLIF(TRIM(VALUES(description)), \'\'), description),
                content = COALESCE(VALUES(content), content),
                document_date = VALUES(document_date),
                document_type = VALUES(document_type),
                eurlex_url = VALUES(eurlex_url),
                work_uri = VALUES(work_uri),
                source = VALUES(source),
                fetched_at = CURRENT_TIMESTAMP';

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare($sql);
            $n    = 0;
            foreach ($rows as $i => $row) {
                $savepoint = 'lex_item_' . $i;
                $this->pdo->exec('SAVEPOINT ' . $savepoint);
                try {
                    $desc = $row['description'] ?? null;
                    if ($desc !== null && $desc !== '') {
                        $desc = PlainTextNormalizer::forIngest((string)$desc);
                        if ($desc === '') {
                            $desc = null;
                        }
                    } else {
                        $desc = null;
                    }
                    $content = $row['content'] ?? null;
                    if ($content !== null && $content !== '') {
                        $content = PlainTextNormalizer::forIngest((string)$content);
                        if ($content === '') {
                            $content = null;
                        }
                    } else {
                        $content = null;
                    }
                    $stmt->execute([
                        (string)$row['celex'],
                        (string)($row['title'] ?? ''),
                        $desc,
                        $content,
                        $this->normalizeDate($row['document_date'] ?? null),
                        (string)($row['document_type'] ?? ''),
                        (string)($row['eurlex_url'] ?? ''),
                        (string)($row['work_uri'] ?? ''),
                        (string)($row['source'] ?? 'ch'),
                    ]);
                    $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                    ++$n;
                } catch (\Throwable $e) {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    error_log('Seismo lex ingest row skipped: ' . $e->getMessage());
                }
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

    /**
     * Default retention policy: legal text is **never** auto-pruned
     * (see `core-plugin-architecture.mdc`). Slice 5a keeps that default
     * but wires `prune()` up anyway so the admin can opt into a cutoff
     * via the Retention settings surface without new code.
     *
     * @param list<string> $keepPredicates Tokens from
     *        {@see \Seismo\Service\RetentionService}.
     */
    public function prune(DateTimeImmutable $olderThan, array $keepPredicates): int
    {
        if (isSatellite()) {
            throw new \RuntimeException('LexItemRepository::prune must not run on a satellite; lex_items live in the mothership DB.');
        }

        $cutoff = $olderThan->format('Y-m-d H:i:s');
        $where  = $this->buildPruneWhere($keepPredicates);

        try {
            $stmt = $this->pdo->prepare(
                // Multi-table DELETE form: see EmailRepository::prune.
                'DELETE t FROM ' . entryTable('lex_items') . ' t WHERE ' . $where
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
     * Dry-run counterpart of `prune()`. Identical WHERE clause via
     * `buildPruneWhere()` — preview and real run cannot diverge.
     *
     * @param list<string> $keepPredicates
     */
    public function dryRunPrune(DateTimeImmutable $olderThan, array $keepPredicates): int
    {
        $cutoff = $olderThan->format('Y-m-d H:i:s');
        $where  = $this->buildPruneWhere($keepPredicates);

        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ' . entryTable('lex_items') . ' t WHERE ' . $where
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
        $keeps = \Seismo\Service\RetentionPredicates::forEntryType('lex_item', $keepPredicates);
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
    private function filterLexPageSources(array $sources): array
    {
        return $this->filterLexTrackedSources($sources, self::LEX_PAGE_SOURCES);
    }

    /**
     * @param list<string> $sources
     * @param list<string>|null $allowed null = {@see LEX_TRACKED_SOURCES}
     * @return list<string>
     */
    private function filterLexTrackedSources(array $sources, ?array $allowed = null): array
    {
        $allowed = $allowed ?? self::LEX_TRACKED_SOURCES;
        $flip = array_flip($allowed);
        $out = [];
        foreach ($sources as $s) {
            if (!is_string($s)) {
                continue;
            }
            if (isset($flip[$s])) {
                $out[] = $s;
            }
        }

        return array_values(array_unique($out));
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

        return date('Y-m-d', $ts);
    }
}
