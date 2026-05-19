<?php

declare(strict_types=1);

namespace Seismo\Repository;

use PDO;
use Seismo\Core\Mail\EmailIngestNormalizer;
use Seismo\Core\Mail\EmailListingBoilerplateStripper;

/**
 * INSERT / upsert path for the unified `emails` table (IMAP + Gmail API).
 *
 * Retention stays on {@see EmailRepository}; reads stay on {@see EntryRepository}.
 * All SQL uses {@see entryTable()}. Mutating methods refuse satellite mode.
 */
final class EmailIngestRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Upsert IMAP-fetched rows keyed by non-null `imap_uid` (unique in schema).
     *
     * @param list<array<string, mixed>> $rows
     */
    public function upsertImapBatch(array $rows): int
    {
        if (isSatellite()) {
            throw new \RuntimeException('EmailIngestRepository::upsertImapBatch must not run on a satellite.');
        }
        if ($rows === []) {
            return 0;
        }

        $t = entryTable('emails');
        $sql = 'INSERT INTO ' . $t . ' (
            imap_uid, gmail_message_id, message_id, from_addr, to_addr, cc_addr,
            subject, from_email, from_name, date_utc, date_received, date_sent,
            body_text, body_html, raw_headers, metadata, text_body, html_body
        ) VALUES (
            ?, NULL, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, NULL, ?, ?
        ) ON DUPLICATE KEY UPDATE
            message_id = VALUES(message_id),
            from_addr = VALUES(from_addr),
            to_addr = VALUES(to_addr),
            cc_addr = VALUES(cc_addr),
            subject = VALUES(subject),
            from_email = VALUES(from_email),
            from_name = VALUES(from_name),
            date_utc = VALUES(date_utc),
            date_received = VALUES(date_received),
            date_sent = VALUES(date_sent),
            body_text = VALUES(body_text),
            body_html = VALUES(body_html),
            raw_headers = VALUES(raw_headers),
            text_body = VALUES(text_body),
            html_body = VALUES(html_body)';

        return $this->executeBatch($sql, $rows, static function (array $row): ?int {
            $uid = isset($row['imap_uid']) ? (int)$row['imap_uid'] : 0;

            return $uid > 0 ? $uid : null;
        }, static function (array $row): array {
            return [
                (int)$row['imap_uid'],
                $row['message_id'],
                $row['from_addr'],
                $row['to_addr'],
                $row['cc_addr'],
                $row['subject'],
                $row['from_email'],
                $row['from_name'],
                $row['date_utc'],
                $row['date_received'],
                $row['date_sent'],
                $row['body_text'],
                $row['body_html'],
                $row['raw_headers'],
                $row['text_body'],
                $row['html_body'],
            ];
        });
    }

    /**
     * Upsert Gmail API rows keyed by `gmail_message_id`.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function upsertGmailBatch(array $rows): int
    {
        if (isSatellite()) {
            throw new \RuntimeException('EmailIngestRepository::upsertGmailBatch must not run on a satellite.');
        }
        if ($rows === []) {
            return 0;
        }

        $t = entryTable('emails');
        $sql = 'INSERT INTO ' . $t . ' (
            imap_uid, gmail_message_id, message_id, from_addr, to_addr, cc_addr,
            subject, from_email, from_name, date_utc, date_received, date_sent,
            body_text, body_html, raw_headers, metadata, text_body, html_body
        ) VALUES (
            NULL, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?
        ) ON DUPLICATE KEY UPDATE
            message_id = VALUES(message_id),
            from_addr = VALUES(from_addr),
            to_addr = VALUES(to_addr),
            cc_addr = VALUES(cc_addr),
            subject = VALUES(subject),
            from_email = VALUES(from_email),
            from_name = VALUES(from_name),
            date_utc = VALUES(date_utc),
            date_received = VALUES(date_received),
            date_sent = VALUES(date_sent),
            body_text = VALUES(body_text),
            body_html = VALUES(body_html),
            raw_headers = VALUES(raw_headers),
            metadata = VALUES(metadata),
            text_body = VALUES(text_body),
            html_body = VALUES(html_body)';

        return $this->executeBatch($sql, $rows, static function (array $row): ?string {
            $id = trim((string)($row['gmail_message_id'] ?? ''));

            return $id !== '' ? $id : null;
        }, static function (array $row): array {
            return [
                $row['gmail_message_id'],
                $row['message_id'],
                $row['from_addr'],
                $row['to_addr'],
                $row['cc_addr'],
                $row['subject'],
                $row['from_email'],
                $row['from_name'],
                $row['date_utc'],
                $row['date_received'],
                $row['date_sent'],
                $row['body_text'],
                $row['body_html'],
                $row['raw_headers'],
                $row['metadata'],
                $row['text_body'],
                $row['html_body'],
            ];
        });
    }

    /**
     * @param callable(array<string, mixed>): (int|string|null) $keyFilter
     * @param callable(array<string, mixed>): list<mixed> $bindRow
     */
    private function executeBatch(string $sql, array $rows, callable $keyFilter, callable $bindRow): int
    {
        $stmt = $this->pdo->prepare($sql);
        $subs = (new EmailSubscriptionRepository($this->pdo))
            ->listAll(EmailSubscriptionRepository::MAX_LIMIT, 0);

        $this->pdo->beginTransaction();
        try {
            $n = 0;
            foreach ($rows as $row) {
                if ($keyFilter($row) === null) {
                    continue;
                }
                $row = $this->prepareRow($row, $subs);
                $stmt->execute($bindRow($row));
                ++$n;
            }
            $this->pdo->commit();

            return $n;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $subs
     * @return array<string, mixed>
     */
    private function prepareRow(array $row, array $subs): array
    {
        $row = EmailIngestNormalizer::normalizeBodies($row);
        $row = $this->maybeStripListingBoilerplate($row, $subs);

        $row['message_id'] = $this->truncate($row['message_id'] ?? null, 512);
        $row['subject']    = $this->truncate($row['subject'] ?? null, 500);
        $row['from_email'] = $this->truncate($row['from_email'] ?? null, 255);
        $row['from_name']  = $this->truncate($row['from_name'] ?? null, 255);

        foreach ([
            'from_addr', 'to_addr', 'cc_addr', 'date_utc', 'date_received', 'date_sent',
            'body_text', 'body_html', 'raw_headers', 'text_body', 'html_body', 'metadata',
        ] as $k) {
            $row[$k] = $this->nullStr($row[$k] ?? null);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $subs
     * @return array<string, mixed>
     */
    private function maybeStripListingBoilerplate(array $row, array $subs): array
    {
        $ui = EmailSubscriptionRepository::resolveSubscriptionUiForFromEmail((string)($row['from_email'] ?? ''), $subs);
        if (empty($ui['strip_listing_boilerplate'])) {
            return $row;
        }
        $subj = (string)($row['subject'] ?? '');
        $forStrip = $subj !== '' ? $subj : null;
        foreach (['text_body', 'body_text'] as $key) {
            $t = (string)($row[$key] ?? '');
            if ($t !== '') {
                $row[$key] = EmailListingBoilerplateStripper::strip($t, $forStrip);
            }
        }

        return $row;
    }

    private function nullStr(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = (string)$v;

        return $s === '' ? null : $s;
    }

    private function truncate(mixed $v, int $max): ?string
    {
        $s = $this->nullStr($v);
        if ($s === null) {
            return null;
        }
        if (strlen($s) <= $max) {
            return $s;
        }

        return substr($s, 0, $max);
    }
}
