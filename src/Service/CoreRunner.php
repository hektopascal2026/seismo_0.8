<?php

declare(strict_types=1);

namespace Seismo\Service;

use DateTimeImmutable;
use DateTimeZone;
use Seismo\Core\Fetcher\ImapMailFetchService;
use Seismo\Core\Mail\GmailApiInboxClient;
use Seismo\Core\Mail\GmailOAuthService;
use Seismo\Core\Mail\MailConfigKeys;
use Seismo\Core\Fetcher\ParlPressFetchService;
use Seismo\Core\Fetcher\RssArticleHydrator;
use Seismo\Core\Fetcher\RssFetchService;
use Seismo\Core\Fetcher\ScraperFetchService;
use Seismo\Feed\FeedModule;
use Seismo\Repository\EmailIngestRepository;
use Seismo\Repository\FeedItemRepository;
use Seismo\Repository\FeedUpsertResult;
use Seismo\Repository\PluginRunLogRepository;
use Seismo\Repository\SystemConfigRepository;

/**
 * Core upstreams (RSS, scraper, mail) — not SourceFetcherInterface plugins.
 * Writes {@see PluginRunResult}s under synthetic ids {@see self::CORE_IDS}.
 *
 * RSS and scraper default to **chunked** refresh (cursor + per-cron batch).
 * Scraper uses a shorter cron time budget than RSS so a two-minute master cron tick
 * stays under ~2 minutes (article fetches include deliberate inter-request delay).
 * Legacy single-pass mode is opt-in via {@see self::CONFIG_KEY_LEGACY_RSS_SCRAPER_REFRESH}
 * in Settings → General.
 */
final class CoreRunner
{
    public const ID_RSS        = 'core:rss';
    public const ID_PARL_PRESS = 'core:parl_press';
    public const ID_SCRAPER    = 'core:scraper';
    public const ID_MAIL       = 'core:mail';

    /** Targeted refresh for {@see FeedModule::CATEGORY_MEDIA} only (Media module / Diagnostics). */
    public const ID_RSS_MEDIA     = 'core:rss:media';
    public const ID_SCRAPER_MEDIA = 'core:scraper:media';

    /** @var array<string, int> seconds between successful runs when not forced */
    private const THROTTLE_SECONDS = [
        self::ID_RSS            => 900,
        self::ID_PARL_PRESS     => 900,
        self::ID_SCRAPER        => 1800,
        self::ID_MAIL           => 900,
        self::ID_RSS_MEDIA      => 900,
        self::ID_SCRAPER_MEDIA  => 1800,
    ];

    /**
     * When `system_config` is `1`, RSS and scraper run the historical behaviour
     * (every enabled source in one invocation). Default is chunked (key absent or `0`).
     */
    public const CONFIG_KEY_LEGACY_RSS_SCRAPER_REFRESH = 'ui:legacy_rss_scraper_refresh';

    private const CHUNK_RSS_FEEDS     = 12;
    private const CHUNK_SCRAPER_FEEDS = 4;

    /**
     * Wall-clock seconds for forced (web) chunked RSS/scraper loops before yielding.
     * Production Nginx/FPM allow ~300s; stay under that for timeline/Diagnostics refresh.
     */
    private const CHUNK_WEB_TIME_BUDGET_SEC = 120;

    /**
     * CLI cron RSS budget: advance multiple chunks per tick when not throttled (mutex-held
     * whole script). Leave headroom in a 2-minute cron schedule slot for parl press, mail,
     * plugins, retention.
     */
    private const CHUNK_CRON_TIME_BUDGET_SEC = 240;

    /**
     * CLI cron scraper budget: lower than RSS — each source may fetch many article pages
     * with production inter-request delay. Yields mid-cycle via cursor when exceeded.
     */
    private const CHUNK_SCRAPER_CRON_TIME_BUDGET_SEC = 90;

    /** Safety cap on chunk iterations per budgeted RSS/scraper run. */
    private const CHUNK_MAX_LOOPS = 80;

    private const K_RSS_AFTER        = 'refresh_chunk:rss_after_id';
    private const K_RSS_ITEMS_ACC    = 'refresh_chunk:rss_items_acc';
    private const K_RSS_ATTEMPTED_ACC = 'refresh_chunk:rss_attempted_acc';
    private const K_RSS_FAILED_ACC   = 'refresh_chunk:rss_failed_acc';
    private const K_RSS_SKIPPED_ACC  = 'refresh_chunk:rss_skipped_acc';

    private const K_SCRAPER_AFTER         = 'refresh_chunk:scraper_after_id';
    private const K_SCRAPER_ITEMS_ACC     = 'refresh_chunk:scraper_items_acc';
    private const K_SCRAPER_ATTEMPTED_ACC  = 'refresh_chunk:scraper_attempted_acc';
    private const K_SCRAPER_FAILED_ACC    = 'refresh_chunk:scraper_failed_acc';
    private const K_SCRAPER_SKIPPED_ACC   = 'refresh_chunk:scraper_skipped_acc';

    public function __construct(
        private FeedItemRepository $feeds,
        private PluginRunLogRepository $runLog,
        private SystemConfigRepository $magnituConfig,
        private EmailIngestRepository $emailIngest,
        private RssFetchService $rss = new RssFetchService(),
        private RssArticleHydrator $rssHydrator = new RssArticleHydrator(),
        private ScraperFetchService $scraper = new ScraperFetchService(),
        private ParlPressFetchService $parlPress = new ParlPressFetchService(),
        private ImapMailFetchService $imapMail = new ImapMailFetchService(),
    ) {
    }

