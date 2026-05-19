<?php

declare(strict_types=1);

namespace Seismo\Repository;

use DateTimeImmutable;
use PDO;
use PDOException;

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
                celex, title, description, document_date, document_type,
                eurlex_url, work_uri, source
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                description = VALUES(description),
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
                $stmt->execute([
                    (string)$row['celex'],
                    (string)($row['title'] ?? ''),
                    $desc,
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
