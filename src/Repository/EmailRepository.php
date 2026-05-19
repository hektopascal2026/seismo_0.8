<?php
/**
 * SQL-only repository for the `emails` table's retention lifecycle.
 *
 * Slice 4 unified 0.4's split `emails` / `fetched_emails` schema into a
 * single table (`Migration003EmailsUnified`, schema 19). Reads for the
 * dashboard timeline live on {@see EntryRepository}; reads for the
 * Magnitu / export API live on {@see MagnituExportRepository}; the
 * ingestion path is {@see \Seismo\Service\CoreRunner} `core:mail` (IMAP in-process)
 * plus optional legacy CLI under `fetcher/mail/` if you still run it.
 *
 * This class exists so Slice 5a's retention policy has a single SQL
 * owner for `emails`-scoped prune. The contract mirrors the other
 * family repos ({@see FeedItemRepository}, {@see LexItemRepository},
 * {@see CalendarEventRepository}):
 *
 *   - `prune()`        DELETE, returns deleted row count.
 *   - `dryRunPrune()`  COUNT(*) using the identical WHERE clause.
 *
 * Both methods share `buildPruneWhere()` so the "preview matches actual"
 * invariant is enforced by construction, not by eyeballing two queries.
 *
 * Satellites must never prune: entry-source tables are owned by the
 * mothership, so `isSatellite()` triggers a RuntimeException defence in
 * depth — the caller (`RetentionService`) already short-circuits on
 * satellites at a higher level.
 */

declare(strict_types=1);

namespace Seismo\Repository;

use DateTimeImmutable;
use PDO;
use PDOException;

final class EmailRepository
{
    /**
     * Age column for retention decisions. `created_at` is the insert
     * timestamp (always populated by MariaDB defaults). `date_received`
     * and `date_sent` come from the IMAP headers and are frequently
     * NULL, so they'd leak rows past retention. `created_at` is the
     * honest, unambiguous cutoff.
     */
    private const AGE_COLUMN = 'created_at';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Delete emails older than `$olderThan` unless a keep-predicate
     * protects them. Returns the number of rows deleted. Cutoff is
     * formatted in UTC (`bootstrap.php` pins both PHP and MariaDB to
     * UTC; see `core-plugin-architecture.mdc`).
     *
     * @param list<string> $keepPredicates Tokens from
     *        {@see \Seismo\Service\RetentionService} (`KEEP_*`). Unknown
     *        tokens are silently ignored for forward compatibility.
     */
    public function prune(DateTimeImmutable $olderThan, array $keepPredicates): int
    {
        if (isSatellite()) {
            throw new \RuntimeException('EmailRepository::prune must not run on a satellite; emails live in the mothership DB.');
        }

        $cutoff = $olderThan->format('Y-m-d H:i:s');
        $where  = $this->buildPruneWhere($keepPredicates);

        try {
            $stmt = $this->pdo->prepare(
                // Multi-table DELETE form: MariaDB/MySQL reject the single-
                // table form `DELETE FROM <t> t WHERE ...` with a 1064 syntax
                // error. We need the alias because `buildPruneWhere()` and the
                // RetentionPredicates EXISTS fragments both reference `t.*`.
                'DELETE t FROM ' . entryTable('emails') . ' t WHERE ' . $where
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
     * Count rows that `prune()` would delete at the same `$olderThan`
     * and `$keepPredicates`. SQL identical except for `SELECT COUNT(*)`
     * vs `DELETE`, which guarantees the preview matches the actual run
     * modulo rows that arrive between preview and run.
     *
     * @param list<string> $keepPredicates
     */
    public function dryRunPrune(DateTimeImmutable $olderThan, array $keepPredicates): int
    {
        $cutoff = $olderThan->format('Y-m-d H:i:s');
        $where  = $this->buildPruneWhere($keepPredicates);

        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ' . entryTable('emails') . ' t WHERE ' . $where
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
        $keeps = \Seismo\Service\RetentionPredicates::forEntryType('email', $keepPredicates);
        $where = 't.' . self::AGE_COLUMN . ' < ?';
        if ($keeps !== '') {
            $where .= ' AND NOT (' . $keeps . ')';
        }
        return $where;
    }
}
