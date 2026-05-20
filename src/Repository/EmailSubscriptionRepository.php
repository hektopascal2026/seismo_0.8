<?php

declare(strict_types=1);

namespace Seismo\Repository;

use PDO;
use Seismo\Core\Mail\EmailBodyProcessorRegistry;

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

    /**
     * Inbox card flags from the best matching non-disabled, confirmed row.
     *
     * @param list<array<string, mixed>> $subscriptionRows
     * @return array{display_name: ?string, strip_listing_boilerplate: bool}
     */
    public static function resolveSubscriptionUiForFromEmail(string $fromEmail, array $subscriptionRows): array
    {
        $from = trim($fromEmail);
        if ($from === '') {
            return ['display_name' => null, 'strip_listing_boilerplate' => false];
        }
        $bestRank  = 0;
        $bestName  = null;
        $bestStrip = false;
        foreach ($subscriptionRows as $row) {
            if (!empty($row['disabled']) || !empty($row['auto_detected'])) {
                continue;
            }
            $mt = (string)($row['match_type'] ?? '');
            $mv = (string)($row['match_value'] ?? '');
            if (!self::matchesAddress($from, $mt, $mv)) {
                continue;
            }
            $rank = $mt === 'email' ? 2 : 1;
            $name = trim((string)($row['display_name'] ?? ''));
            if ($rank > $bestRank) {
                $bestRank  = $rank;
                $bestName  = $name !== '' ? $name : null;
                $bestStrip = !empty($row['strip_listing_boilerplate']);

                continue;
            }
            if ($rank === $bestRank && ($bestName === null || $bestName === '') && $name !== '') {
                $bestName = $name;
            }
        }

        return [
            'display_name'              => $bestName,
            'strip_listing_boilerplate' => $bestStrip,
        ];
    }

    /**
     * @param list<array<string, mixed>> $subscriptionRows
     */
    public static function resolveDisplayNameForFromEmail(string $fromEmail, array $subscriptionRows): ?string
    {
        return self::resolveSubscriptionUiForFromEmail($fromEmail, $subscriptionRows)['display_name'];
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
     * Gmail-ingest proposals awaiting review.
     *
     * @return list<array<string, mixed>>
     */
    public function listPending(int $limit, int $offset): array
    {
        return $this->listByAutoDetected(true, $limit, $offset);
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
        $seenDomains = [];

        foreach ($ingestRows as $row) {
            $from = strtolower(trim((string)($row['from_email'] ?? '')));
            if ($from === '') {
                continue;
            }
            $domain = self::extractDomainFromEmail($from);
            if ($domain === null || isset($seenDomains[$domain])) {
                continue;
            }
            $seenDomains[$domain] = true;

            if (self::matchesAnyRow($from, $known)) {
                continue;
            }

            $fromName = trim((string)($row['from_name'] ?? ''));
            $id = $this->insertPendingDomain($domain, $fromName);
            if ($id <= 0) {
                continue;
            }
            ++$created;
            $known[] = [
                'match_type'    => 'domain',
                'match_value'   => $domain,
                'display_name'  => self::proposeDisplayName($fromName !== '' ? $fromName : null, $domain),
                'auto_detected' => 1,
            ];
        }

        return $created;
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
        $autoDetected = !empty($data['auto_detected']) ? 1 : 0;
        $bodyProcessor = self::normalizeBodyProcessor($data['body_processor'] ?? null);

        $t   = entryTable('email_subscriptions');
        $sql = "INSERT INTO {$t} (
            match_type, match_value, display_name, category, disabled, show_in_magnitu, strip_listing_boilerplate,
            body_processor, auto_detected, unsubscribe_url, unsubscribe_mailto, unsubscribe_one_click,
            item_count
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $matchType,
            $matchValue,
            $displayName,
            $data['category'] ?? null,
            !empty($data['disabled']) ? 1 : 0,
            $showMagnitu,
            $stripListing,
            $bodyProcessor,
            $autoDetected,
            $data['unsubscribe_url'] ?? null,
            $data['unsubscribe_mailto'] ?? null,
            !empty($data['unsubscribe_one_click']) ? 1 : 0,
        ]);

        $newId = (int)$this->pdo->lastInsertId();
        (new SourceLogRepository($this->pdo))->appendQuietly(
            SourceLogRepository::KIND_MAIL,
            $newId,
            $displayName
        );

        return $newId;
    }

    private function insertPendingDomain(string $domain, string $fromName): int
    {
        try {
            return $this->insert([
                'match_type'    => 'domain',
                'match_value'   => $domain,
                'display_name'  => self::proposeDisplayName($fromName !== '' ? $fromName : null, $domain),
                'auto_detected' => 1,
                'disabled'      => 0,
            ]);
        } catch (\Throwable $e) {
            // Unique (match_type, match_value) — another worker may have inserted first.
            if (strpos($e->getMessage(), 'Duplicate') === false && strpos($e->getMessage(), '1062') === false) {
                error_log('Seismo email_subscriptions pending insert: ' . $e->getMessage());
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
        $bodyProcessor = array_key_exists('body_processor', $data)
            ? self::normalizeBodyProcessor($data['body_processor'])
            : self::normalizeBodyProcessor($existing['body_processor'] ?? null);

        $t   = entryTable('email_subscriptions');
        $sql = "UPDATE {$t} SET
            match_type = ?,
            match_value = ?,
            display_name = ?,
            category = ?,
            disabled = ?,
            show_in_magnitu = ?,
            strip_listing_boilerplate = ?,
            body_processor = ?,
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
            $data['category'] ?? $existing['category'],
            $disabled,
            $showMagnitu,
            $stripListing,
            $bodyProcessor,
            $data['unsubscribe_url'] ?? $existing['unsubscribe_url'],
            $data['unsubscribe_mailto'] ?? $existing['unsubscribe_mailto'],
            array_key_exists('unsubscribe_one_click', $data)
                ? (!empty($data['unsubscribe_one_click']) ? 1 : 0)
                : (int)($existing['unsubscribe_one_click'] ?? 0),
            $id,
        ]);
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

    private function assertNotSatellite(): void
    {
        if (isSatellite()) {
            throw new \RuntimeException('Satellite mode — email subscriptions are managed on the mothership only.');
        }
    }
}
