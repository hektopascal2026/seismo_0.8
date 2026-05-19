<?php

declare(strict_types=1);

namespace Seismo\Service;

use DateTimeImmutable;
use DateTimeZone;
use Seismo\Config\CalendarConfigStore;
use Seismo\Config\LexConfigStore;
use Seismo\Core\Scoring\ScoringService;
use Seismo\Repository\CalendarEventRepository;
use Seismo\Repository\CronMutexRepository;
use Seismo\Repository\EmailIngestRepository;
use Seismo\Repository\EntryScoreRepository;
use Seismo\Repository\FeedItemRepository;
use Seismo\Repository\LexItemRepository;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Repository\PluginRunLogRepository;

/**
 * Orchestrates plugin execution. Shared by:
 *   - Master cron (refresh_cron.php) — calls runAll() with throttling.
 *   - Web "Refresh all" — Diagnostics uses runAll(force: true); timeline Refresh
 *     uses runAll(force: true, skipLexPlugins: true) so HTTP stays within timeouts.
 *   - Per-plugin refresh buttons — calls runPlugin($id, force: true).
 *   - Diagnostics "Test" button — calls testPlugin($id) (no persistence).
 *
 * ## Master Cron pattern
 *
 * `refresh_cron.php` is the ONLY cron job a shared-host admin needs to register.
 * It may fire every 5 minutes (Plesk default granularity). We do NOT want every
 * plugin hitting its upstream every 5 minutes, so runAll() consults each
 * plugin's {@see SourceFetcherInterface::getMinIntervalSeconds()} and skips
 * plugins whose last successful run (`ok` or `warn`) in `plugin_run_log` is fresher than that.
 *
 * Throttle skips use {@see PluginRunResult::throttleSkipped()} — they are **not**
 * persisted to `plugin_run_log` (cron stdout only). User-initiated refresh paths
 * call with `$force = true` to bypass the throttle.
 *
 * Rows ARE persisted for every non-throttle outcome (ok, warn, error, skipped-
 * because-satellite, skipped-because-disabled-in-config). Those are the rows
 * diagnostics displays.
 *
 * Slice 4: {@see CoreRunner} runs first (RSS/Substack, scraper, IMAP mail),
 * then registered plugins. Same `runAll()` entry point for web + CLI cron.
 *
 * ## Refresh mutex (RSS/scraper chunk cursors)
 *
 * Web refresh and `refresh_cron.php` share chunked ingest state in `system_config`.
 * {@see CronMutexRepository} serialises overlapping runs: cron holds the lock for the
 * whole script (including retention); `runAll(..., true)` skips an inner acquire/release.
 * Module refresh buttons acquire the same lock so they cannot race cron or each other.
 *
 * After ingest, {@see recipeRescoreAfterIngest()} runs the deterministic recipe
 * scorer ({@see ScoringService}) so new rows get `entry_scores` without waiting
 * for a Magnitu `magnitu_recipe` POST.
 */
final class RefreshAllService
{
    public function __construct(
        private readonly PluginRegistry $registry,
        private readonly PluginRunLogRepository $runLog,
        private readonly LexItemRepository $lexItems,
        private readonly CalendarEventRepository $calendarEvents,
        private readonly LexConfigStore $lexConfig,
        private readonly CalendarConfigStore $calendarConfig,
        private readonly CoreRunner $coreRunner,
        private readonly SystemConfigRepository $systemConfig,
        private readonly EntryScoreRepository $entryScores,
        private readonly \PDO $pdo,
    ) {
    }

    /**
     * Run core fetchers, then every registered plugin.
     *
     * @param bool $force If true, ignore the per-plugin throttle (web "Refresh all").
     * @param bool $skipLexPlugins If true, skip plugins with {@see SourceFetcherInterface::getEntryType()}
     *                            `lex_item` (timeline toolbar Refresh — Lex stays on Diagnostics / cron).
     * @param bool $refreshMutexHeldExternally When true, do not acquire/release {@see CronMutexRepository}
     *                                         (caller already holds the lock — used by `refresh_cron.php`).
     * @return array<string, PluginRunResult>
     * @throws RefreshMutexBusyException When another ingest holds the advisory lock (web paths only).
     */
    public function runAll(bool $force = false, bool $skipLexPlugins = false, bool $refreshMutexHeldExternally = false): array
    {
        /** @var array<string, PluginRunResult> */
        return $this->executeUnderRefreshMutex($refreshMutexHeldExternally, function () use ($force, $skipLexPlugins): array {
            $results = $this->coreRunner->runAll($force);
            foreach ($this->registry->all() as $id => $plugin) {
                if ($skipLexPlugins && $plugin->getEntryType() === 'lex_item') {
                    $results[$id] = PluginRunResult::skipped(
                        'Skipped — timeline refresh omits Lex sources (use Diagnostics or refresh_cron.php).',
                        false
                    );
                    continue;
                }
                $results[$id] = $this->runOne($plugin, $force);
            }

            $this->recipeRescoreAfterIngest();

            return $results;
        });
    }

