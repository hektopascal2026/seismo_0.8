<?php
/**
 * Policy-driven data retention for the four entry families.
 *
 * Seismo runs on shared hosting (1–2 GB DB quotas are typical), so a
 * table that accrues raw HTML bodies and long descriptions will fill
 * the instance quota unless pruned. Per
 * `core-plugin-architecture.mdc`, every family repository ships a
 * `prune()` from day one; this service is the policy layer that decides
 * which family gets pruned how often, with what cutoff, and which rows
 * are protected.
 *
 * Public surface:
 *   - `pruneAll()`    — run retention for every family that has a
 *                       policy row. Called from the tail of
 *                       `refresh_cron.php`. Mothership only.
 *   - `previewAll()`  — return a per-family count of rows that
 *                       `pruneAll()` would delete, without deleting.
 *                       Drives the "dry run" button in the Retention
 *                       settings view. Safe on both satellite and
 *                       mothership (SELECT only).
 *
 * Policy storage:
 *   Each family's retention policy lives in one `system_config` row
 *   keyed `retention:<family>` (e.g. `retention:feed_items`). The
 *   value is a JSON document:
 *
 *       { "days": 180, "keep": ["favourited","high_score","labelled"] }
 *
 *   A `days` of `null` or `0` disables pruning for that family
 *   (unlimited retention). Missing row → built-in default from
 *   {@see self::DEFAULT_POLICIES}.
 *
 * Keep-predicates:
 *   The `keep` array uses the same token vocabulary as
 *   {@see RetentionPredicates}. Unknown tokens are silently ignored so
 *   adding a new predicate token in a future slice doesn't break
 *   existing admin-edited policies.
 *
 * Preview-vs-actual consistency:
 *   Each family repo's `prune()` and `dryRunPrune()` share the same
 *   `buildPruneWhere()` helper. This service passes identical
 *   `$olderThan` and `$keepPredicates` to both code paths, so the only
 *   legitimate drift between preview and actual counts comes from rows
 *   that arrive between the two calls (which is acceptable — the user
 *   gets an accurate estimate, not a contract).
 *
 * Satellite behaviour:
 *   `pruneAll()` short-circuits on satellites: entry-source tables are
 *   owned by the mothership and the runner-level guard in
 *   `refresh_cron.php` already prevents the call; this is defence in
 *   depth. `previewAll()` is safe to call on a satellite (it's SELECT
 *   only) but each family repo throws on `prune()` from a satellite,
 *   so previewing is the only thing a satellite can do — which is
 *   correct semantics.
 */

declare(strict_types=1);

namespace Seismo\Service;

use DateTimeImmutable;
use DateTimeZone;
use Seismo\Repository\CalendarEventRepository;
use Seismo\Repository\EmailRepository;
use Seismo\Repository\FeedItemRepository;
use Seismo\Repository\LexItemRepository;
use Seismo\Repository\SystemConfigRepository;

final class RetentionService
{
    /** Keep rows favourited (☆ / ★ in the dashboard) by the admin. */
    public const KEEP_FAVOURITED = 'favourited';

    /** Keep rows with a Magnitu-assigned `investigation_lead` or `important` score. */
    public const KEEP_HIGH_SCORE = 'high_score';

    /** Keep rows manually labelled in `magnitu_labels` — labels are training data. */
    public const KEEP_LABELLED = 'labelled';

    /** Default keep-predicate set applied to every family. */
    public const DEFAULT_KEEPS = [
        self::KEEP_FAVOURITED,
        self::KEEP_HIGH_SCORE,
        self::KEEP_LABELLED,
    ];

    /**
     * Family → built-in default policy. Matches the table published in
     * `core-plugin-architecture.mdc` ("Data retention"): 180 days for
     * feeds and emails, unlimited for legal text and Leg.
     *
     * @var array<string, array{days:?int, keep:list<string>}>
     */
    public const DEFAULT_POLICIES = [
        'feed_items'      => ['days' => 180,  'keep' => self::DEFAULT_KEEPS],
        'emails'          => ['days' => 180,  'keep' => self::DEFAULT_KEEPS],
        'lex_items'       => ['days' => null, 'keep' => self::DEFAULT_KEEPS],
        'calendar_events' => ['days' => null, 'keep' => self::DEFAULT_KEEPS],
    ];

    public function __construct(
        private SystemConfigRepository $config,
        private FeedItemRepository $feeds,
        private EmailRepository $emails,
        private LexItemRepository $lex,
        private CalendarEventRepository $calendar,
    ) {
    }

    /**
     * One-call wiring for the common case (cron + controller).
     */
    public static function boot(\PDO $pdo): self
    {
        return new self(
            new SystemConfigRepository($pdo),
            new FeedItemRepository($pdo),
            new EmailRepository($pdo),
            new LexItemRepository($pdo),
            new CalendarEventRepository($pdo),
        );
    }