    /**
     * @return array<string, PluginRunResult>
     */
    public function runAll(bool $force): array
    {
        return [
            self::ID_RSS         => $this->runRss($force),
            self::ID_PARL_PRESS  => $this->runParlPress($force),
            self::ID_SCRAPER     => $this->runScraper($force),
            self::ID_MAIL        => $this->runMail($force),
        ];
    }

    /**
     * Run one core fetcher by id ({@see self::ID_RSS}, {@see self::ID_SCRAPER}, {@see self::ID_MAIL}).
     */
    public function runOne(string $coreId, bool $force): PluginRunResult
    {
        return match ($coreId) {
            self::ID_RSS        => $this->runRss($force),
            self::ID_PARL_PRESS => $this->runParlPress($force),
            self::ID_SCRAPER    => $this->runScraper($force),
            self::ID_MAIL       => $this->runMail($force),
            default             => PluginRunResult::error('Unknown core fetcher id: ' . $coreId),
        };
    }

    private function runRss(bool $force): PluginRunResult
    {
        if (isSatellite()) {
            $r = PluginRunResult::skipped('Satellite mode — core fetchers do not run here.');
            $this->record(self::ID_RSS, $r, 0);

            return $r;
        }
        if ($this->legacyRssScraperRefreshEnabled()) {
            return $this->runRssLegacy($force);
        }

        return $this->runRssChunkedWithBudget($force);
    }

    /**
     * Historical single-pass RSS refresh (all feeds in one run).
     */
    private function runRssLegacy(bool $force): PluginRunResult
    {
        if (!$force && $this->isThrottled(self::ID_RSS, self::THROTTLE_SECONDS[self::ID_RSS])) {
            return $this->returnCoreThrottled(self::ID_RSS, self::THROTTLE_SECONDS[self::ID_RSS]);
        }

        $start = (int)(microtime(true) * 1000);
        $total = 0;
        $attempted = 0;
        $failed = 0;
        $rowsSkipped = 0;
        try {
            $offset = 0;
            $page   = 200;
            while (true) {
                $batch = $this->feeds->listFeedsForRssRefresh($page, $offset);
                if ($batch === []) {
                    break;
                }
                foreach ($batch as $feed) {
                    $id = (int)($feed['id'] ?? 0);
                    $url = trim((string)($feed['url'] ?? ''));
                    if ($id <= 0 || $url === '') {
                        continue;
                    }
                    $attempted++;
                    try {
                        $stats = $this->ingestRssFeed($feed);
                        $total += $stats->inserted;
                        $rowsSkipped += $stats->skipped;
                        $this->logFeedUpsertSkips('core:rss', $id, $stats);
                        $this->feeds->touchFeedSuccess($id);
                    } catch (\Throwable $e) {
                        $failed++;
                        error_log('Seismo core:rss feed ' . $id . ': ' . $e->getMessage());
                        $this->feeds->touchFeedFailure($id, $e->getMessage());
                    }
                }
                if (count($batch) < $page) {
                    break;
                }
                $offset += $page;
            }
            $r = PluginRunResult::batchFeeds($total, $attempted, $failed, $rowsSkipped);
        } catch (\Throwable $e) {
            error_log('Seismo core:rss: ' . $e->getMessage());
            $r = PluginRunResult::error($e->getMessage());
        }
        $duration = max(0, (int)(microtime(true) * 1000) - $start);
        $this->record(self::ID_RSS, $r, $duration);

        return $r;
    }

    /**
     * One RSS chunk. Throttle applies only when starting a new cycle (`after_id` is 0).
     * A full-cycle `plugin_run_log` row is written only when the cursor wraps or the
     * tail batch finishes (short batch).
     */
    private function runRssChunkedOnce(bool $force): PluginRunResult
    {
        $start = (int)(microtime(true) * 1000);
        try {
            $afterId = $this->getCursorInt(self::K_RSS_AFTER);
            if ($afterId === 0 && !$force && $this->isThrottled(self::ID_RSS, self::THROTTLE_SECONDS[self::ID_RSS])) {
                return $this->returnCoreThrottled(self::ID_RSS, self::THROTTLE_SECONDS[self::ID_RSS]);
            }
            if ($afterId === 0) {
                $this->zeroRssAccumulators();
            }

            $batch = $this->feeds->listFeedsForRssRefreshAfterId($afterId, self::CHUNK_RSS_FEEDS);
            if ($batch === []) {
                if ($afterId > 0) {
                    return $this->finalizeRssChunkedCycle($start);
                }
                $r = PluginRunResult::batchFeeds(0, 0, 0);
                $duration = max(0, (int)(microtime(true) * 1000) - $start);
                $this->record(self::ID_RSS, $r, $duration);

                return $r;
            }

            $total = 0;
            $attempted = 0;
            $failed = 0;
            $rowsSkipped = 0;
            $cursorAfter = $afterId;
            foreach ($batch as $feed) {
                $id = (int)($feed['id'] ?? 0);
                $url = trim((string)($feed['url'] ?? ''));
                if ($id <= 0 || $url === '') {
                    continue;
                }
                $cursorAfter = max($cursorAfter, $id);
                $attempted++;
                try {
                    $stats = $this->ingestRssFeed($feed);
                    $total += $stats->inserted;
                    $rowsSkipped += $stats->skipped;
                    $this->logFeedUpsertSkips('core:rss', $id, $stats);
                    $this->feeds->touchFeedSuccess($id);
                } catch (\Throwable $e) {
                    $failed++;
                    error_log('Seismo core:rss feed ' . $id . ': ' . $e->getMessage());
                    $this->feeds->touchFeedFailure($id, $e->getMessage());
                }
            }

            $this->addRssAccumulators($total, $attempted, $failed, $rowsSkipped);
            if (count($batch) < self::CHUNK_RSS_FEEDS) {
                return $this->finalizeRssChunkedCycle($start);
            }
            $this->magnituConfig->set(self::K_RSS_AFTER, (string)max(0, $cursorAfter));

            return PluginRunResult::batchFeeds($total, $attempted, $failed, $rowsSkipped)->withPersist(false);
        } catch (\Throwable $e) {
            error_log('Seismo core:rss: ' . $e->getMessage());
            $r = PluginRunResult::error($e->getMessage());
            $duration = max(0, (int)(microtime(true) * 1000) - $start);
            $this->record(self::ID_RSS, $r, $duration);

            return $r;
        }
    }

