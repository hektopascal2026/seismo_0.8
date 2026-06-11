<?php

declare(strict_types=1);

namespace Seismo\Repository;

use PDO;
use Seismo\Core\Mail\EmailBodyProcessorRegistry;
use Seismo\Core\Mail\EmailNewsletterSubjectPrefix;
use Seismo\Mail\MailModule;

/**
 * `email_subscriptions` — domain-first newsletter registry (Slice 8).
 *
 * Do not use `sender_tags`; that table is legacy-only for dashboard pills.
 *
 * `auto_detected = 1` rows are Gmail-ingest proposals awaiting review;
 * confirmed subscriptions use `auto_detected = 0`.
 */
final class EmailSubscriptionRepository
{
    public const MAX_LIMIT = 200;

    public const MODULE_MAIL = MailModule::SCOPE_MAIL;

    public const MODULE_NEWSLETTER = MailModule::SCOPE_NEWSLETTER;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Whether `from_email` matches a subscription row (domain or exact email).
     */
    public static function matchesAddress(string $fromEmail, string $matchType, string $matchValue): bool
    {
        $from = strtolower(trim($fromEmail));
        $mv   = trim($matchValue);
        if ($from === '' || $mv === '') {
            return false;
        }
        if ($matchType === 'email') {
            return $from === strtolower($mv);
        }
        if ($matchType !== 'domain') {
            return false;
        }
        $domain = strtolower(ltrim($mv, '@'));
        if ($domain === '') {
            return false;
        }
        $at = strpos($from, '@');
        if ($at === false) {
            return false;
        }
        $host = substr($from, $at + 1);

        return $host === $domain || str_ends_with($host, '.' . $domain);
    }

    /**
     * SQL host match for domain subscriptions — same semantics as {@see matchesAddress()}.
     * Bind the same domain string twice.
     */
    public static function sqlDomainHostMatch(string $fromEmailColumn = 'from_email'): string
    {
        $col = preg_replace('/[^a-z_]/', '', strtolower($fromEmailColumn)) ?: 'from_email';

        return '(LOWER(SUBSTRING_INDEX(' . $col . ', \'@\', -1)) = ?'
            . ' OR LOWER(SUBSTRING_INDEX(' . $col . ', \'@\', -1)) LIKE CONCAT(\'%.\', ?))';
    }

    /**
     * @param list<array<string, mixed>> $subscriptionRows
     */
    public static function matchesAnyRow(string $fromEmail, array $subscriptionRows): bool
    {
        $from = trim($fromEmail);
        if ($from === '') {
            return false;
        }
        foreach ($subscriptionRows as $row) {
            $mt = (string)($row['match_type'] ?? '');
            $mv = (string)($row['match_value'] ?? '');
            if (self::matchesAddress($from, $mt, $mv)) {
                return true;
            }
        }

        return false;
    }

    public static function normalizeSubjectFilter(mixed $value): string
    {
        return trim((string)($value ?? ''));
    }

    /**
     * Plain substring (case-insensitive) or regex when wrapped in {@code /.../}.
     */
    public static function matchesSubjectFilter(string $subject, ?string $subjectFilter): bool
    {
        $subjFilter = self::normalizeSubjectFilter($subjectFilter);
        if ($subjFilter === '') {
            return true;
        }
        $subject = trim($subject);
        if ($subject === '') {
            return false;
        }
        if (str_starts_with($subjFilter, '/')
            && (str_ends_with($subjFilter, '/') || preg_match('/\/[imsuy]*$/', $subjFilter))
        ) {
            try {
                return (bool)preg_match($subjFilter, $subject);
            } catch (\Throwable) {
                return false;
            }
        }

        return mb_stripos($subject, $subjFilter) !== false;
    }