    /**
     * Run retention across every family. Families with `days = null`
     * are skipped entirely (no SELECT, no DELETE). Returns a map of
     * `family => rows_deleted` so the caller can log / display the
     * outcome.
     *
     * @return array<string, int>
     */
    public function pruneAll(): array
    {
        if (isSatellite()) {
            return [];
        }
        $results = [];
        foreach (array_keys(self::DEFAULT_POLICIES) as $family) {
            $policy = $this->loadPolicy($family);
            if ($policy['days'] === null || $policy['days'] <= 0) {
                continue;
            }
            $cutoff = self::cutoff($policy['days']);
            $results[$family] = $this->runPrune($family, $cutoff, $policy['keep']);
        }
        return $results;
    }

    /**
     * Return the full preview grid used by the Retention settings page.
     * Every family is included, even those with `days = null`, so the
     * admin sees the current policy in one table. Skipped families
     * report `days: null` and `would_delete: null`; active families
     * report the concrete count `prune()` would remove today.
     *
     * @return array<string, array{days:?int, keep:list<string>, would_delete:?int}>
     */
    public function previewAll(): array
    {
        $out = [];
        foreach (array_keys(self::DEFAULT_POLICIES) as $family) {
            $policy = $this->loadPolicy($family);
            $wouldDelete = null;
            if ($policy['days'] !== null && $policy['days'] > 0) {
                $cutoff = self::cutoff($policy['days']);
                $wouldDelete = $this->runDryRun($family, $cutoff, $policy['keep']);
            }
            $out[$family] = [
                'days'         => $policy['days'],
                'keep'         => $policy['keep'],
                'would_delete' => $wouldDelete,
            ];
        }
        return $out;
    }

    /**
     * Load a family's retention policy, falling back to the built-in
     * default. Unknown keep-tokens in the stored row are filtered out
     * before returning — see {@see RetentionPredicates::forEntryType}
     * (which also ignores them, but dropping them here keeps the
     * policy shape honest for the Settings UI).
     *
     * @return array{days:?int, keep:list<string>}
     */
    public function loadPolicy(string $family): array
    {
        $default = self::DEFAULT_POLICIES[$family] ?? ['days' => null, 'keep' => self::DEFAULT_KEEPS];
        $stored  = $this->config->getJson(SystemConfigRepository::RETENTION_PREFIX . $family, []);
        if ($stored === []) {
            return $default;
        }
        $rawDays = $stored['days'] ?? null;
        $days    = $rawDays === null ? null : max(0, (int)$rawDays);
        if ($days === 0) {
            $days = null;
        }
        $keep = $stored['keep'] ?? $default['keep'];
        if (!is_array($keep)) {
            $keep = $default['keep'];
        }
        $allowed = [self::KEEP_FAVOURITED, self::KEEP_HIGH_SCORE, self::KEEP_LABELLED];
        $keep    = array_values(array_intersect(
            array_map('strval', $keep),
            $allowed
        ));

        return ['days' => $days, 'keep' => $keep];
    }

    /**
     * Persist a policy row. Passing `days = null` or `0` disables
     * pruning for the family.
     *
     * @param list<string> $keep
     */
    public function savePolicy(string $family, ?int $days, array $keep): void
    {
        if (!array_key_exists($family, self::DEFAULT_POLICIES)) {
            throw new \InvalidArgumentException('Unknown retention family: ' . $family);
        }
        $payload = [
            'days' => $days !== null && $days > 0 ? $days : null,
            'keep' => array_values(array_intersect(
                array_map('strval', $keep),
                [self::KEEP_FAVOURITED, self::KEEP_HIGH_SCORE, self::KEEP_LABELLED]
            )),
        ];
        $this->config->setJson(SystemConfigRepository::RETENTION_PREFIX . $family, $payload);
    }

    /**
     * @param list<string> $keep
     */
    private function runPrune(string $family, DateTimeImmutable $cutoff, array $keep): int
    {
        return match ($family) {
            'feed_items'      => $this->feeds->prune($cutoff, $keep),
            'emails'          => $this->emails->prune($cutoff, $keep),
            'lex_items'       => $this->lex->prune($cutoff, $keep),
            'calendar_events' => $this->calendar->prune($cutoff, $keep),
            default           => 0,
        };
    }

    /**
     * @param list<string> $keep
     */
    private function runDryRun(string $family, DateTimeImmutable $cutoff, array $keep): int
    {
        return match ($family) {
            'feed_items'      => $this->feeds->dryRunPrune($cutoff, $keep),
            'emails'          => $this->emails->dryRunPrune($cutoff, $keep),
            'lex_items'       => $this->lex->dryRunPrune($cutoff, $keep),
            'calendar_events' => $this->calendar->dryRunPrune($cutoff, $keep),
            default           => 0,
        };
    }

    private static function cutoff(int $days): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . $days . ' days');
    }
}