    private function runParlPress(bool $force): PluginRunResult
    {
        if (isSatellite()) {
            $r = PluginRunResult::skipped('Satellite mode — core fetchers do not run here.');
            $this->record(self::ID_PARL_PRESS, $r, 0);

            return $r;
        }
        if (!$force && $this->isThrottled(self::ID_PARL_PRESS, self::THROTTLE_SECONDS[self::ID_PARL_PRESS])) {
            return $this->returnCoreThrottled(self::ID_PARL_PRESS, self::THROTTLE_SECONDS[self::ID_PARL_PRESS]);
        }

        $start = (int)(microtime(true) * 1000);
        $total = 0;
        $attempted = 0;
        $failed = 0;
        try {
            $offset = 0;
            $page   = 50;
            while (true) {
                $batch = $this->feeds->listFeedsForParlPressRefresh($page, $offset);
                if ($batch === []) {
                    break;
                }
                foreach ($batch as $feed) {
                    $id = (int)($feed['id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    $attempted++;
                    try {
                        // Always strip legacy rows (e.g. SimplePie against the SharePoint URL stored
                        // "Untitled" per item). Cron uses force=false — alien cleanup must not be web-only.
                        $pre = $this->feeds->deleteAlienParlPressFeedItems($id);
                        if ($pre > 0) {
                            error_log(
                                'Seismo core:parl_press feed ' . $id . ': pre-clean removed ' . $pre
                                . ' non-SharePoint feed_items row(s) before fetch (legacy RSS on this URL).'
                            );
                        }
                        $rows = $this->parlPress->fetchForFeed($feed);
                        $stats = $this->feeds->upsertFeedItems($id, $rows);
                        $total += $stats->inserted;
                        $this->logFeedUpsertSkips('core:parl_press', $id, $stats);
                        if ($stats->inserted > 0) {
                            $purged = $this->feeds->deleteAlienParlPressFeedItems($id);
                            if ($purged > 0) {
                                error_log(
                                    'Seismo core:parl_press feed ' . $id . ': removed ' . $purged
                                    . ' non-SharePoint feed_items row(s) after upsert.'
                                );
                            }
                        }
                        $this->feeds->touchFeedSuccess($id);
                    } catch (\Throwable $e) {
                        $failed++;
                        error_log('Seismo core:parl_press feed ' . $id . ': ' . $e->getMessage());
                        $this->feeds->touchFeedFailure($id, $e->getMessage());
                    }
                }
                if (count($batch) < $page) {
                    break;
                }
                $offset += $page;
            }
            $r = PluginRunResult::batchFeeds($total, $attempted, $failed);
        } catch (\Throwable $e) {
            error_log('Seismo core:parl_press: ' . $e->getMessage());
            $r = PluginRunResult::error($e->getMessage());
        }
        $duration = max(0, (int)(microtime(true) * 1000) - $start);
        $this->record(self::ID_PARL_PRESS, $r, $duration);

        return $r;
    }

    private function runScraper(bool $force): PluginRunResult
    {
        if (isSatellite()) {
            $r = PluginRunResult::skipped('Satellite mode — core fetchers do not run here.');
            $this->record(self::ID_SCRAPER, $r, 0);

            return $r;
        }
        if ($this->legacyRssScraperRefreshEnabled()) {
            return $this->runScraperLegacy($force);
        }

        return $this->runScraperChunkedWithBudget($force);
    }

    private function runScraperLegacy(bool $force): PluginRunResult
    {
        if (!$force && $this->isThrottled(self::ID_SCRAPER, self::THROTTLE_SECONDS[self::ID_SCRAPER])) {
            return $this->returnCoreThrottled(self::ID_SCRAPER, self::THROTTLE_SECONDS[self::ID_SCRAPER]);
        }

        $start = (int)(microtime(true) * 1000);
        $this->backfillScraperFeedsSafely();
        $total = 0;
        $attempted = 0;
        $failed = 0;
        $rowsSkipped = 0;
        try {
            $offset = 0;
            $page   = 200;
            while (true) {
                $batch = $this->feeds->listFeedsForScraperRefresh($page, $offset);
                if ($batch === []) {
                    break;
                }
                foreach ($batch as $feed) {
                    $id = (int)($feed['id'] ?? 0);
                    $url = trim((string)($feed['url'] ?? ''));
                    if ($id <= 0 || $url === '') {
                        continue;
                    }
                    $attempted++;
                    try {
                        $stats = $this->ingestScraperFeed($feed, !$force);
                        $total += $stats->inserted;
                        $rowsSkipped += $stats->skipped;
                        $this->logFeedUpsertSkips('core:scraper', $id, $stats);
                        $this->feeds->touchFeedSuccess($id);
                    } catch (\Throwable $e) {
                        $failed++;
                        error_log('Seismo core:scraper feed ' . $id . ': ' . $e->getMessage());
                        $this->feeds->touchFeedFailure($id, $e->getMessage());
                    }
                }
                if (count($batch) < $page) {
                    break;
                }
                $offset += $page;
            }
            $r = PluginRunResult::batchFeeds($total, $attempted, $failed, $rowsSkipped);
        } catch (\Throwable $e) {
            error_log('Seismo core:scraper: ' . $e->getMessage());
            $r = PluginRunResult::error($e->getMessage());
        }
        $duration = max(0, (int)(microtime(true) * 1000) - $start);
        $this->record(self::ID_SCRAPER, $r, $duration);

        return $r;
    }

    private function runRssChunkedWithBudget(bool $force): PluginRunResult
    {
        return $this->runChunkedCoreWithBudget(
            $force,
            self::CHUNK_WEB_TIME_BUDGET_SEC,
            self::CHUNK_CRON_TIME_BUDGET_SEC,
            fn (bool $f): PluginRunResult => $this->runRssChunkedOnce($f),
            'RSS'
        );
    }

    private function runScraperChunkedWithBudget(bool $force): PluginRunResult
    {
        return $this->runChunkedCoreWithBudget(
            $force,
            self::CHUNK_WEB_TIME_BUDGET_SEC,
            self::CHUNK_SCRAPER_CRON_TIME_BUDGET_SEC,
            fn (bool $f): PluginRunResult => $this->runScraperChunkedOnce($f),
            'scraper'
        );
    }

    private function runChunkedCoreWithBudget(
        bool $force,
        int $webBudgetSec,
        int $cronBudgetSec,
        callable $runOnce,
        string $label
    ): PluginRunResult {
        $budgetSec = $force ? $webBudgetSec : $cronBudgetSec;
        $deadline  = microtime(true) + $budgetSec;
        $chunkItemSum = 0;
        $last         = PluginRunResult::ok(0);
        $budgetExceeded = false;
        for ($i = 0; $i < self::CHUNK_MAX_LOOPS && microtime(true) < $deadline; $i++) {
            $last = $runOnce($force);
            if ($last->isThrottleSkipped()) {
                return $last;
            }
            if ($last->persistToPluginRunLog) {
                return $last;
            }
            $chunkItemSum += $last->count;
            if (microtime(true) >= $deadline) {
                $budgetExceeded = true;
            }
        }

        $via = $force ? 'next cron or refresh' : 'next cron tick';
        
        // Track consecutive time-budget pauses
        $key = 'refresh_chunk:' . strtolower($label) . '_consecutive_pauses';
        $consecutive = (int)($this->magnituConfig->get($key) ?? '0');
        $consecutive++;
        $this->magnituConfig->set($key, (string)$consecutive);

        if ($consecutive >= 3) {
            $msg = 'Stuck Queue Alert: ' . $label . ' refresh has been paused on the time budget ' . $consecutive . ' consecutive runs. An active feed or scraper is likely deadlocking.';
            $r = new PluginRunResult('warn', $chunkItemSum, $msg, true);
            $coreId = ($label === 'RSS') ? self::ID_RSS : self::ID_SCRAPER;
            $this->record($coreId, $r, (int)($budgetSec * 1000));
            return $r;
        }

        return new PluginRunResult(
            'ok',
            $chunkItemSum,
            'Chunked ' . $label . ': paused (time budget) — ' . $via . ' continues the cycle.',
            false
        );
    }

    private function runScraperChunkedOnce(bool $force): PluginRunResult
    {
        $start = (int)(microtime(true) * 1000);
        try {
            $afterId = $this->getCursorInt(self::K_SCRAPER_AFTER);
            if ($afterId === 0 && !$force && $this->isThrottled(self::ID_SCRAPER, self::THROTTLE_SECONDS[self::ID_SCRAPER])) {
                return $this->returnCoreThrottled(self::ID_SCRAPER, self::THROTTLE_SECONDS[self::ID_SCRAPER]);
            }
            if ($afterId === 0) {
                // Self-heal at the start of each chunked cycle so newly-added
                // scraper_configs (and re-enabled ones) get a matching enabled
                // feeds row before listFeedsForScraperRefreshAfterId() reads.
                $this->backfillScraperFeedsSafely();
                $this->zeroScraperAccumulators();
            }

            $batch = $this->feeds->listFeedsForScraperRefreshAfterId($afterId, self::CHUNK_SCRAPER_FEEDS);
            if ($batch === []) {
                if ($afterId > 0) {
                    return $this->finalizeScraperChunkedCycle($start);
                }
                $r = PluginRunResult::batchFeeds(0, 0, 0);
                $duration = max(0, (int)(microtime(true) * 1000) - $start);
                $this->record(self::ID_SCRAPER, $r, $duration);

                return $r;
            }

            $total = 0;
            $attempted = 0;
            $failed = 0;
            $rowsSkipped = 0;
            $cursorAfter = $afterId;
            foreach ($batch as $feed) {
                $id = (int)($feed['id'] ?? 0);
                $url = trim((string)($feed['url'] ?? ''));
                if ($id <= 0 || $url === '') {
                    continue;
                }
                $cursorAfter = max($cursorAfter, $id);
                $attempted++;
                try {
                    $stats = $this->ingestScraperFeed($feed, !$force);
                    $total += $stats->inserted;
                    $rowsSkipped += $stats->skipped;
                    $this->logFeedUpsertSkips('core:scraper', $id, $stats);
                    $this->feeds->touchFeedSuccess($id);
                } catch (\Throwable $e) {
                    $failed++;
                    error_log('Seismo core:scraper feed ' . $id . ': ' . $e->getMessage());
                    $this->feeds->touchFeedFailure($id, $e->getMessage());
                }
            }

            $this->addScraperAccumulators($total, $attempted, $failed, $rowsSkipped);
            if (count($batch) < self::CHUNK_SCRAPER_FEEDS) {
                return $this->finalizeScraperChunkedCycle($start);
            }
            $this->magnituConfig->set(self::K_SCRAPER_AFTER, (string)max(0, $cursorAfter));

            return PluginRunResult::batchFeeds($total, $attempted, $failed, $rowsSkipped)->withPersist(false);
        } catch (\Throwable $e) {
            error_log('Seismo core:scraper: ' . $e->getMessage());
            $r = PluginRunResult::error($e->getMessage());
            $duration = max(0, (int)(microtime(true) * 1000) - $start);
            $this->record(self::ID_SCRAPER, $r, $duration);

            return $r;
        }
    }

    private function runMail(bool $force): PluginRunResult
    {
        if (isSatellite()) {
            $r = PluginRunResult::skipped('Satellite mode — core fetchers do not run here.');
            $this->record(self::ID_MAIL, $r, 0);

            return $r;
        }
        $mailThrottle = self::THROTTLE_SECONDS[self::ID_MAIL];
        // Gmail quota: always respect mail throttle (timeline/diagnostics force=true does not bypass).
        if ($this->isMailThrottled($mailThrottle)) {
            $r = PluginRunResult::throttleSkipped(
                'Throttled — last mail run is fresher than ' . $mailThrottle . 's.'
            );
            $this->record(self::ID_MAIL, $r, 0);

            return $r;
        }

        $start = (int)(microtime(true) * 1000);
        try {
            $transport = $this->resolveMailTransport();
            if ($transport === MailConfigKeys::TRANSPORT_GMAIL_API) {
                $r = $this->runGmailMail();
            } elseif ($transport === MailConfigKeys::TRANSPORT_IMAP_LEGACY) {
                $r = $this->runImapLegacyMail($force);
            } else {
                $r = PluginRunResult::skipped(
                    'Mail not configured — connect Gmail in Settings → Mail (recommended) or configure legacy IMAP.',
                    $force
                );
            }
        } catch (\Throwable $e) {
            error_log('Seismo core:mail: ' . $e->getMessage());
            $r = PluginRunResult::error($e->getMessage());
        }
        $duration = max(0, (int)(microtime(true) * 1000) - $start);
        $this->record(self::ID_MAIL, $r, $duration);

        return $r;
    }

    private function runGmailMail(): PluginRunResult
    {
        $oauth = new GmailOAuthService($this->magnituConfig);
        if (!$oauth->isConnected()) {
            return PluginRunResult::skipped(
                'Gmail not connected — use Settings → Mail → Connect Google account.',
                true
            );
        }
        $client  = new GmailApiInboxClient($oauth, $this->magnituConfig);
        $outcome = $client->fetch(false);
        $n       = $this->emailIngest->upsertGmailBatch($outcome->rows);

        if ($outcome->fetchFailures > 0) {
            return new PluginRunResult(
                'warn',
                $n,
                $outcome->fetchFailures . ' Gmail message(s) failed to fetch; history cursor not advanced — they will retry on the next run.'
            );
        }

        return PluginRunResult::ok($n);
    }

    private function runImapLegacyMail(bool $force): PluginRunResult
    {
        if (!extension_loaded('imap')) {
            return PluginRunResult::skipped(
                'PHP imap extension is not enabled — install ext-imap for legacy IMAP mail.',
                $force
            );
        }
        if (!$this->mailImapConfigured()) {
            return PluginRunResult::skipped(
                'Legacy IMAP not configured — set mailbox/host and credentials in Settings → Mail.',
                $force
            );
        }
        $cfg  = $this->loadMailImapConfig();
        $rows = $this->imapMail->fetch($cfg);
        $n    = $this->emailIngest->upsertImapBatch($rows);
        if ($n > 0 && $rows !== [] && $this->truthyMailConfig($cfg['mail_mark_seen'] ?? '0')) {
            try {
                $this->imapMail->markSeen($cfg, array_map(
                    static fn (array $row): int => (int)($row['imap_uid'] ?? 0),
                    $rows
                ));
            } catch (\Throwable $e) {
                error_log('Seismo core:mail mark seen: ' . $e->getMessage());
            }
        }

        return PluginRunResult::ok($n);
    }

    private function resolveMailTransport(): ?string
    {
        $explicit = trim((string)($this->magnituConfig->get(MailConfigKeys::TRANSPORT) ?? ''));
        if ($explicit === MailConfigKeys::TRANSPORT_GMAIL_API) {
            return MailConfigKeys::TRANSPORT_GMAIL_API;
        }
        if ($explicit === MailConfigKeys::TRANSPORT_IMAP_LEGACY) {
            return MailConfigKeys::TRANSPORT_IMAP_LEGACY;
        }

        $oauth = new GmailOAuthService($this->magnituConfig);
        if ($oauth->isConnected()) {
            return MailConfigKeys::TRANSPORT_GMAIL_API;
        }
        if ($this->mailImapConfigured()) {
            return MailConfigKeys::TRANSPORT_IMAP_LEGACY;
        }

        return null;
    }

    /**
     * @param array<string, string|null> $cfg
     */
    private function truthyMailConfig(mixed $v): bool
    {
        $s = strtolower(trim((string)$v));

        return $s === '1' || $s === 'true' || $s === 'yes' || $s === 'on';
    }

    private function mailImapConfigured(): bool
    {
        $c = $this->loadMailImapConfig();
        $user = trim((string)($c['mail_imap_username'] ?? ''));
        if ($user === '') {
            return false;
        }
        $mb = trim((string)($c['mail_imap_mailbox'] ?? ''));
        $host = trim((string)($c['mail_imap_host'] ?? ''));

        return $mb !== '' || $host !== '';
    }

    /**
     * @return array<string, string|null>
     */
    private function loadMailImapConfig(): array
    {
        $keys = [
            'mail_imap_mailbox',
            'mail_imap_username',
            'mail_imap_password',
            'mail_imap_host',
            'mail_imap_port',
            'mail_imap_flags',
            'mail_imap_folder',
            'mail_max_messages',
            'mail_search_criteria',
            'mail_mark_seen',
        ];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->magnituConfig->get($k);
        }

        return $out;
    }

    /**
     * Clear chunked RSS/scraper cursor + cycle counters (e.g. after toggling refresh mode in Settings).
     */
    public static function clearChunkedFeedRefreshState(SystemConfigRepository $config): void
    {
        foreach ([
            self::K_RSS_AFTER,
            self::K_RSS_ITEMS_ACC,
            self::K_RSS_ATTEMPTED_ACC,
            self::K_RSS_FAILED_ACC,
            self::K_RSS_SKIPPED_ACC,
            self::K_SCRAPER_AFTER,
            self::K_SCRAPER_ITEMS_ACC,
            self::K_SCRAPER_ATTEMPTED_ACC,
            self::K_SCRAPER_FAILED_ACC,
            self::K_SCRAPER_SKIPPED_ACC,
        ] as $key) {
            $config->delete($key);
        }
    }

    private function legacyRssScraperRefreshEnabled(): bool
    {
        return $this->magnituConfig->get(self::CONFIG_KEY_LEGACY_RSS_SCRAPER_REFRESH) === '1';
    }

    private function getCursorInt(string $key): int
    {
        $v = $this->magnituConfig->get($key);
        if ($v === null || $v === '' || !ctype_digit($v)) {
            return 0;
        }

        return max(0, (int)$v);
    }

    private function getAccInt(string $key): int
    {
        $v = $this->magnituConfig->get($key);
        if ($v === null || $v === '' || !ctype_digit($v)) {
            return 0;
        }

        return max(0, (int)$v);
    }

    private function zeroRssAccumulators(): void
    {
        $this->magnituConfig->set(self::K_RSS_ITEMS_ACC, '0');
        $this->magnituConfig->set(self::K_RSS_ATTEMPTED_ACC, '0');
        $this->magnituConfig->set(self::K_RSS_FAILED_ACC, '0');
        $this->magnituConfig->set(self::K_RSS_SKIPPED_ACC, '0');
    }

    private function addRssAccumulators(int $items, int $attempted, int $failed, int $rowsSkipped = 0): void
    {
        $this->magnituConfig->set(
            self::K_RSS_ITEMS_ACC,
            (string)($this->getAccInt(self::K_RSS_ITEMS_ACC) + $items)
        );
        $this->magnituConfig->set(
            self::K_RSS_ATTEMPTED_ACC,
            (string)($this->getAccInt(self::K_RSS_ATTEMPTED_ACC) + $attempted)
        );
        $this->magnituConfig->set(
            self::K_RSS_FAILED_ACC,
            (string)($this->getAccInt(self::K_RSS_FAILED_ACC) + $failed)
        );
        $this->magnituConfig->set(
            self::K_RSS_SKIPPED_ACC,
            (string)($this->getAccInt(self::K_RSS_SKIPPED_ACC) + $rowsSkipped)
        );
    }

    private function finalizeRssChunkedCycle(int $startMs): PluginRunResult
    {
        $items = $this->getAccInt(self::K_RSS_ITEMS_ACC);
        $att = $this->getAccInt(self::K_RSS_ATTEMPTED_ACC);
        $fail = $this->getAccInt(self::K_RSS_FAILED_ACC);
        $skipped = $this->getAccInt(self::K_RSS_SKIPPED_ACC);
        $this->zeroRssAccumulators();
        $this->magnituConfig->set(self::K_RSS_AFTER, '0');
        $this->magnituConfig->set('refresh_chunk:rss_consecutive_pauses', '0');
        $r = PluginRunResult::batchFeeds($items, $att, $fail, $skipped);
        $duration = max(0, (int)(microtime(true) * 1000) - $startMs);
        $this->record(self::ID_RSS, $r, $duration);

        return $r;
    }

    private function zeroScraperAccumulators(): void
    {
        $this->magnituConfig->set(self::K_SCRAPER_ITEMS_ACC, '0');
        $this->magnituConfig->set(self::K_SCRAPER_ATTEMPTED_ACC, '0');
        $this->magnituConfig->set(self::K_SCRAPER_FAILED_ACC, '0');
        $this->magnituConfig->set(self::K_SCRAPER_SKIPPED_ACC, '0');
    }

    private function addScraperAccumulators(int $items, int $attempted, int $failed, int $rowsSkipped = 0): void
    {
        $this->magnituConfig->set(
            self::K_SCRAPER_ITEMS_ACC,
            (string)($this->getAccInt(self::K_SCRAPER_ITEMS_ACC) + $items)
        );
        $this->magnituConfig->set(
            self::K_SCRAPER_ATTEMPTED_ACC,
            (string)($this->getAccInt(self::K_SCRAPER_ATTEMPTED_ACC) + $attempted)
        );
        $this->magnituConfig->set(
            self::K_SCRAPER_FAILED_ACC,
            (string)($this->getAccInt(self::K_SCRAPER_FAILED_ACC) + $failed)
        );
        $this->magnituConfig->set(
            self::K_SCRAPER_SKIPPED_ACC,
            (string)($this->getAccInt(self::K_SCRAPER_SKIPPED_ACC) + $rowsSkipped)
        );
    }

    private function finalizeScraperChunkedCycle(int $startMs): PluginRunResult
    {
        $items = $this->getAccInt(self::K_SCRAPER_ITEMS_ACC);
        $att = $this->getAccInt(self::K_SCRAPER_ATTEMPTED_ACC);
        $fail = $this->getAccInt(self::K_SCRAPER_FAILED_ACC);
        $skipped = $this->getAccInt(self::K_SCRAPER_SKIPPED_ACC);
        $this->zeroScraperAccumulators();
        $this->magnituConfig->set(self::K_SCRAPER_AFTER, '0');
        $this->magnituConfig->set('refresh_chunk:scraper_consecutive_pauses', '0');
        $r = PluginRunResult::batchFeeds($items, $att, $fail, $skipped);
        $duration = max(0, (int)(microtime(true) * 1000) - $startMs);
        $this->record(self::ID_SCRAPER, $r, $duration);

        return $r;
    }

    /**
     * Single-pass RSS refresh for one `feeds.category` (Media module button).
     */
    public function runRssForCategory(string $category, bool $force): PluginRunResult
    {
        $category = trim($category);
        $logId    = self::pluginLogIdForCategoryRss($category);

        if (isSatellite()) {
            $r = PluginRunResult::skipped('Satellite mode — core fetchers do not run here.');
            $this->record($logId, $r, 0);

            return $r;
        }

        if ($category === '') {
            return PluginRunResult::error('Category is required for targeted RSS refresh.');
        }

        $start = (int)(microtime(true) * 1000);
        $total = 0;
        $attempted = 0;
        $failed = 0;
        $rowsSkipped = 0;
        try {
            $offset = 0;
            $page   = 200;
            while (true) {
                $batch = $this->feeds->listFeedsForRssRefreshInCategory($category, $page, $offset);
                if ($batch === []) {
                    break;
                }
                foreach ($batch as $feed) {
                    $id = (int)($feed['id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    $attempted++;
                    try {
                        $stats = $this->ingestRssFeed($feed);
                        $total += $stats->inserted;
                        $rowsSkipped += $stats->skipped;
                        $this->logFeedUpsertSkips('core:rss:' . $category, $id, $stats);
                        $this->feeds->touchFeedSuccess($id);
                    } catch (\Throwable $e) {
                        $failed++;
                        error_log('Seismo core:rss:' . $category . ' feed ' . $id . ': ' . $e->getMessage());
                        $this->feeds->touchFeedFailure($id, $e->getMessage());
                    }
                }
                if (count($batch) < $page) {
                    break;
                }
                $offset += $page;
            }
            $r = PluginRunResult::batchFeeds($total, $attempted, $failed, $rowsSkipped);
        } catch (\Throwable $e) {
            error_log('Seismo core:rss:' . $category . ': ' . $e->getMessage());
            $r = PluginRunResult::error($e->getMessage());
        }
        $duration = max(0, (int)(microtime(true) * 1000) - $start);
        $this->record($logId, $r, $duration);

        return $r;
    }

    /**
     * Single-pass scraper refresh for one `feeds.category` (Media module button).
     */
    public function runScraperForCategory(string $category, bool $force): PluginRunResult
    {
        $category = trim($category);
        $logId    = self::pluginLogIdForCategoryScraper($category);

        if (isSatellite()) {
            $r = PluginRunResult::skipped('Satellite mode — core fetchers do not run here.');
            $this->record($logId, $r, 0);

            return $r;
        }

        if ($category === '') {
            return PluginRunResult::error('Category is required for targeted scraper refresh.');
        }

        $this->backfillScraperFeedsSafely();

        $start = (int)(microtime(true) * 1000);
        $total = 0;
        $attempted = 0;
        $failed = 0;
        $rowsSkipped = 0;
        try {
            $offset = 0;
            $page   = 50;
            while (true) {
                $batch = $this->feeds->listFeedsForScraperRefreshInCategory($category, $page, $offset);
                if ($batch === []) {
                    break;
                }
                foreach ($batch as $feed) {
                    $id = (int)($feed['id'] ?? 0);
                    $url = trim((string)($feed['url'] ?? ''));
                    if ($id <= 0 || $url === '') {
                        continue;
                    }
                    $attempted++;
                    try {
                        $stats = $this->ingestScraperFeed($feed, false);
                        $total += $stats->inserted;
                        $rowsSkipped += $stats->skipped;
                        $this->logFeedUpsertSkips('core:scraper:' . $category, $id, $stats);
                        $this->feeds->touchFeedSuccess($id);
                    } catch (\Throwable $e) {
                        $failed++;
                        error_log('Seismo core:scraper:' . $category . ' feed ' . $id . ': ' . $e->getMessage());
                        $this->feeds->touchFeedFailure($id, $e->getMessage());
                    }
                }
                if (count($batch) < $page) {
                    break;
                }
                $offset += $page;
            }
            $r = PluginRunResult::batchFeeds($total, $attempted, $failed, $rowsSkipped);
        } catch (\Throwable $e) {
            error_log('Seismo core:scraper:' . $category . ': ' . $e->getMessage());
            $r = PluginRunResult::error($e->getMessage());
        }
        $duration = max(0, (int)(microtime(true) * 1000) - $start);
        $this->record($logId, $r, $duration);

        return $r;
    }

    private static function pluginLogIdForCategoryRss(string $category): string
    {
        return trim($category) === FeedModule::CATEGORY_MEDIA
            ? self::ID_RSS_MEDIA
            : self::ID_RSS . ':' . trim($category);
    }

    private static function pluginLogIdForCategoryScraper(string $category): string
    {
        return trim($category) === FeedModule::CATEGORY_MEDIA
            ? self::ID_SCRAPER_MEDIA
            : self::ID_SCRAPER . ':' . trim($category);
    }

    /**
     * @param array<string, mixed> $feed Row from {@see FeedItemRepository::listFeedsForRssRefresh()}
     */
    private function ingestRssFeed(array $feed): FeedUpsertResult
    {
        $id  = (int)($feed['id'] ?? 0);
        $url = trim((string)($feed['url'] ?? ''));
        if ($id <= 0 || $url === '') {
            return new FeedUpsertResult(0, 0);
        }

        $items = $this->rss->fetchFeedItems($url);
        if ((int)($feed['extract_full_text'] ?? 0) === 1) {
            $items = $this->rssHydrator->hydrateThinItems($items, true);
        }

        return $this->feeds->upsertFeedItems($id, $items);
    }

    /**
     * @param array<string, mixed> $feed
     */
    private function ingestScraperFeed(array $feed, bool $delayBetweenArticleFetches): FeedUpsertResult
    {
        $id  = (int)($feed['id'] ?? 0);
        $url = trim((string)($feed['url'] ?? ''));
        if ($id <= 0 || $url === '') {
            return new FeedUpsertResult(0, 0);
        }

        $linkPattern = trim((string)($feed['scraper_link_pattern'] ?? ''));
        $out         = $this->scraper->fetchScraperFeedItems(
            $url,
            $linkPattern,
            trim((string)($feed['scraper_date_selector'] ?? '')),
            trim((string)($feed['scraper_exclude_selectors'] ?? '')),
            ScraperFetchService::PRODUCTION_MAX_ARTICLES,
            $delayBetweenArticleFetches
        );
        if ($out['fatal_error'] !== null) {
            throw new \RuntimeException((string)$out['fatal_error']);
        }
        foreach ($out['warnings'] as $w) {
            error_log('Seismo core:scraper feed ' . $id . ': ' . $w);
        }
        if ($linkPattern !== '' && $out['items'] === [] && !empty($out['no_links_matched'])) {
            throw new \RuntimeException(
                'No same-host links on the listing page match the link pattern (substring).'
            );
        }

        return $this->feeds->upsertFeedItems($id, $out['items']);
    }

    private function returnCoreThrottled(string $coreId, int $minIntervalSeconds): PluginRunResult
    {
        $r = PluginRunResult::throttleSkipped(
            'Throttled — last successful run is fresher than ' . $minIntervalSeconds . 's.'
        );
        $this->record($coreId, $r, 0);

        return $r;
    }

    private function logFeedUpsertSkips(string $coreLabel, int $feedId, FeedUpsertResult $stats): void
    {
        if ($stats->skipped > 0) {
            error_log(sprintf(
                'Seismo %s feed %d: %d row(s) skipped at upsert.',
                $coreLabel,
                $feedId,
                $stats->skipped
            ));
        }
    }

    /**
     * Call {@see FeedItemRepository::backfillScraperFeeds()} but never let a
     * backfill error abort the whole `core:scraper` run — the worst case is
     * still "no new entries", same as before the helper existed.
     */
    private function backfillScraperFeedsSafely(): void
    {
        try {
            $created = $this->feeds->backfillScraperFeeds();
            if ($created > 0) {
                error_log('Seismo core:scraper backfill: created ' . $created . ' feeds row(s) for orphan scraper_configs.');
            }
        } catch (\Throwable $e) {
            error_log('Seismo core:scraper backfill failed: ' . $e->getMessage());
        }
    }

    private function isThrottled(string $coreId, int $minSeconds): bool
    {
        if ($minSeconds <= 0) {
            return false;
        }
        $last = $this->runLog->lastSuccessfulRunAt($coreId);
        if ($last === null) {
            return false;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return ($now->getTimestamp() - $last->getTimestamp()) < $minSeconds;
    }

    /**
     * Mail/Gmail: throttle on last successful run, and also on the last attempt
     * when it failed (e.g. API 429) so cron does not hammer Google every tick.
     */
    private function isMailThrottled(int $minSeconds): bool
    {
        if ($minSeconds <= 0) {
            return false;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $lastOk = $this->runLog->lastSuccessfulRunAt(self::ID_MAIL);
        if ($lastOk !== null && ($now->getTimestamp() - $lastOk->getTimestamp()) < $minSeconds) {
            return true;
        }
        $lastNonSkipped = $this->runLog->lastNonSkippedRun(self::ID_MAIL);
        if ($lastNonSkipped !== null && $lastNonSkipped['status'] === 'error') {
            if (($now->getTimestamp() - $lastNonSkipped['run_at']->getTimestamp()) < $minSeconds) {
                return true;
            }
        }

        return false;
    }

    private function record(string $id, PluginRunResult $result, int $durationMs): void
    {
        if (!$result->persistToPluginRunLog) {
            return;
        }
        try {
            $this->runLog->record($id, $result, $durationMs);
        } catch (\Throwable $e) {
            error_log('Seismo core_run_log write failed: ' . $e->getMessage());
        }
    }
}