    /**
     * Feeds page: RSS/Substack + Parliament press (not scraper or mail).
     *
     * @return array<string, PluginRunResult>
     * @throws RefreshMutexBusyException
     */
    public function runFeedModuleCoreFetchers(bool $force = true): array
    {
        /** @var array<string, PluginRunResult> */
        return $this->executeUnderRefreshMutex(false, function () use ($force): array {
            $results = [
                CoreRunner::ID_RSS         => $this->coreRunner->runOne(CoreRunner::ID_RSS, $force),
                CoreRunner::ID_PARL_PRESS => $this->coreRunner->runOne(CoreRunner::ID_PARL_PRESS, $force),
            ];
            $this->recipeRescoreAfterIngest();

            return $results;
        });
    }

    /**
     * @return array<string, PluginRunResult>
     * @throws RefreshMutexBusyException
     */
    public function runScraperModuleCoreFetcher(bool $force = true): array
    {
        /** @var array<string, PluginRunResult> */
        return $this->executeUnderRefreshMutex(false, function () use ($force): array {
            $results = [
                CoreRunner::ID_SCRAPER => $this->coreRunner->runOne(CoreRunner::ID_SCRAPER, $force),
            ];
            $this->recipeRescoreAfterIngest();

            return $results;
        });
    }

    /**
     * @return array<string, PluginRunResult>
     */
    public function runMailModuleCoreFetcher(bool $force = true): array
    {
        $results = [
            CoreRunner::ID_MAIL => $this->coreRunner->runOne(CoreRunner::ID_MAIL, $force),
        ];
        $this->recipeRescoreAfterIngest();

        return $results;
    }

    /**
     * Every registered plugin with {@see SourceFetcherInterface::getEntryType()} `lex_item`
     * (Fedlex, EUR-Lex, DE/FR, Jus sources).
     *
     * @return array<string, PluginRunResult>
     */
    public function runAllLexItemPlugins(bool $force = true): array
    {
        $results = [];
        foreach ($this->registry->all() as $id => $plugin) {
            if ($plugin->getEntryType() !== 'lex_item') {
                continue;
            }
            $results[$id] = $this->runOne($plugin, $force);
        }
        $this->recipeRescoreAfterIngest();

        return $results;
    }

    /**
     * @param array<string, PluginRunResult> $results
     *
     * @return array{summary: string, all_failed: bool}
     */
    public static function aggregatePluginRunResults(array $results): array
    {
        $okCount = 0;
        $warnCount = 0;
        $errCount = 0;
        $itemsTotal = 0;
        foreach ($results as $r) {
            if ($r->isOk()) {
                $okCount++;
                $itemsTotal += $r->count;
            } elseif ($r->status === 'warn') {
                $warnCount++;
                $itemsTotal += $r->count;
            } elseif ($r->status === 'error') {
                $errCount++;
            }
        }
        $skipped = count($results) - $okCount - $warnCount - $errCount;
        $summary = sprintf(
            '%d ok, %d partial (%d items), %d error, %d skipped',
            $okCount,
            $warnCount,
            $itemsTotal,
            $errCount,
            $skipped
        );
        $n = count($results);
        $allFailed = $n > 0 && $errCount === $n;

        return ['summary' => $summary, 'all_failed' => $allFailed];
    }

    /**
     * Adds plugin ids / short reasons after {@see aggregatePluginRunResults()} so operators
     * see which upstream failed while counts stay headline-sized.
     *
     * Skips with identical messages are collapsed to `id1, id2: message` (e.g. many Lex plugins
     * skipped by timeline refresh share one explanation).
     *
     * @param array<string, PluginRunResult> $results
     */
    public static function aggregateResultDetailAppendix(array $results, int $maxMessageLen = 160): string
    {
        $errors = [];
        $warns  = [];
        /** @var array<string, list<string>> */
        $skippedByMsg = [];

        foreach ($results as $id => $r) {
            $snippet = self::truncateAggregateDetailMessage((string)($r->message ?? ''), $maxMessageLen);
            if ($r->status === 'error') {
                $errors[] = $snippet !== '' ? $id . ': ' . $snippet : $id;
            } elseif ($r->status === 'warn') {
                $warns[] = $snippet !== '' ? $id . ': ' . $snippet : $id;
            } elseif ($r->status === 'skipped') {
                $skippedByMsg[$snippet] ??= [];
                $skippedByMsg[$snippet][] = $id;
            }
        }

        $chunks = [];
        if ($errors !== []) {
            $chunks[] = 'Error — ' . implode('; ', $errors);
        }
        if ($warns !== []) {
            $chunks[] = 'Partial — ' . implode('; ', $warns);
        }
        if ($skippedByMsg !== []) {
            $skippedParts = [];
            foreach ($skippedByMsg as $msg => $ids) {
                sort($ids);
                $idList = implode(', ', $ids);
                $skippedParts[] = $msg !== '' ? $idList . ': ' . $msg : $idList;
            }
            $chunks[] = 'Skipped — ' . implode('; ', $skippedParts);
        }

        if ($chunks === []) {
            return '';
        }

        return ' ' . implode(' ', $chunks);
    }

