<?php

declare(strict_types=1);

namespace Seismo\Core\Fetcher;

use DateTimeImmutable;
use DateTimeZone;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Header\DateHeader;

/**
 * IMAP inbox fetch for {@see \Seismo\Service\CoreRunner} (`core:mail`).
 *
 * Configuration is read from `system_config` keys (0.4-compatible names):
 *
 *   - `mail_imap_mailbox` — full `imap_open()` mailbox string, e.g.
 *     `{mail.example.com:993/imap/ssl}INBOX`. When empty, `mail_imap_host` +
 *     `mail_imap_port` + `mail_imap_flags` + `mail_imap_folder` are composed.
 *   - `mail_imap_username`, `mail_imap_password` — credentials (required).
 *   - `mail_max_messages` — cap per run (default 50).
 *   - `mail_search_criteria` — passed to `imap_search()` (default `UNSEEN`).
 *   - `mail_mark_seen` — truthy to set \\Seen after rows are persisted (second short IMAP connection).
 *
 * Returns normalised rows for {@see \Seismo\Repository\EmailIngestRepository::upsertImapBatch()}.
 * No SQL; may throw on I/O failure.
 */
final class ImapMailFetchService
{
    private const DEFAULT_MAX = 50;
    private const MAX_BODY_BYTES = 2_097_152;

    /**
     * @param array<string, string|null> $config Flat key => value from {@see \Seismo\Repository\SystemConfigRepository}
     * @return list<array<string, mixed>>
     */
    public function fetch(array $config): array
    {
        if (!function_exists('imap_open')) {
            throw new \RuntimeException('PHP imap extension is not enabled (install ext-imap).');
        }

        $mailbox = $this->resolveMailboxString($config);
        $user    = trim((string)($config['mail_imap_username'] ?? ''));
        $pass    = (string)($config['mail_imap_password'] ?? '');
        if ($mailbox === '' || $user === '') {
            throw new \RuntimeException('IMAP is not configured: set mail_imap_mailbox (or host) and mail_imap_username in system_config.');
        }

        $max = (int)($config['mail_max_messages'] ?? self::DEFAULT_MAX);
        if ($max < 1) {
            $max = self::DEFAULT_MAX;
        }
        if ($max > 500) {
            $max = 500;
        }

        $criteria = trim((string)($config['mail_search_criteria'] ?? 'UNSEEN'));
        if ($criteria === '') {
            $criteria = 'UNSEEN';
        }

        $imap = @imap_open($mailbox, $user, $pass, 0, 1);
        if ($imap === false) {
            $err = imap_last_error() ?: 'unknown error';
            imap_errors(); // clear stack

            throw new \RuntimeException('IMAP connection failed: ' . $err);
        }

        try {
            $uids = imap_search($imap, $criteria, SE_UID);
            if ($uids === false || $uids === []) {
                return [];
            }
            $uids = array_map('intval', $uids);
            $uids = array_values(array_filter($uids, static fn (int $u) => $u > 0));
            if ($uids === []) {
                return [];
            }
            // Newest last in typical mailbox order — take the tail window.
            sort($uids, SORT_NUMERIC);
            $uids = array_slice($uids, -$max);

            $out = [];
            foreach ($uids as $uid) {
                try {
                    $row = $this->fetchOneMessage($imap, $uid);
                    if ($row !== null) {
                        $out[] = $row;
                    }
                } catch (\Throwable $e) {
                    error_log('Seismo IMAP uid ' . $uid . ': ' . $e->getMessage());
                }
            }

            return $out;
        } finally {
            imap_close($imap);
        }
    }

    /**
     * Set \\Seen on UIDs after rows were persisted (avoids losing mail if DB write fails).
     *
     * @param array<string, string|null> $config
     * @param list<int> $uids
     */
    public function markSeen(array $config, array $uids): void
    {
        if ($uids === [] || !function_exists('imap_open')) {
            return;
        }
        if (!$this->truthy($config['mail_mark_seen'] ?? '1')) {
            return;
        }

        $mailbox = $this->resolveMailboxString($config);
        $user    = trim((string)($config['mail_imap_username'] ?? ''));
        $pass    = (string)($config['mail_imap_password'] ?? '');
        if ($mailbox === '' || $user === '') {
            return;
        }

        $imap = @imap_open($mailbox, $user, $pass, 0, 1);
        if ($imap === false) {
            imap_errors();

            return;
        }
        try {
            foreach ($uids as $uid) {
                $uid = (int)$uid;
                if ($uid <= 0) {
                    continue;
                }
                @imap_setflag_full($imap, (string)$uid, '\\Seen', ST_UID);
            }
        } finally {
            imap_close($imap);
        }
    }