    /**
     * Whether a stored email matches a subscription row's address + subject_filter rules.
     * Used when resolving samples for pending proposals (auto_detected rows included).
     *
     * @param array<string, mixed> $subscription
     */
    public static function matchesSubscriptionRowForStoredEmail(
        array $subscription,
        string $fromEmail,
        ?string $subject,
    ): bool {
        if (!empty($subscription['disabled'])) {
            return false;
        }
        $mt = (string)($subscription['match_type'] ?? '');
        $mv = (string)($subscription['match_value'] ?? '');
        if (!self::matchesAddress($fromEmail, $mt, $mv)) {
            return false;
        }

        return self::matchesSubjectFilter($subject !== null ? trim($subject) : '', $subscription['subject_filter'] ?? null);
    }

    /**
     * Whether a confirmed subscription row owns an email (address + optional subject filter).
     *
     * @param array<string, mixed> $subscription
     */
    public static function subscriptionMatchesEmail(array $subscription, string $fromEmail, ?string $subject): bool
    {
        if (!empty($subscription['disabled']) || !empty($subscription['auto_detected'])) {
            return false;
        }

        return self::matchesSubscriptionRowForStoredEmail($subscription, $fromEmail, $subject);
    }

    /**
     * Find the best matching non-disabled, active subscription row.
     * Evaluates subject_filter to disambiguate multiple matches.
     *
     * @param list<array<string, mixed>> $subscriptionRows
     * @return array<string, mixed>|null
     */
    public static function findBestMatchingSubscription(string $fromEmail, ?string $subject, array $subscriptionRows): ?array
    {
        $from = trim($fromEmail);
        if ($from === '') {
            return null;
        }

        $bestScore = -1;
        $bestRow   = null;

        $subject = $subject !== null ? trim($subject) : '';

        foreach ($subscriptionRows as $sub) {
            if (!empty($sub['disabled']) || !empty($sub['auto_detected'])) {
                continue;
            }
            if (!self::matchesSubscriptionRowForStoredEmail($sub, $from, $subject)) {
                continue;
            }

            $mt = (string)($sub['match_type'] ?? '');
            $score = $mt === 'email' ? 20 : 10;
            if (self::normalizeSubjectFilter($sub['subject_filter'] ?? null) !== '') {
                $score += 5;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRow   = $sub;
            }
        }

        return $bestRow;
    }

    /**
     * Assign {@see emails.email_subscription_id} on stored parent rows from routing rules.
     */
    public function backfillEmailSubscriptionIds(int $batchSize = 500): int
    {
        $this->assertNotSatellite();
        $batchSize = max(50, min(2000, $batchSize));
        $subs      = $this->listActive(self::MAX_LIMIT, 0);
        if ($subs === []) {
            return 0;
        }

        $t     = entryTable('emails');
        $total = 0;
        $lastId = 0;

        $select = $this->pdo->prepare(
            "SELECT id, from_email, subject
             FROM {$t}
             WHERE parent_email_id IS NULL
               AND (email_subscription_id IS NULL OR email_subscription_id = 0)
               AND id > ?
             ORDER BY id ASC
             LIMIT " . (int)$batchSize
        );
        $update = $this->pdo->prepare(
            "UPDATE {$t} SET email_subscription_id = ? WHERE id = ?"
        );

        while (true) {
            $select->execute([$lastId]);
            $rows = $select->fetchAll(\PDO::FETCH_ASSOC);
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $emailId = (int)($row['id'] ?? 0);
                if ($emailId <= 0) {
                    continue;
                }
                $lastId = $emailId;

                $best = self::findBestMatchingSubscription(
                    (string)($row['from_email'] ?? ''),
                    isset($row['subject']) ? (string)$row['subject'] : null,
                    $subs,
                );
                if ($best === null) {
                    continue;
                }
                $subId = (int)($best['id'] ?? 0);
                if ($subId <= 0) {
                    continue;
                }
                $update->execute([$subId, $emailId]);
                ++$total;
            }

            if (count($rows) < $batchSize) {
                break;
            }
        }

