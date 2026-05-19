<?php
/**
 * Migration 003 — unified `emails` table (Slice 4, schema version 19).
 *
 * Merges `fetched_emails` into `emails`, widens `id` to BIGINT, adds IMAP/body
 * columns. Idempotent: safe to re-run on hosts already unified.
 *
 * ## Operator / release-note warnings
 *
 * **`mergeFetchedEmails()` assumption:** This migration is only safe if, for this
 * database, either `fetched_emails` was the sole live mail write path or the
 * legacy `emails` table was empty of rows whose `id` collides with an
 * `fetched_emails.id`. If *both* tables were populated with independent
 * sequences such that the same numeric `id` refers to two different messages,
 * `INSERT … ON DUPLICATE KEY UPDATE` would merge unrelated content. The
 * migration aborts when `SELECT COUNT(*) FROM emails e INNER JOIN fetched_emails f ON e.id = f.id`
 * is non-zero — reconcile or renumber manually first.
 *
 * **`dedupeImapUids()`:** Duplicate non-null `imap_uid` values are resolved by
 * clearing `imap_uid` on all but the lowest `id` per duplicate set (irreversible
 * once the UNIQUE key exists). The migration logs how many rows were cleared to
 * `error_log` — check server logs after upgrade.
 *
 * Watch-item (not a v0.4 blocker): `entry_scores.entry_id` and
 * `entry_favourites.entry_id` remain INT in the consolidated schema. This
 * migration widens `emails.id` to BIGINT UNSIGNED so IMAP-era ids from
 * `fetched_emails` fit. If an email row’s id ever exceeds 2³¹−1, local scoring
 * / favourite rows that store that id as INT would mis-reference — unlikely
 * on typical installs; revisit only if you approach billions of email rows.
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;
use RuntimeException;

final class Migration003EmailsUnified
{
    public const VERSION = 19;

    public function apply(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'emails')) {
            throw new RuntimeException('Migration 003: table `emails` is missing.');
        }

        try {
            $pdo->exec(
                'ALTER TABLE emails MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT'
            );
        } catch (PDOException $e) {
            // Redundant MODIFY when the column is already BIGINT UNSIGNED typically
            // succeeds; permission errors, FK blocks, or other failures must surface.
            throw new RuntimeException(
                'Migration 003: widening emails.id failed: ' . $e->getMessage(),
                0,
                $e
            );
        }

        $this->addColumn($pdo, 'emails', 'imap_uid', 'BIGINT UNSIGNED NULL');
        $this->addColumn($pdo, 'emails', 'message_id', 'VARCHAR(512) NULL');
        $this->addColumn($pdo, 'emails', 'from_addr', 'TEXT NULL');
        $this->addColumn($pdo, 'emails', 'to_addr', 'TEXT NULL');
        $this->addColumn($pdo, 'emails', 'cc_addr', 'TEXT NULL');
        $this->addColumn($pdo, 'emails', 'date_utc', 'DATETIME NULL');
        $this->addColumn($pdo, 'emails', 'body_text', 'LONGTEXT NULL');
        $this->addColumn($pdo, 'emails', 'body_html', 'LONGTEXT NULL');
        $this->addColumn($pdo, 'emails', 'raw_headers', 'LONGTEXT NULL');

        if ($this->tableExists($pdo, 'fetched_emails')) {
            $this->assertNoOverlappingIds($pdo);
            $this->mergeFetchedEmails($pdo);
            $pdo->exec('DROP TABLE IF EXISTS fetched_emails');
        }

        $pdo->exec(
            'UPDATE emails SET
                body_text = COALESCE(body_text, text_body),
                body_html = COALESCE(body_html, html_body),
                date_utc = COALESCE(date_utc, date_received),
                from_addr = COALESCE(from_addr, from_email)
             WHERE body_text IS NULL OR body_html IS NULL OR date_utc IS NULL OR from_addr IS NULL'
        );

        $this->dedupeImapUids($pdo);
        $this->addImapUidUnique($pdo);
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);

        return (bool)$stmt->fetchColumn();
    }

    private function addColumn(PDO $pdo, string $table, string $column, string $ddl): void
    {
        if ($this->columnExists($pdo, $table, $column)) {
            return;
        }
        try {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$ddl}");
        } catch (PDOException $e) {
            throw new RuntimeException(
                "Migration 003: failed adding {$table}.{$column}: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);

        return (bool)$stmt->fetchColumn();
    }

    private function dedupeImapUids(PDO $pdo): void
    {
        $n = $pdo->exec(
            'UPDATE emails e1
             INNER JOIN emails e2
               ON e1.imap_uid = e2.imap_uid AND e1.imap_uid IS NOT NULL AND e1.id > e2.id
             SET e1.imap_uid = NULL'
        );
        if ($n !== false && $n > 0) {
            error_log(
                '[seismo] Migration 003: dedupeImapUids cleared imap_uid on ' . $n
                . ' row(s) (duplicate imap_uid; kept lowest id per value). Irreversible after uniq_emails_imap_uid — see Migration003EmailsUnified docblock.'
            );
        }
    }

    /**
     * If both tables have rows with the same primary key id, merging would corrupt data.
     */
    private function assertNoOverlappingIds(PDO $pdo): void
    {
        $stmt = $pdo->query(
            'SELECT COUNT(*) FROM emails e INNER JOIN fetched_emails f ON e.id = f.id'
        );
        $n = (int)$stmt->fetchColumn();
        if ($n > 0) {
            throw new RuntimeException(
                'Migration 003: `emails` and `fetched_emails` both contain ' . $n
                . ' overlapping primary key id(s). Merging would mix unrelated messages. '
                . 'Confirm which table was the live write path; if both were used with independent id sequences, renumber or reconcile manually before re-running.'
            );
        }
    }

    private function addImapUidUnique(PDO $pdo): void
    {
        try {
            $pdo->exec('ALTER TABLE emails ADD UNIQUE KEY uniq_emails_imap_uid (imap_uid)');
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
                return;
            }
            throw new RuntimeException(
                'Migration 003: could not add uniq_emails_imap_uid: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    private function mergeFetchedEmails(PDO $pdo): void
    {
        $sql = 'INSERT INTO emails (
            id, imap_uid, message_id, from_addr, to_addr, cc_addr, subject,
            date_utc, body_text, body_html, raw_headers, created_at,
            from_email, from_name, text_body, html_body, date_received, date_sent
        )
        SELECT
            fe.id, fe.imap_uid, fe.message_id, fe.from_addr, fe.to_addr, fe.cc_addr,
            SUBSTRING(fe.subject, 1, 500),
            fe.date_utc, fe.body_text, fe.body_html, fe.raw_headers, fe.created_at,
            NULL, NULL,
            fe.body_text, fe.body_html, fe.date_utc, NULL
        FROM fetched_emails fe
        ON DUPLICATE KEY UPDATE
            imap_uid = COALESCE(VALUES(imap_uid), emails.imap_uid),
            message_id = COALESCE(VALUES(message_id), emails.message_id),
            from_addr = COALESCE(VALUES(from_addr), emails.from_addr),
            to_addr = COALESCE(VALUES(to_addr), emails.to_addr),
            cc_addr = COALESCE(VALUES(cc_addr), emails.cc_addr),
            subject = COALESCE(VALUES(subject), emails.subject),
            date_utc = COALESCE(VALUES(date_utc), emails.date_utc),
            body_text = COALESCE(VALUES(body_text), emails.body_text),
            body_html = COALESCE(VALUES(body_html), emails.body_html),
            raw_headers = COALESCE(VALUES(raw_headers), emails.raw_headers),
            text_body = COALESCE(VALUES(text_body), emails.text_body),
            html_body = COALESCE(VALUES(html_body), emails.html_body),
            date_received = COALESCE(VALUES(date_received), emails.date_received)';

        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Migration 003: merge from fetched_emails failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