    /**
     * @internal Used by aggregateResultDetailAppendix only.
     */
    private static function truncateAggregateDetailMessage(string $message, int $maxLen): string
    {
        $plain = trim(preg_replace('/\s+/', ' ', $message) ?? '');
        if ($plain === '' || $maxLen < 4) {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($plain, 'UTF-8') <= $maxLen) {
                return $plain;
            }

            return rtrim(mb_substr($plain, 0, max(1, $maxLen - 1), 'UTF-8')) . '…';
        }
        if (strlen($plain) <= $maxLen) {
            return $plain;
        }

        return rtrim(substr($plain, 0, max(1, $maxLen - 1))) . '…';
    }

    /**
     * Sets {@see $_SESSION} success/error from an aggregate plugin run (module refresh buttons).
     *
     * @param array<string, PluginRunResult> $results
     */
    public static function applySessionFlashForAggregateResults(array $results, string $label): void
    {
        $agg = self::aggregatePluginRunResults($results);
        $detail = self::aggregateResultDetailAppendix($results);
        $msg = $label . ': ' . $agg['summary'] . '.' . $detail;
        if ($agg['all_failed']) {
            $_SESSION['error'] = $msg;
        } else {
            $_SESSION['success'] = $msg;
        }
    }

    /**
     * Run a single plugin by id.
     *
     * @param bool $force Defaults to true because the web single-plugin refresh
     *                    button is always explicit human intent.
     */
    public function runPlugin(string $id, bool $force = true): PluginRunResult
    {
        $plugin = $this->registry->get($id);
        if ($plugin === null) {
            return PluginRunResult::error('Plugin "' . $id . '" is not registered.');
        }

        $result = $this->runOne($plugin, $force);
        $this->recipeRescoreAfterIngest();

        return $result;
    }

    /**
     * Dry-run for diagnostics: call fetch() without writing. Throttle is
     * ignored; no plugin_run_log row is written. Returns the first $peek rows.
     *
     * @return array{items: list<array<string, mixed>>, error: ?string, count: int}
     */
    public function testPlugin(string $id, int $peek = 5): array
    {
        $peek = max(1, min($peek, 20));
        $plugin = $this->registry->get($id);
        if ($plugin === null) {
            return ['items' => [], 'error' => 'Plugin "' . $id . '" is not registered.', 'count' => 0];
        }

        if (isSatellite()) {
            return ['items' => [], 'error' => 'Satellite mode — entry plugins do not run here.', 'count' => 0];
        }

        $block = $this->resolveConfigBlock($plugin);

        try {
            $rows = $plugin->fetch($block);
        } catch (\Throwable $e) {
            error_log('Seismo testPlugin ' . $plugin->getIdentifier() . ': ' . $e->getMessage());

            return ['items' => [], 'error' => $e->getMessage(), 'count' => 0];
        }

        return [
            'items' => array_slice($rows, 0, $peek),
            'error' => null,
            'count' => count($rows),
        ];
    }

    private function runOne(SourceFetcherInterface $plugin, bool $force): PluginRunResult
    {
        $id = $plugin->getIdentifier();

        if (isSatellite()) {
            $result = PluginRunResult::skipped('Satellite mode — entry plugins do not run here.');
            $this->record($id, $result, 0);

            return $result;
        }

        if (!$force && $this->isThrottled($plugin)) {
            $msg = 'Throttled — last successful run is fresher than ' . $plugin->getMinIntervalSeconds() . 's.';

            return PluginRunResult::throttleSkipped($msg);
        }

        $block = $this->resolveConfigBlock($plugin);
        if (empty($block['enabled'])) {
            $result = PluginRunResult::skipped('Disabled in config.');
            $this->record($id, $result, 0);

            return $result;
        }

        $start = (int)(microtime(true) * 1000);
        try {
            $rows = $plugin->fetch($block);
            $count = $this->persist($plugin, $rows);
            $result = PluginRunResult::ok($count);
        } catch (\Throwable $e) {
            error_log('Seismo plugin ' . $id . ': ' . $e->getMessage());
            $result = PluginRunResult::error($e->getMessage());
        }
        $duration = max(0, (int)(microtime(true) * 1000) - $start);
        $this->record($id, $result, $duration);

        return $result;
    }

    private function isThrottled(SourceFetcherInterface $plugin): bool
    {
        $minInterval = $plugin->getMinIntervalSeconds();
        if ($minInterval <= 0) {
            return false;
        }
        $last = $this->runLog->lastSuccessfulRunAt($plugin->getIdentifier());
        if ($last === null) {
            return false;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return ($now->getTimestamp() - $last->getTimestamp()) < $minInterval;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveConfigBlock(SourceFetcherInterface $plugin): array
    {
        $key = $plugin->getConfigKey();

        return match ($plugin->getEntryType()) {
            'lex_item'       => $this->resolveLexBlock($key),
            'calendar_event' => (array)($this->calendarConfig->load()[$key] ?? []),
            default          => [],
        };
    }

    /**
     * Lex plugins share a global `jus_banned_words` list (legacy 0.4 surface).
     * Inject it only for the Jus sources so plugins can filter titles without
     * each needing to round-trip the entire LexConfigStore.
     *
     * @return array<string, mixed>
     */
    private function resolveLexBlock(string $key): array
    {
        $all = $this->lexConfig->load();
        $block = (array)($all[$key] ?? []);
        if (in_array($key, \Seismo\Repository\TimelineFilter::JUS_LEX_SOURCES, true)) {
            $bw = $all['jus_banned_words'] ?? [];
            $block['jus_banned_words'] = is_array($bw) ? $bw : [];
        }
        return $block;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function persist(SourceFetcherInterface $plugin, array $rows): int
    {
        return match ($plugin->getEntryType()) {
            'lex_item'       => $this->lexItems->upsertBatch($rows),
            'calendar_event' => $this->calendarEvents->upsertBatch($rows),
            default          => throw new \RuntimeException('No repository wired for entry_type "' . $plugin->getEntryType() . '"'),
        };
    }

    private function record(string $id, PluginRunResult $result, int $durationMs): void
    {
        if (!$result->persistToPluginRunLog) {
            return;
        }
        try {
            $this->runLog->record($id, $result, $durationMs);
        } catch (\Throwable $e) {
            error_log('Seismo plugin_run_log write failed: ' . $e->getMessage());
        }
    }

    /**
     * Run a single core fetcher (`core:rss`, `core:parl_press`, `core:scraper`, `core:mail`).
     *
     * @throws RefreshMutexBusyException When locking {@see CoreRunner::ID_RSS} or {@see CoreRunner::ID_SCRAPER} while busy.
     */
    public function runCoreFetcher(string $coreId, bool $force = true, bool $refreshMutexHeldExternally = false): PluginRunResult
    {
        $chunkStateCore = in_array($coreId, [CoreRunner::ID_RSS, CoreRunner::ID_SCRAPER], true);

        /** @var PluginRunResult */
        return $this->executeUnderRefreshMutex($refreshMutexHeldExternally || !$chunkStateCore, function () use ($coreId, $force): PluginRunResult {
            $result = $this->coreRunner->runOne($coreId, $force);
            $this->recipeRescoreAfterIngest();

            return $result;
        });
    }

    /**
     * Serialize ingest that mutates chunked RSS/scraper cursor rows in `system_config`,
     * so web refresh cannot race `refresh_cron.php` (or another tab).
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function executeUnderRefreshMutex(bool $mutexHeldExternally, callable $fn): mixed
    {
        if (isSatellite() || $mutexHeldExternally) {
            return $fn();
        }
        $mutex = new CronMutexRepository($this->pdo);
        if (!$mutex->tryAcquireRefreshCron()) {
            throw new RefreshMutexBusyException(RefreshMutexBusyException::defaultMessage());
        }
        try {
            return $fn();
        } finally {
            try {
                $mutex->releaseRefreshCron();
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Best-effort recipe scoring for rows without a Magnitu score. Does not
     * affect plugin exit codes or flash messages when it fails.
     */
    private function recipeRescoreAfterIngest(): void
    {
        ScoringService::rescoreStoredRecipeBestEffortForRepos($this->systemConfig, $this->entryScores);
    }

    /**
     * Convenience factory so controllers and cron don't repeat the wiring.
     */
    public static function boot(\PDO $pdo): self
    {
        $runLog       = new PluginRunLogRepository($pdo);
        $systemConfig = new SystemConfigRepository($pdo);

        return new self(
            new PluginRegistry(),
            $runLog,
            new LexItemRepository($pdo),
            new CalendarEventRepository($pdo),
            new LexConfigStore(),
            new CalendarConfigStore(),
            new CoreRunner(
                new FeedItemRepository($pdo),
                $runLog,
                $systemConfig,
                new EmailIngestRepository($pdo),
            ),
            $systemConfig,
            new EntryScoreRepository($pdo),
            $pdo,
        );
    }
}
