<?php

declare(strict_types=1);

namespace Seismo\Repository;

use PDO;
use Seismo\Core\PlainTextNormalizer;
use Seismo\Core\Mail\EmailAlternateLocalePolicy;
use Seismo\Core\Mail\EmailIngestNormalizer;
use Seismo\Core\Mail\EmailListingBoilerplatePolicy;
use Seismo\Core\Mail\EmailListingBoilerplateStripper;
use Seismo\Core\Mail\EmailLocaleGuesser;
use Seismo\Core\Mail\EmailMetadata;
use Seismo\Core\Mail\EmailSubscriptionProcessor;
use Seismo\Core\Mail\EmailWebViewBodyHydrator;
use Seismo\Core\Mail\EmailWebViewUrlExtractor;

/**
 * INSERT / upsert path for the unified `emails` table (IMAP + Gmail API).
 *
 * Retention stays on {@see EmailRepository}; reads stay on {@see EntryRepository}.
 * All SQL uses {@see entryTable()}. Mutating methods refuse satellite mode.
 */
final class EmailIngestRepository
{
    /** Match {@see \Seismo\Core\Fetcher\ImapMailFetchService::MAX_BODY_BYTES}. */
    private const MAX_BODY_BYTES = 2_097_152;

    /** Hosted “view in browser” fetches per {@see executeBatch()} — cron wall-clock guard. */
    private const MAX_HOSTED_HYDRATE_PER_BATCH = 15;

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
            subject, derived_title, from_email, from_name, date_utc, date_received, date_sent,
            body_text, body_html, raw_headers, metadata, text_body, html_body, hidden
        ) VALUES (
            ?, NULL, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, NULL, ?, ?, 0
        ) ON DUPLICATE KEY UPDATE
            message_id = VALUES(message_id),
            from_addr = VALUES(from_addr),
            to_addr = VALUES(to_addr),
            cc_addr = VALUES(cc_addr),
            subject = VALUES(subject),
            derived_title = VALUES(derived_title),
            from_email = VALUES(from_email),
            from_name = VALUES(from_name),
            date_utc = VALUES(date_utc),
            date_received = VALUES(date_received),
            date_sent = VALUES(date_sent),
            body_text = VALUES(body_text),
            body_html = VALUES(body_html),
            raw_headers = VALUES(raw_headers),
            ' . $this->bodyDuplicateUpdateSql() . '';

        $existingImap = $this->existingImapUids($rows);

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
                $row['derived_title'],
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
        }, $existingImap, 'imap_uid');
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
            subject, derived_title, from_email, from_name, date_utc, date_received, date_sent,
            body_text, body_html, raw_headers, metadata, text_body, html_body, hidden
        ) VALUES (
            NULL, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, 0
        ) ON DUPLICATE KEY UPDATE
            message_id = VALUES(message_id),
            from_addr = VALUES(from_addr),
            to_addr = VALUES(to_addr),
            cc_addr = VALUES(cc_addr),
            subject = VALUES(subject),
            derived_title = VALUES(derived_title),
            from_email = VALUES(from_email),
            from_name = VALUES(from_name),
            date_utc = VALUES(date_utc),
            date_received = VALUES(date_received),
            date_sent = VALUES(date_sent),
            body_text = VALUES(body_text),
            body_html = VALUES(body_html),
            raw_headers = VALUES(raw_headers),
            ' . $this->bodyDuplicateUpdateSql() . ',
            metadata = VALUES(metadata)';

        $existingGmail = $this->existingGmailMessageIds($rows);

        $n = $this->executeBatch($sql, $rows, static function (array $row): ?string {
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
                $row['derived_title'],
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
        }, $existingGmail, 'gmail_message_id');

        if ($n > 0) {
            try {
                (new EmailSubscriptionRepository($this->pdo))->ensurePendingFromGmailIngest($rows);
            } catch (\Throwable $e) {
                error_log('Seismo Gmail pending senders: ' . $e->getMessage());
            }
        }

        return $n;
    }

    /**
     * @param callable(array<string, mixed>): (int|string|null) $keyFilter
     * @param callable(array<string, mixed>): list<mixed> $bindRow
     * @param array<string|int, true> $existingIngestKeys Keys already in DB for this batch (skip hosted fetch).
     * @param 'gmail_message_id'|'imap_uid'|null $ingestKeyField Row field used with $existingIngestKeys.
     */
    private function executeBatch(
        string $sql,
        array $rows,
        callable $keyFilter,
        callable $bindRow,
        array $existingIngestKeys = [],
        ?string $ingestKeyField = null,
    ): int {
        $stmt = $this->pdo->prepare($sql);
        $subs = (new EmailSubscriptionRepository($this->pdo))
            ->listActive(EmailSubscriptionRepository::MAX_LIMIT, 0);

        $prepared          = [];
        $hostedHydratesLeft = self::MAX_HOSTED_HYDRATE_PER_BATCH;
        foreach ($rows as $row) {
            if ($keyFilter($row) === null) {
                continue;
            }
            $hydrateHosted = true;
            if ($ingestKeyField !== null) {
                $key = $ingestKeyField === 'imap_uid'
                    ? (int)($row['imap_uid'] ?? 0)
                    : trim((string)($row[$ingestKeyField] ?? ''));
                $hydrateHosted = $key === '' || $key === 0 || !isset($existingIngestKeys[$key]);
            }
            $prepared[] = $this->prepareRow($row, $subs, $hydrateHosted, $hostedHydratesLeft);
        }

        $this->pdo->beginTransaction();
        try {
            $n = 0;
            foreach ($prepared as $i => $row) {
                $savepoint = 'email_ingest_' . $i;
                $this->pdo->exec('SAVEPOINT ' . $savepoint);
                try {
                    $stmt->execute($bindRow($row));

                    $parentId = (int)$this->pdo->lastInsertId();
                    if ($parentId === 0) {
                        $parentId = $this->findEmailId($row);
                    }

                    if ($parentId > 0) {
                        $sub = \Seismo\Repository\EmailSubscriptionRepository::findBestMatchingSubscription(
                            (string)($row['from_email'] ?? ''),
                            (string)($row['subject'] ?? ''),
                            $subs
                        );
                        if ($sub !== null && !empty($sub['digest_split_config'])) {
                            $cfg = \Seismo\Core\Mail\DigestSplitConfigNormalizer::resolveForIngest(
                                (string)$sub['digest_split_config']
                            );
                            if ($cfg !== null) {
                                $this->splitAndIngestStories($parentId, $row, $cfg);
                            }
                        }
                    }

                    $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                    ++$n;
                } catch (\Throwable $e) {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    error_log('Seismo email ingest row skipped: ' . $e->getMessage());
                }
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
    private function prepareRow(
        array $row,
        array $subs,
        bool $hydrateHostedBody = false,
        ?int &$hostedHydratesRemaining = null,
    ): array {
        $htmlBeforeNormalize = trim((string)($row['html_body'] ?? $row['body_html'] ?? ''));
        $row = EmailIngestNormalizer::normalizeBodies($row);
        $plainAfterNormalize = trim((string)($row['text_body'] ?? $row['body_text'] ?? ''));
        $customKeywords = [];
        $from = trim((string)($row['from_email'] ?? ''));
        if ($from !== '') {
            foreach ($subs as $sub) {
                if (empty($sub['disabled']) && !empty($sub['cleanup_config'])) {
                    $mt = (string)($sub['match_type'] ?? '');
                    $mv = (string)($sub['match_value'] ?? '');
                    if (EmailSubscriptionRepository::matchesAddress($from, $mt, $mv)) {
                        $cfg = json_decode((string)$sub['cleanup_config'], true);
                        if (is_array($cfg) && !empty($cfg['webview_keywords'])) {
                            $customKeywords = (array)$cfg['webview_keywords'];
                        }
                        break;
                    }
                }
            }
        }

        if (!$hydrateHostedBody) {
            $profile    = EmailLocaleGuesser::profileForEmail((string)($row['subject'] ?? ''), $plainAfterNormalize);
            $ranks      = EmailAlternateLocalePolicy::preferredLocaleRanks($profile);
            $resolution = EmailWebViewUrlExtractor::resolve($htmlBeforeNormalize, $plainAfterNormalize, $ranks, $customKeywords);
            if (($resolution->hydrateBody && EmailMetadata::bodySourceFromRow($row) !== EmailMetadata::BODY_SOURCE_WEB_VIEW)
                || EmailAlternateLocalePolicy::needsHostedHydrationRetry($row, $resolution, $plainAfterNormalize)
                || EmailAlternateLocalePolicy::needsTruncatedWebViewHydration($row, $resolution, $plainAfterNormalize)
            ) {
                $hydrateHostedBody = true;
            }
        }
        // Before listing/subscription stripping — those remove “view in browser” lines from plain text.
        $row = $this->applyWebViewProcessing(
            $row,
            $htmlBeforeNormalize,
            $plainAfterNormalize,
            $hydrateHostedBody,
            $hostedHydratesRemaining,
            $customKeywords,
        );
        $row = $this->maybeStripListingBoilerplate($row, $subs);
        $row = EmailSubscriptionProcessor::apply($row, $subs);
        $row = $this->syncAndCapBodies($row);

        $row['message_id'] = $this->truncate($row['message_id'] ?? null, 512);
        $row['subject']    = $this->truncate($row['subject'] ?? null, 500);
        $row['derived_title'] = $this->truncate($row['derived_title'] ?? null, 500);
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
        if (!EmailListingBoilerplatePolicy::shouldStrip($ui)) {
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

    /**
     * @param array<string, mixed> $row
     * @param string $htmlForExtract  Raw HTML as stored on fetch (before any body stripping).
     * @param string $plainForExtract Plain body right after {@see EmailIngestNormalizer}, before listing/subscription processors.
     * @return array<string, mixed>
     */
    public function applyWebViewProcessing(
        array $row,
        string $htmlForExtract = '',
        string $plainForExtract = '',
        bool $hydrateHostedBody = false,
        ?int &$hostedHydratesRemaining = null,
        array $customKeywords = [],
    ): array {
        $html = trim($htmlForExtract);
        if ($html === '') {
            $html = trim((string)($row['html_body'] ?? $row['body_html'] ?? ''));
        }
        $plain = trim($plainForExtract);
        if ($plain === '') {
            $plain = trim((string)($row['text_body'] ?? $row['body_text'] ?? ''));
        }

        $profile = EmailLocaleGuesser::profileForEmail(
            (string)($row['subject'] ?? ''),
            $plain
        );
        $ranks      = EmailAlternateLocalePolicy::preferredLocaleRanks($profile);
        $resolution = EmailWebViewUrlExtractor::resolve($html, $plain, $ranks, $customKeywords);

        if ($resolution->url !== null) {
            $row = EmailMetadata::mergeWebViewUrl($row, $resolution->url);
        }

        $canHydrate = $hydrateHostedBody
            && $resolution->url !== null
            && EmailMetadata::bodySourceFromRow($row) !== EmailMetadata::BODY_SOURCE_WEB_VIEW
            && ($hostedHydratesRemaining === null || $hostedHydratesRemaining > 0);

        $localeRank = $resolution->localeRank;
        $doHydrate  = $canHydrate && (
            ($resolution->hydrateBody && $localeRank !== null)
            || EmailAlternateLocalePolicy::needsTruncatedWebViewHydration($row, $resolution, $plain)
        );

        if ($doHydrate) {
            if ($localeRank === null) {
                $localeRank = EmailAlternateLocalePolicy::preferredHydrationRankForProfile($profile);
            }
            $row = (new EmailWebViewBodyHydrator())->hydrateRow(
                $row,
                $resolution->url,
                $localeRank
            );
            if ($hostedHydratesRemaining !== null) {
                --$hostedHydratesRemaining;
            }
        }

        if ($resolution->localeRank !== null
            && EmailMetadata::bodySourceFromRow($row) !== EmailMetadata::BODY_SOURCE_WEB_VIEW
        ) {
            $subj = trim((string)($row['subject'] ?? ''));
            foreach (['text_body', 'body_text'] as $key) {
                $t = (string)($row[$key] ?? '');
                if ($t !== '') {
                    $row[$key] = EmailListingBoilerplateStripper::strip(
                        $t,
                        $subj !== '' ? $subj : null
                    );
                }
            }
        }

        return $row;
    }

    /** @deprecated use {@see applyWebViewProcessing()} */
    public function applyWebViewUrlMetadata(
        array $row,
        string $htmlForExtract = '',
        string $plainForExtract = '',
    ): array {
        return $this->applyWebViewProcessing($row, $htmlForExtract, $plainForExtract, false);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, true>
     */
    private function existingGmailMessageIds(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = trim((string)($row['gmail_message_id'] ?? ''));
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return $this->lookupExistingColumn($ids, 'gmail_message_id');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, true>
     */
    private function existingImapUids(array $rows): array
    {
        $uids = [];
        foreach ($rows as $row) {
            $uid = (int)($row['imap_uid'] ?? 0);
            if ($uid > 0) {
                $uids[$uid] = true;
            }
        }

        return $this->lookupExistingColumn($uids, 'imap_uid');
    }

    /**
     * @param array<string|int, true> $keys
     * @return array<string|int, true>
     */
    private function lookupExistingColumn(array $keys, string $column): array
    {
        if ($keys === []) {
            return [];
        }
        if ($column !== 'gmail_message_id' && $column !== 'imap_uid') {
            return [];
        }

        $t     = entryTable('emails');
        $found = [];
        foreach (array_chunk(array_keys($keys), 200, true) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt         = $this->pdo->prepare(
                'SELECT ' . $column . ' FROM ' . $t . ' WHERE ' . $column . ' IN (' . $placeholders . ')'
            );
            $stmt->execute(array_values($chunk));
            while (($val = $stmt->fetchColumn()) !== false) {
                if ($column === 'imap_uid') {
                    $found[(int)$val] = true;
                } else {
                    $found[(string)$val] = true;
                }
            }
        }

        return $found;
    }

    /** SQL fragment: do not overwrite bodies already hydrated from a hosted DE/EN web view. */
    private function bodyDuplicateUpdateSql(): string
    {
        $preserve = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.body_source')), '') = '"
            . EmailMetadata::BODY_SOURCE_WEB_VIEW . "'";

        return 'text_body = IF(' . $preserve . ', text_body, VALUES(text_body)), '
            . 'html_body = IF(' . $preserve . ', html_body, VALUES(html_body)), '
            . 'body_text = IF(' . $preserve . ', body_text, VALUES(body_text)), '
            . 'body_html = IF(' . $preserve . ', body_html, VALUES(body_html))';
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

        return mb_strcut($s, 0, $max, 'UTF-8');
    }

    /**
     * Keep legacy `text_body`/`html_body` mirrors aligned with `body_*` and within DB limits.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function syncAndCapBodies(array $row): array
    {
        foreach (
            [
                ['text_body', 'body_text'],
                ['html_body', 'body_html'],
            ] as [$legacy, $modern]
        ) {
            $v = trim((string)($row[$legacy] ?? $row[$modern] ?? ''));
            if ($v === '') {
                continue;
            }
            $v = $this->capBodyBytes($v);
            $row[$legacy] = $v;
            $row[$modern] = $v;
        }

        return $row;
    }

    private function capBodyBytes(string $body): string
    {
        $body = PlainTextNormalizer::forIngest($body);
        if ($body === '') {
            return '';
        }
        if (strlen($body) <= self::MAX_BODY_BYTES) {
            return $body;
        }

        return \Seismo\Util\Utf8ByteCap::truncate($body, self::MAX_BODY_BYTES, "\n\n[truncated]");
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchRowsForSubscriptionMatch(
        string $matchType,
        string $matchValue,
        int $limit,
        int $offset = 0,
    ): array {
        if (isSatellite()) {
            throw new \RuntimeException('EmailIngestRepository::fetchRowsForSubscriptionMatch must not run on a satellite.');
        }
        $matchType = strtolower(trim($matchType));
        $limit     = max(1, min(500, $limit));
        $offset    = max(0, $offset);
        $t         = entryTable('emails');
        if ($matchType === 'email') {
            $param = strtolower(trim($matchValue));
            if ($param === '') {
                return [];
            }
            $stmt = $this->pdo->prepare(
                'SELECT id, subject, derived_title, from_email, from_name, from_addr, to_addr, cc_addr,
                        message_id, text_body, body_text, html_body, body_html, metadata,
                        date_utc, date_received, date_sent
                 FROM ' . $t . ' WHERE LOWER(from_email) = ? AND parent_email_id IS NULL ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset
            );
            $stmt->execute([$param]);
        } elseif ($matchType === 'domain') {
            $domain = strtolower(ltrim(trim($matchValue), '@'));
            if ($domain === '') {
                return [];
            }
            $stmt = $this->pdo->prepare(
                'SELECT id, subject, derived_title, from_email, from_name, from_addr, to_addr, cc_addr,
                        message_id, text_body, body_text, html_body, body_html, metadata,
                        date_utc, date_received, date_sent
                 FROM ' . $t . '
                 WHERE ' . EmailSubscriptionRepository::sqlDomainHostMatch('from_email') . ' AND parent_email_id IS NULL
                 ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset
            );
            $stmt->execute([$domain, $domain]);
        } else {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function updateProcessedContent(int $emailId, string $textBody, ?string $derivedTitle, ?string $metadataJson = null): void
    {
        if (isSatellite()) {
            throw new \RuntimeException('EmailIngestRepository::updateProcessedContent must not run on a satellite.');
        }
        if ($emailId <= 0) {
            return;
        }
        $textBody = $this->capBodyBytes($textBody);
        $t        = entryTable('emails');
        if ($metadataJson !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE ' . $t . ' SET text_body = ?, body_text = ?, derived_title = ?, metadata = ? WHERE id = ?'
            );
            $stmt->execute([
                $textBody !== '' ? $textBody : null,
                $textBody !== '' ? $textBody : null,
                $derivedTitle,
                $metadataJson !== '' ? $metadataJson : null,
                $emailId,
            ]);

            return;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE ' . $t . ' SET text_body = ?, body_text = ?, derived_title = ? WHERE id = ?'
        );
        $stmt->execute([
            $textBody !== '' ? $textBody : null,
            $textBody !== '' ? $textBody : null,
            $derivedTitle,
            $emailId,
        ]);
    }

    private function findEmailId(array $row): int
    {
        $t = entryTable('emails');
        if (!empty($row['imap_uid'])) {
            $stmt = $this->pdo->prepare("SELECT id FROM {$t} WHERE imap_uid = ? LIMIT 1");
            $stmt->execute([(int)$row['imap_uid']]);
            return (int)$stmt->fetchColumn();
        }
        if (!empty($row['gmail_message_id'])) {
            $stmt = $this->pdo->prepare("SELECT id FROM {$t} WHERE gmail_message_id = ? LIMIT 1");
            $stmt->execute([$row['gmail_message_id']]);
            return (int)$stmt->fetchColumn();
        }
        if (!empty($row['message_id'])) {
            $stmt = $this->pdo->prepare("SELECT id FROM {$t} WHERE message_id = ? LIMIT 1");
            $stmt->execute([$row['message_id']]);
            return (int)$stmt->fetchColumn();
        }
        return 0;
    }

    public function splitAndIngestStories(int $parentId, array $parentRow, array $cfg): void
    {
        $parentRow = $this->resolveParentRowForSplit($parentId, $parentRow);
        $t = entryTable('emails');
        $scoreRepo = new EntryScoreRepository($this->pdo);

        $stmtChildIds = $this->pdo->prepare("SELECT id FROM {$t} WHERE parent_email_id = ?");
        $stmtChildIds->execute([$parentId]);
        $oldChildIds = $stmtChildIds->fetchAll(PDO::FETCH_COLUMN);
        if (is_array($oldChildIds) && $oldChildIds !== []) {
            $scoreRepo->deleteForEntries('email', array_map('intval', $oldChildIds));
        }

        // Delete existing child entries to ensure clean idempotency
        $stmtDel = $this->pdo->prepare("DELETE FROM {$t} WHERE parent_email_id = ?");
        $stmtDel->execute([$parentId]);

        $html = (string)($parentRow['html_body'] ?? $parentRow['body_html'] ?? '');
        $text = (string)($parentRow['text_body'] ?? $parentRow['body_text'] ?? '');

        $splitter = new \Seismo\Core\Mail\EmailDigestSplitterService();
        $stories = $splitter->split($html, $text, $cfg);
        if ($stories === []) {
            return;
        }

        $sql = "INSERT INTO {$t} (
            imap_uid, gmail_message_id, message_id, parent_email_id, from_addr, to_addr, cc_addr,
            subject, derived_title, from_email, from_name, date_utc, date_received, date_sent,
            body_text, body_html, text_body, html_body, metadata, hidden
        ) VALUES (
            NULL, NULL, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, 0
        )";

        $stmtIns = $this->pdo->prepare($sql);

        foreach ($stories as $index => $story) {
            $childMsgId = null;
            if (!empty($parentRow['message_id'])) {
                $childMsgId = $parentRow['message_id'] . '_story_' . $index;
            }

            $metaPayload = [];
            if ($story['link'] !== null) {
                $metaPayload['link'] = $story['link'];
                $metaPayload['web_view_url'] = $story['link'];
            }
            $parentInboxDate = trim((string)($parentRow['date_received'] ?? ''));
            if ($parentInboxDate === '') {
                $parentInboxDate = trim((string)($parentRow['date_utc'] ?? ''));
            }
            if ($parentInboxDate === '') {
                $parentInboxDate = trim((string)($parentRow['date_sent'] ?? ''));
            }
            if ($parentInboxDate !== '') {
                $metaPayload['parent_inbox_date'] = $parentInboxDate;
            }
            $meta = $metaPayload === [] ? null : json_encode($metaPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $stmtIns->execute([
                $childMsgId,
                $parentId,
                $parentRow['from_addr'] ?? null,
                $parentRow['to_addr'] ?? null,
                $parentRow['cc_addr'] ?? null,
                $story['title'], // subject
                $story['title'], // derived_title
                $parentRow['from_email'] ?? null,
                $parentRow['from_name'] ?? null,
                $parentRow['date_utc'] ?? null,
                $parentRow['date_received'] ?? null,
                $parentRow['date_sent'] ?? null,
                $story['text_body'], // body_text
                $story['html_body'], // body_html
                $story['text_body'], // text_body
                $story['html_body'], // html_body
                $meta
            ]);
        }

        $scoreRepo->deleteForEntry('email', $parentId);
    }

    /**
     * Reprocess passes a slim parent row (no dates). Always merge inbox timestamps from DB.
     *
     * @param array<string, mixed> $parentRow
     * @return array<string, mixed>
     */
    private function resolveParentRowForSplit(int $parentId, array $parentRow): array
    {
        if ($parentId <= 0) {
            return $parentRow;
        }

        $t = entryTable('emails');
        $stmt = $this->pdo->prepare(
            "SELECT message_id, from_addr, to_addr, cc_addr, from_email, from_name,
                    date_utc, date_received, date_sent
               FROM {$t} WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$parentId]);
        $dbRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($dbRow)) {
            return $parentRow;
        }

        $merged = array_merge($dbRow, $parentRow);
        foreach (['message_id', 'from_addr', 'to_addr', 'cc_addr', 'from_email', 'from_name', 'date_utc', 'date_received', 'date_sent'] as $col) {
            $fromDb = $dbRow[$col] ?? null;
            $fromRow = $parentRow[$col] ?? null;
            if (($merged[$col] === null || $merged[$col] === '') && $fromDb !== null && $fromDb !== '') {
                $merged[$col] = $fromDb;
            } elseif (($fromRow === null || $fromRow === '') && $fromDb !== null && $fromDb !== '') {
                $merged[$col] = $fromDb;
            }
        }

        return $merged;
    }
}