        return $total;
    }

    /**
     * Inbox card flags from the best matching non-disabled, confirmed row.
     *
     * @param list<array<string, mixed>> $subscriptionRows
     * @return array{display_name: ?string, strip_listing_boilerplate: bool}
     */
    public static function resolveSubscriptionUiForFromEmail(string $fromEmail, array $subscriptionRows, ?string $subject = null): array
    {
        $bestRow = self::findBestMatchingSubscription($fromEmail, $subject, $subscriptionRows);
        if ($bestRow === null) {
            return [
                'display_name'              => null,
                'strip_listing_boilerplate' => false,
                'hydrate_webview'           => false,
                'module_scope'              => self::MODULE_MAIL,
            ];
        }

        return [
            'display_name'              => trim((string)($bestRow['display_name'] ?? '')) ?: null,
            'strip_listing_boilerplate' => !empty($bestRow['strip_listing_boilerplate']),
            'hydrate_webview'           => !empty($bestRow['hydrate_webview']),
            'module_scope'              => self::rowModuleScope($bestRow),
        ];
    }

    public static function rowModuleScope(array $row): string
    {
        return self::normalizeModuleScope($row['module_scope'] ?? self::MODULE_MAIL);
    }

    public static function normalizeModuleScope(mixed $value): string
    {
        $scope = strtolower(trim((string)($value ?? '')));

        return $scope === self::MODULE_NEWSLETTER ? self::MODULE_NEWSLETTER : self::MODULE_MAIL;
    }

    /**
     * @param list<array<string, mixed>> $subscriptionRows
     */
    public static function resolveDisplayNameForFromEmail(string $fromEmail, array $subscriptionRows, ?string $subject = null): ?string
    {
        return self::resolveSubscriptionUiForFromEmail($fromEmail, $subscriptionRows, $subject)['display_name'];
    }

    /**
     * Propose a human display name from Gmail From headers.
     */
    public static function proposeDisplayName(?string $fromName, string $domain): string
    {
        $name = trim((string)$fromName);
        $name = trim($name, "\"'");
        if ($name !== '' && stripos($name, '@') === false && filter_var($name, FILTER_VALIDATE_EMAIL) === false) {
            return $name;
        }

        $parts = explode('.', strtolower($domain));
        if (count($parts) >= 2) {
            $label = $parts[count($parts) - 2];
        } else {
            $label = $parts[0] ?? $domain;
        }
        $label = preg_replace('/[^a-z0-9]+/i', ' ', $label) ?? $label;
        $label = trim($label);
        if ($label === '') {
            return $domain;
        }
        if (function_exists('mb_convert_case')) {
            return mb_convert_case($label, MB_CASE_TITLE, 'UTF-8');
        }

        return ucwords(strtolower($label));
    }

    public static function extractDomainFromEmail(string $fromEmail): ?string
    {
        $from = strtolower(trim($fromEmail));
        $at   = strpos($from, '@');
        if ($at === false || $at === strlen($from) - 1) {
            return null;
        }
        $host = substr($from, $at + 1);

        return $host !== '' ? $host : null;
    }

    public static function isPendingRow(array $row): bool
    {
        return !empty($row['auto_detected']);
    }

    /**
     * Confirmed subscriptions (main registry table).
     *
     * @return list<array<string, mixed>>
     */
    public function listActive(int $limit, int $offset): array
    {
        return $this->listByAutoDetected(false, $limit, $offset);
    }

    /**
     * Confirmed subscriptions for a Mail or Newsletter admin module.
     *
     * @return list<array<string, mixed>>
     */
    public function listActiveForModule(string $moduleScope, int $limit, int $offset): array
    {
        $scope = self::normalizeModuleScope($moduleScope);
        $limit  = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);
        $t      = entryTable('email_subscriptions');
        $sql    = "SELECT * FROM {$t}
            WHERE removed_at IS NULL
              AND auto_detected = 0
              AND module_scope = ?
            ORDER BY id DESC
            LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$scope]);

        return $stmt->fetchAll();
    }

    /**
     * Active subscriptions with split drift (failed to split stories) for a module.
     *
     * @return list<array<string, mixed>>
     */
    public function listDriftingForModule(string $moduleScope): array
    {
        $scope = self::normalizeModuleScope($moduleScope);
        $t      = entryTable('email_subscriptions');
        $sql    = "SELECT * FROM {$t}
            WHERE removed_at IS NULL
              AND auto_detected = 0
              AND module_scope = ?
              AND split_drift = 1
            ORDER BY id DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$scope]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Gmail-ingest proposals awaiting review for a Mail or Newsletter module.
     *
     * @return list<array<string, mixed>>
     */
    public function listPendingForModule(string $moduleScope, int $limit, int $offset): array
    {
        $scope  = self::normalizeModuleScope($moduleScope);
        $limit  = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);
        $t      = entryTable('email_subscriptions');
        $sql    = "SELECT * FROM {$t}
            WHERE removed_at IS NULL
              AND auto_detected = 1
              AND module_scope = ?
            ORDER BY id DESC
            LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$scope]);

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(int $limit, int $offset): array
    {
        return $this->listActive($limit, $offset);
    }

    /**
     * All non-removed rows (pending + active) for duplicate checks during Gmail ingest.
     *
     * @return list<array<string, mixed>>
     */
    public function listAllIncludingPending(int $limit, int $offset): array
    {
        $limit  = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);
        $t      = entryTable('email_subscriptions');
        $sql    = "SELECT * FROM {$t}
            WHERE removed_at IS NULL
            ORDER BY id DESC
            LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset;

        return $this->pdo->query($sql)->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listByAutoDetected(bool $pending, int $limit, int $offset): array
    {
        $limit  = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);
        $flag   = $pending ? 1 : 0;
        $t      = entryTable('email_subscriptions');
        $sql    = "SELECT * FROM {$t}
            WHERE removed_at IS NULL AND auto_detected = {$flag}
            ORDER BY id DESC
            LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset;

        return $this->pdo->query($sql)->fetchAll();
    }

    /**
     * @return ?array<string, mixed>
     */
    public function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $t   = entryTable('email_subscriptions');
        $sql = "SELECT * FROM {$t} WHERE id = ? AND removed_at IS NULL LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * After a Gmail ingest batch, queue domain subscriptions for unknown senders.
     *
     * @param list<array<string, mixed>> $ingestRows normalised Gmail rows
     */
    public function ensurePendingFromGmailIngest(array $ingestRows): int
    {
        if (isSatellite() || $ingestRows === []) {
            return 0;
        }

        $known = $this->listAllIncludingPending(self::MAX_LIMIT, 0);
        $created = 0;
        $seenEmails = [];

        foreach ($ingestRows as $row) {
            $from = strtolower(trim((string)($row['from_email'] ?? '')));
            if ($from === '') {
                continue;
            }
            if (isset($seenEmails[$from])) {
                continue;
            }
            $seenEmails[$from] = true;

            if (self::matchesAnyRow($from, $known)) {
                continue;
            }

            $fromName = trim((string)($row['from_name'] ?? ''));
            $id = $this->insertPendingEmail($from, $fromName);
            if ($id <= 0) {
                continue;
            }
            ++$created;
            $known[] = [
                'match_type'    => 'email',
                'match_value'   => $from,
                'display_name'  => $fromName !== '' ? $fromName : $from,
                'auto_detected' => 1,
            ];
        }

        return $created;
    }

    /**
     * Queue additional newsletter subscriptions when a sender already on Newsletter
     * mails a subject pattern that matches none of the confirmed rows.
     *
     * @param list<array<string, mixed>> $ingestRows normalised Gmail rows
     */
    public function ensurePendingNewsletterTypesFromIngest(array $ingestRows): int
    {
        if (isSatellite() || $ingestRows === []) {
            return 0;
        }

        $known = $this->listAllIncludingPending(self::MAX_LIMIT, 0);
        $confirmedNewsletter = self::filterConfirmedForModule($known, self::MODULE_NEWSLETTER);
        if ($confirmedNewsletter === []) {
            return 0;
        }

        $created  = 0;
        $seenKeys = [];

        foreach ($ingestRows as $row) {
            $from = strtolower(trim((string)($row['from_email'] ?? '')));
            if ($from === '') {
                continue;
            }
            $subject = isset($row['subject']) ? trim((string)$row['subject']) : '';
            if ($subject === '') {
                continue;
            }

            if (!self::matchesAnyRow($from, $confirmedNewsletter)) {
                continue;
            }

            if (self::findBestMatchingSubscription($from, $subject, $confirmedNewsletter) !== null) {
                continue;
            }

            $prefix = EmailNewsletterSubjectPrefix::propose($subject);
            if ($prefix === null) {
                continue;
            }

            $anchor = self::findAnchorNewsletterSubscription($from, $confirmedNewsletter);
            if ($anchor === null) {
                continue;
            }

            $matchType  = (string)($anchor['match_type'] ?? '');
            $matchValue = (string)($anchor['match_value'] ?? '');
            $subjectFilter = self::normalizeSubjectFilter($prefix);
            $batchKey = $matchType . '|' . $matchValue . '|' . $subjectFilter . '|' . self::MODULE_NEWSLETTER;
            if (isset($seenKeys[$batchKey])) {
                continue;
            }
            $seenKeys[$batchKey] = true;

            if (self::subscriptionProposalExists($known, $matchType, $matchValue, $subjectFilter, self::MODULE_NEWSLETTER)) {
                continue;
            }

            try {
                $id = $this->insert([
                    'match_type'     => $matchType,
                    'match_value'    => $matchValue,
                    'display_name'   => $prefix,
                    'subject_filter' => $subjectFilter,
                    'module_scope'   => self::MODULE_NEWSLETTER,
                    'auto_detected'  => 1,
                    'disabled'       => 0,
                ]);
            } catch (\Throwable $e) {
                if (strpos($e->getMessage(), 'Duplicate') === false && strpos($e->getMessage(), '1062') === false) {
                    error_log('Seismo newsletter type pending insert: ' . $e->getMessage());
                }
                continue;
            }

            if ($id <= 0) {
                continue;
            }

            ++$created;
            $known[] = [
                'match_type'     => $matchType,
                'match_value'    => $matchValue,
                'subject_filter' => $subjectFilter,
                'module_scope'   => self::MODULE_NEWSLETTER,
                'auto_detected'  => 1,
                'disabled'       => 0,
            ];
        }

        return $created;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function filterConfirmedForModule(array $rows, string $moduleScope): array
    {
        $scope = self::normalizeModuleScope($moduleScope);
        $out   = [];
        foreach ($rows as $row) {
            if (!empty($row['disabled']) || !empty($row['auto_detected'])) {
                continue;
            }
            if (self::rowModuleScope($row) !== $scope) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Best confirmed newsletter row for copying match_type / match_value onto a proposal.
     *
     * @param list<array<string, mixed>> $newsletterRows confirmed newsletter subscriptions only
     * @return array<string, mixed>|null
     */
    public static function findAnchorNewsletterSubscription(string $fromEmail, array $newsletterRows): ?array
    {
        $from = trim($fromEmail);
        if ($from === '') {
            return null;
        }

        $bestScore = -1;
        $bestRow   = null;
        foreach ($newsletterRows as $row) {
            $mt = (string)($row['match_type'] ?? '');
            $mv = (string)($row['match_value'] ?? '');
            if (!self::matchesAddress($from, $mt, $mv)) {
                continue;
            }
            $score = $mt === 'email' ? 20 : 10;
            if (self::normalizeSubjectFilter($row['subject_filter'] ?? null) !== '') {
                $score += 2;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRow   = $row;
            }
        }

        return $bestRow;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function subscriptionProposalExists(
        array $rows,
        string $matchType,
        string $matchValue,
        string $subjectFilter,
        string $moduleScope,
    ): bool {
        $subjectFilter = self::normalizeSubjectFilter($subjectFilter);
        $moduleScope   = self::normalizeModuleScope($moduleScope);
        $matchType     = strtolower(trim($matchType));
        $matchValue    = $matchType === 'email'
            ? strtolower(trim($matchValue))
            : strtolower(ltrim(trim($matchValue), '@'));

        foreach ($rows as $row) {
            if (self::rowModuleScope($row) !== $moduleScope) {
                continue;
            }
            if ((string)($row['match_type'] ?? '') !== $matchType) {
                continue;
            }
            if (strtolower(trim((string)($row['match_value'] ?? ''))) !== $matchValue) {
                continue;
            }
            if (self::normalizeSubjectFilter($row['subject_filter'] ?? null) === $subjectFilter) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{
     *   match_type: string,
     *   match_value: string,
     *   display_name: string,
     *   category?: string|null,
     *   disabled?: int|bool,
     *   show_in_magnitu?: int|bool,
     *   strip_listing_boilerplate?: int|bool,
     *   auto_detected?: int|bool,
     *   unsubscribe_url?: string|null,
     *   unsubscribe_mailto?: string|null,
     *   unsubscribe_one_click?: int|bool,
     * } $data
     */
    public function insert(array $data): int
    {
        $this->assertNotSatellite();
        [$matchType, $matchValue, $displayName] = $this->normalizeMatchFields($data);
        $showMagnitu  = array_key_exists('show_in_magnitu', $data)
            ? (!empty($data['show_in_magnitu']) ? 1 : 0)
            : 1;
        $stripListing = !empty($data['strip_listing_boilerplate']) ? 1 : 0;
        $hydrateWebview = !empty($data['hydrate_webview']) ? 1 : 0;
        $autoDetected = !empty($data['auto_detected']) ? 1 : 0;
        $bodyProcessor = self::normalizeBodyProcessor($data['body_processor'] ?? null);
        $cleanupConfig = !empty($data['cleanup_config']) ? trim((string)$data['cleanup_config']) : null;
        $subjectFilter = self::normalizeSubjectFilter($data['subject_filter'] ?? null);
        $digestSplitConfig = null;

        $t   = entryTable('email_subscriptions');
        $moduleScope = self::normalizeModuleScope($data['module_scope'] ?? self::MODULE_MAIL);

        $sql = "INSERT INTO {$t} (
            match_type, match_value, display_name, subject_filter, category, module_scope, disabled, show_in_magnitu, strip_listing_boilerplate,
            hydrate_webview, body_processor, cleanup_config, digest_split_config, auto_detected, unsubscribe_url, unsubscribe_mailto, unsubscribe_one_click,
            item_count
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $matchType,
            $matchValue,
            $displayName,
            $subjectFilter,
            $data['category'] ?? null,
            $moduleScope,
            !empty($data['disabled']) ? 1 : 0,
            $showMagnitu,
            $stripListing,
            $hydrateWebview,
            $bodyProcessor,
            $cleanupConfig,
            $digestSplitConfig,
            $autoDetected,
            $data['unsubscribe_url'] ?? null,
            $data['unsubscribe_mailto'] ?? null,
            !empty($data['unsubscribe_one_click']) ? 1 : 0,
        ]);        $newId = (int)$this->pdo->lastInsertId();
        (new SourceLogRepository($this->pdo))->appendQuietly(
            SourceLogRepository::KIND_MAIL,
            $newId,
            $displayName
        );

        return $newId;
    }

    private function insertPendingEmail(string $email, string $fromName): int
    {
        try {
            return $this->insert([
                'match_type'    => 'email',
                'match_value'   => $email,
                'display_name'  => $fromName !== '' ? $fromName : $email,
                'module_scope'  => self::MODULE_MAIL,
                'auto_detected' => 1,
                'disabled'      => 0,
            ]);
        } catch (\Throwable $e) {
            // Unique (match_type, match_value) — another worker may have inserted first.
            if (strpos($e->getMessage(), 'Duplicate') === false && strpos($e->getMessage(), '1062') === false) {
                error_log('Seismo email_subscriptions pending email insert: ' . $e->getMessage());
            }

            return 0;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $this->assertNotSatellite();
        if ($id <= 0) {
            throw new \InvalidArgumentException('Invalid subscription id.');
        }
        $existing = $this->findById($id);
        if ($existing === null) {
            throw new \InvalidArgumentException('Subscription not found.');
        }
        [$matchType, $matchValue, $displayName] = $this->normalizeMatchFields($data, $existing);
        $disabled = array_key_exists('disabled', $data)
            ? (!empty($data['disabled']) ? 1 : 0)
            : (int)($existing['disabled'] ?? 0);
        $showMagnitu = array_key_exists('show_in_magnitu', $data)
            ? (!empty($data['show_in_magnitu']) ? 1 : 0)
            : (int)($existing['show_in_magnitu'] ?? 1);
        $stripListing = array_key_exists('strip_listing_boilerplate', $data)
            ? (!empty($data['strip_listing_boilerplate']) ? 1 : 0)
            : (int)($existing['strip_listing_boilerplate'] ?? 0);
        $hydrateWebview = array_key_exists('hydrate_webview', $data)
            ? (!empty($data['hydrate_webview']) ? 1 : 0)
            : (int)($existing['hydrate_webview'] ?? 0);
        $bodyProcessor = array_key_exists('body_processor', $data)
            ? self::normalizeBodyProcessor($data['body_processor'])
            : self::normalizeBodyProcessor($existing['body_processor'] ?? null);
        $cleanupConfig = array_key_exists('cleanup_config', $data)
            ? (!empty($data['cleanup_config']) ? trim((string)$data['cleanup_config']) : null)
            : ($existing['cleanup_config'] ?? null);
        $subjectFilter = array_key_exists('subject_filter', $data)
            ? self::normalizeSubjectFilter($data['subject_filter'])
            : self::normalizeSubjectFilter($existing['subject_filter'] ?? null);
        $digestSplitConfig = null;

        $t   = entryTable('email_subscriptions');
        $moduleScope = array_key_exists('module_scope', $data)
            ? self::normalizeModuleScope($data['module_scope'])
            : self::rowModuleScope($existing);

        $sql = "UPDATE {$t} SET
            match_type = ?,
            match_value = ?,
            display_name = ?,
            subject_filter = ?,
            category = ?,
            module_scope = ?,
            disabled = ?,
            show_in_magnitu = ?,
            strip_listing_boilerplate = ?,
            hydrate_webview = ?,
            body_processor = ?,
            cleanup_config = ?,
            digest_split_config = ?,
            split_drift = 0,
            auto_detected = 0,
            unsubscribe_url = ?,
            unsubscribe_mailto = ?,
            unsubscribe_one_click = ?
            WHERE id = ? AND removed_at IS NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $matchType,
            $matchValue,
            $displayName,
            $subjectFilter,
            $data['category'] ?? $existing['category'],
            $moduleScope,
            $disabled,
            $showMagnitu,
            $stripListing,
            $hydrateWebview,
            $bodyProcessor,
            $cleanupConfig,
            $digestSplitConfig,
            $data['unsubscribe_url'] ?? $existing['unsubscribe_url'],
            $data['unsubscribe_mailto'] ?? $existing['unsubscribe_mailto'],
            array_key_exists('unsubscribe_one_click', $data)
                ? (!empty($data['unsubscribe_one_click']) ? 1 : 0)
                : (int)($existing['unsubscribe_one_click'] ?? 0),
            $id,
        ]);
    }

    public function updateSplitDrift(int $id, bool $drift): void
    {
        if ($id <= 0) {
            return;
        }
        $t = entryTable('email_subscriptions');
        $stmt = $this->pdo->prepare("UPDATE {$t} SET split_drift = ? WHERE id = ?");
        $stmt->execute([$drift ? 1 : 0, $id]);
    }

    public function setModuleScope(int $id, string $moduleScope): void
    {
        $this->assertNotSatellite();
        if ($id <= 0) {
            throw new \InvalidArgumentException('Invalid subscription id.');
        }
        $scope = self::normalizeModuleScope($moduleScope);
        $t   = entryTable('email_subscriptions');
        $sql = "UPDATE {$t} SET module_scope = ? WHERE id = ? AND removed_at IS NULL AND auto_detected = 0 LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$scope, $id]);
        if ($stmt->rowCount() === 0) {
            throw new \InvalidArgumentException('Subscription not found.');
        }
    }

    public static function normalizeBodyProcessor(mixed $value): ?string
    {
        $key = trim((string)($value ?? ''));
        if ($key === '') {
            return null;
        }
        if (!in_array($key, EmailBodyProcessorRegistry::knownKeys(), true)) {
            throw new \InvalidArgumentException('Unknown body processor: ' . $key);
        }

        return $key;
    }

    public function softDelete(int $id): void
    {
        $this->assertNotSatellite();
        if ($id <= 0) {
            throw new \InvalidArgumentException('Invalid subscription id.');
        }
        $t   = entryTable('email_subscriptions');
        $sql = "UPDATE {$t} SET removed_at = UTC_TIMESTAMP() WHERE id = ? AND removed_at IS NULL LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
    }

    public function setDisabled(int $id, bool $disabled): void
    {
        $this->assertNotSatellite();
        if ($id <= 0) {
            throw new \InvalidArgumentException('Invalid subscription id.');
        }
        $t   = entryTable('email_subscriptions');
        $sql = "UPDATE {$t} SET disabled = ? WHERE id = ? AND removed_at IS NULL LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$disabled ? 1 : 0, $id]);
    }

    /**
     * @param array<string, mixed> $data
     * @param ?array<string, mixed> $existing
     * @return array{0: string, 1: string, 2: string}
     */
    private function normalizeMatchFields(array $data, ?array $existing = null): array
    {
        $matchType = array_key_exists('match_type', $data)
            ? trim((string)$data['match_type'])
            : (string)($existing['match_type'] ?? '');
        if (!in_array($matchType, ['domain', 'email'], true)) {
            throw new \InvalidArgumentException('match_type must be domain or email.');
        }
        $matchValue = array_key_exists('match_value', $data)
            ? trim((string)$data['match_value'])
            : (string)($existing['match_value'] ?? '');
        if ($matchValue === '') {
            throw new \InvalidArgumentException('match_value is required.');
        }
        if ($matchType === 'domain') {
            $matchValue = ltrim(strtolower($matchValue), '@');
        } else {
            $matchValue = strtolower($matchValue);
        }
        $displayName = array_key_exists('display_name', $data)
            ? trim((string)$data['display_name'])
            : (string)($existing['display_name'] ?? '');
        if ($displayName === '') {
            $displayName = $matchValue;
        }

        return [$matchType, $matchValue, $displayName];
    }

    /**
     * Distinct non-empty categories already assigned on subscriptions.
     *
     * @return list<string>
     */
    public function listUsedCategories(): array
    {
        $t = entryTable('email_subscriptions');
        $sql = "SELECT DISTINCT TRIM(category) AS category FROM {$t}
            WHERE removed_at IS NULL
              AND category IS NOT NULL
              AND TRIM(category) <> ''
            ORDER BY category ASC
            LIMIT 100";
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            return [];
        }
        $out = [];
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $c = trim((string)($row['category'] ?? ''));
            if ($c !== '') {
                $out[] = $c;
            }
        }

        return $out;
    }

    private function assertNotSatellite(): void
    {
        if (isSatellite()) {
            throw new \RuntimeException('Satellite mode — email subscriptions are managed on the mothership only.');
        }
    }
}