    /**
     * @param array<string, string|null> $config
     */
    private function resolveMailboxString(array $config): string
    {
        $mb = trim((string)($config['mail_imap_mailbox'] ?? ''));
        if ($mb !== '') {
            return $mb;
        }
        $host = trim((string)($config['mail_imap_host'] ?? ''));
        if ($host === '') {
            return '';
        }
        $port = (int)($config['mail_imap_port'] ?? 993);
        if ($port <= 0 || $port > 65535) {
            $port = 993;
        }
        $flags = trim((string)($config['mail_imap_flags'] ?? '/imap/ssl'));
        if ($flags === '') {
            $flags = '/imap/ssl';
        }
        if ($flags[0] !== '/') {
            $flags = '/' . ltrim($flags, '/');
        }
        $folder = trim((string)($config['mail_imap_folder'] ?? 'INBOX'));
        if ($folder === '') {
            $folder = 'INBOX';
        }

        return '{' . $host . ':' . $port . $flags . '}' . $folder;
    }

    /**
     * @return ?array<string, mixed>
     */
    private function fetchOneMessage($imap, int $uid): ?array
    {
        $overview = @imap_fetch_overview($imap, (string)$uid, FT_UID);
        if ($overview === false || $overview === []) {
            return null;
        }
        $o = $overview[0];

        $rawHeaders = imap_fetchheader($imap, $uid, FT_UID);
        if ($rawHeaders === false) {
            $rawHeaders = '';
        }
        $rawBody = imap_body($imap, $uid, FT_UID);
        if ($rawBody === false) {
            $rawBody = '';
        }
        $rawMime = rtrim($rawHeaders) . "\r\n\r\n" . $rawBody;

        if ($rawMime === '') {
            return null;
        }

        $parser = new MailMimeParser();
        $message = $parser->parse($rawMime, true);

        $subject = $message->getHeaderValue('Subject') ?? '';
        $subject = $this->truncate($subject, 500);

        $messageId = $message->getHeaderValue('Message-ID');
        if ($messageId !== null) {
            $messageId = trim($messageId);
            if ($messageId === '') {
                $messageId = null;
            } else {
                $messageId = $this->truncate($messageId, 512);
            }
        }

        $fromEmail = null;
        $fromName = null;
        $fromHeader = $message->getHeader('From');
        if ($fromHeader !== null) {
            $addresses = $fromHeader->getAddresses();
            if (!empty($addresses)) {
                $fromEmail = $addresses[0]->getEmail();
                $fromName = $addresses[0]->getName();
            }
        }
        $fromDisplay = $message->getHeaderValue('From') ?? '';

        $to = $message->getHeaderValue('To');
        $cc = $message->getHeaderValue('Cc');

        $dateUtc = null;
        $dateHeader = $message->getHeader('Date');
        if ($dateHeader instanceof DateHeader) {
            $dt = $dateHeader->getDateTime();
            if ($dt instanceof \DateTimeInterface) {
                $dateUtc = DateTimeImmutable::createFromInterface($dt)->setTimezone(new \DateTimeZone('UTC'));
            }
        }
        if ($dateUtc === null) {
            $dateUtc = $this->parseMailDate($message->getHeaderValue('Date'));
        }

        $plain = $message->getTextContent() ?? '';
        $html = $message->getHtmlContent() ?? '';

        // 0.4 fetch_mail.php parity: derive readable plain from HTML when there is no text/plain.
        if (trim($plain) === '' && $html !== '') {
            $plain = EmailHtmlPlainText::fromHtml($html);
        }

        $plain = $this->capBody($plain);
        $html  = $this->capBody($html);

        return [
            'imap_uid'      => $uid,
            'message_id'    => $messageId,
            'from_addr'     => $fromDisplay !== '' ? $fromDisplay : null,
            'to_addr'       => $to !== '' ? $to : null,
            'cc_addr'       => $cc !== '' ? $cc : null,
            'subject'       => $subject !== '' ? $subject : null,
            'from_email'    => $fromEmail,
            'from_name'     => $fromName,
            'date_utc'      => $dateUtc->format('Y-m-d H:i:s'),
            'date_received' => $dateUtc->format('Y-m-d H:i:s'),
            'date_sent'     => null,
            'body_text'     => $plain !== '' ? $plain : null,
            'body_html'     => $html !== '' ? $html : null,
            'raw_headers'   => $rawHeaders !== '' ? $rawHeaders : null,
            'text_body'     => $plain !== '' ? $plain : null,
            'html_body'     => $html !== '' ? $html : null,
        ];
    }

    private function capBody(string $body): string
    {
        if (strlen($body) <= self::MAX_BODY_BYTES) {
            return $body;
        }

        return \Seismo\Util\Utf8ByteCap::truncate($body, self::MAX_BODY_BYTES, "\n\n[truncated]");
    }

    private function parseMailDate(?string $dateHeader): DateTimeImmutable
    {
        if ($dateHeader === null || trim($dateHeader) === '') {
            return new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }
        $ts = @strtotime($dateHeader);
        if ($ts === false) {
            return new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }

        return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('UTC'));
    }

    private function truncate(string $s, int $max): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }

        return mb_strcut($s, 0, $max, 'UTF-8');
    }

    /**
     * @param array<string, string|null> $config
     */
    private function truthy(mixed $v): bool
    {
        $s = strtolower(trim((string)$v));

        return $s === '1' || $s === 'true' || $s === 'yes' || $s === 'on';
    }
}
