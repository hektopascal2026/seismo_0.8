<?php

declare(strict_types=1);

namespace Seismo\Repository;

use DateTimeImmutable;
use PDO;
use PDOException;
use Seismo\Core\Lex\LexPlainText;

/**
 * Lex family table — bounded reads, transactional upserts, satellite-safe entryTable().
 */
final class LexItemRepository
{
    public const MAX_LIMIT = 200;

    /** Lex list page sources (EU/CH/DE/FR) — not JUS; Parl MM is a `feed_item`. */
    public const LEX_PAGE_SOURCES = ['eu', 'ch', 'de', 'fr'];

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
        $sql = "SELECT * FROM {$table} WHERE source IN ({$placeholders}) ORDER BY document_date DESC LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset;

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
        $sources = $this->filterLexPageSources($sources);
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
        $sql   = "SELECT id, celex, work_uri, source, description
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

    /**
     * Write corpus text for one row. Updates `description` only when a synopsis is supplied.
     */
    public function updateCorpus(int $id, string $content, ?string $description = null): bool
    {
        if (isSatellite()) {
            throw new \RuntimeException('LexItemRepository::updateCorpus must not run on a satellite.');
        }
        if ($id <= 0 || trim($content) === '') {
            return false;
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
     * Insert/update Swiss Fedlex rows. All-or-nothing transaction.
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
                description = VALUES(description),
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
            foreach ($rows as $row) {
                $desc = $row['description'] ?? null;
                if ($desc !== null && $desc !== '') {
                    $desc = (string)$desc;
                } else {
                    $desc = null;
                }
                $content = $row['content'] ?? null;
                if ($content !== null && $content !== '') {
                    $content = (string)$content;
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
        $allowed = array_flip(self::LEX_PAGE_SOURCES);
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
