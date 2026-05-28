<?php

declare(strict_types=1);

namespace Seismo\Controller;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Seismo\Http\CsrfToken;
use Seismo\Http\RefreshAjax;
use Seismo\Repository\PluginRunLogRepository;
use Seismo\Repository\SourceHealthRepository;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Service\CoreRunner;
use Seismo\Service\PluginRegistry;
use Seismo\Service\RefreshAllService;
use Seismo\Service\RefreshMutexBusyException;

/**
 * Diagnostics page — plugin-run status surface.
 *
 * Slice 3 scope (intentionally minimal):
 *   - Status table with one row per registered plugin (latest run from
 *     plugin_run_log + throttle window).
 *   - "Refresh all now" master button (force=true; bypasses throttle).
 *   - Per-plugin "Refresh now" button (force=true).
 *   - Per-plugin "Test fetch" button (calls fetch() without persisting,
 *     stashes a peek of the rows in a session flash).
 *
 * Out of scope for Slice 3 (graduates later):
 *   - History strip (recentForPlugin) — code is in place, UI lands when needed.
 *   - Inline config viewer — admin still uses Lex/Leg pages for config.
 *
 * All POST endpoints require CSRF and run behind AuthGate (router enforces it
 * because `diagnostics`, `refresh_all`, `refresh_plugin`, `plugin_test` are
 * NOT on the AuthGate public whitelist).
 *
 * The diagnostics UI lives under **Settings → Diagnostics** (`?action=settings&tab=diagnostics`).
 * {@see self::show()} redirects legacy `?action=diagnostics` bookmarks.
 */
final class DiagnosticsController
{
    /** Shared with {@see self::refreshAllRemote()} — 60s cooldown between full refreshes (0.4 parity). */
    private const KEY_LAST_REFRESH_AT = 'last_refresh_at';

    public function show(): void
    {
        header('Location: ' . getBasePath() . '/index.php?action=settings&tab=diagnostics', true, 303);
        exit;
    }

    /**
     * Build view variables for {@see SettingsController} when `tab=diagnostics`.
     * Consumes and clears {@see $_SESSION} `plugin_test_result` (one-shot peek).
     *
     * @return array{
     *   diagStatus: array<string, array<string, mixed>>,
     *   diagCoreStatus: array<string, array<string, mixed>>,
     *   diagMediaStatus: array<string, array<string, mixed>>,
     *   diagLoadError: ?string,
     *   diagTestResult: ?array{id: string, count: int, error: ?string, items: list<array<string, mixed>>},
     *   diagRunHistory: array<string, list<array{run_at: \DateTimeImmutable, status: string, item_count: int, error_message: ?string, duration_ms: int}>>,
     *   diagSourceHealthFeeds: list<array<string, mixed>>,
     *   diagSourceHealthMail: list<array<string, mixed>>,
     *   diagSourceHealthStaleDays: int,
     *   diagSourceHealthError: ?string,
     * }
     */
    public static function prepareViewData(): array
    {
        $registry = new PluginRegistry();
        $plugins  = $registry->all();

        $coreMeta = [
            CoreRunner::ID_RSS         => ['label' => 'RSS & Substack', 'min_interval' => 1800, 'entry_type' => 'feed_items'],
            CoreRunner::ID_PARL_PRESS  => ['label' => 'Parlament Medien (press)', 'min_interval' => 1800, 'entry_type' => 'feed_items'],
            CoreRunner::ID_SCRAPER     => ['label' => 'Scraper pages', 'min_interval' => 3600, 'entry_type' => 'feed_items'],
            CoreRunner::ID_MAIL        => ['label' => 'Mail (IMAP)', 'min_interval' => 900, 'entry_type' => 'emails'],
        ];

        $mediaMeta = [
            CoreRunner::ID_RSS_MEDIA     => [
                'label'        => 'Media — RSS & hydration',
                'min_interval' => 1800,
                'entry_type'   => 'feed_items',
            ],
            CoreRunner::ID_SCRAPER_MEDIA => [
                'label'        => 'Media — scraper listings',
                'min_interval' => 3600,
                'entry_type'   => 'feed_items',
            ],
        ];

        $status      = [];
        $coreStatus  = [];
        $mediaStatus = [];
        $loadError   = null;
        $runHistory  = [];
        $pdo         = null;
        $cronStalledMinutes = null;
        $log         = null;

        try {
            $pdo        = getDbConnection();
            $log        = new PluginRunLogRepository($pdo);
            $ids        = array_merge(array_keys($coreMeta), array_keys($mediaMeta), array_keys($plugins));
            $latest     = $log->latestPerPlugin($ids);
            $runHistory = $log->recentForPlugins($ids, 8);

            // Stuck Cron Advisory Mutex Warning
            $mutex = new \Seismo\Repository\CronMutexRepository($pdo);
            if ($mutex->isRefreshCronLockHeld()) {
                $configRepo = new \Seismo\Repository\SystemConfigRepository($pdo);
                $started = $configRepo->get('refresh_cron:started_at');
                if ($started !== null && $started !== '' && ctype_digit($started)) {
                    $elapsed = time() - (int)$started;
                    if ($elapsed > 600) { // 10 minutes
                        $cronStalledMinutes = (int)round($elapsed / 60);
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('Seismo diagnostics: ' . $e->getMessage());
            $loadError = 'Could not read plugin_run_log. Has the latest migration run yet? (php migrate.php)';
            $latest = [];
            $runHistory = [];
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $coreStatus  = self::buildFetcherStatusMap($coreMeta, $latest, $now, true, $log);
        $mediaStatus = self::buildFetcherStatusMap($mediaMeta, $latest, $now, false, $log);

        foreach ($plugins as $id => $plugin) {
            $row = $latest[$id] ?? null;
            $minInterval = $plugin->getMinIntervalSeconds();

            $nextAllowed = null;
            if ($row !== null && in_array($row['status'], ['ok', 'warn'], true) && $minInterval > 0) {
                $nextAllowed = $row['run_at']->modify('+' . $minInterval . ' seconds');
            }

            $lastAttempt = null;
            if ($row !== null && $row['status'] === 'skipped' && $log !== null) {
                $lastAttempt = $log->lastNonSkippedRun($id);
            }

            $status[$id] = [
                'id'            => $id,
                'label'         => $plugin->getLabel(),
                'entry_type'    => $plugin->getEntryType(),
                'config_key'    => $plugin->getConfigKey(),
                'min_interval'  => $minInterval,
                'last'          => $row,
                'next_allowed'  => $nextAllowed,
                'is_throttled'  => $nextAllowed !== null && $nextAllowed > $now,
                'is_core'       => false,
                'last_attempt'  => $lastAttempt,
            ];
        }

        $testResult = $_SESSION['plugin_test_result'] ?? null;
        unset($_SESSION['plugin_test_result']);

        $sourceHealthFeeds   = [];
        $sourceHealthMail    = [];
        $sourceHealthError   = null;
        $sourceHealthDays    = 14;

        if (!isSatellite() && $pdo instanceof PDO) {
            try {
                $health            = new SourceHealthRepository($pdo);
                $sourceHealthFeeds = $health->listFeedHealth($sourceHealthDays);
                $sourceHealthMail  = $health->listMailHealth($sourceHealthDays);
            } catch (\Throwable $e) {
                error_log('Seismo diagnostics source health: ' . $e->getMessage());
                $sourceHealthError = 'Could not load source health. Check error_log for details.';
            }
        }

        return [
            'diagStatus'              => $status,
            'diagCoreStatus'          => $coreStatus,
            'diagMediaStatus'         => $mediaStatus,
            'diagLoadError'           => $loadError,
            'diagTestResult'          => is_array($testResult) ? $testResult : null,
            'diagRunHistory'          => $runHistory,
            'diagSourceHealthFeeds'   => $sourceHealthFeeds,
            'diagSourceHealthMail'    => $sourceHealthMail,
            'diagSourceHealthStaleDays' => $sourceHealthDays,
            'diagSourceHealthError'   => $sourceHealthError,
            'cronStalledMinutes'      => $cronStalledMinutes,
        ];
    }

    public function refreshAll(): void
    {
        $ajax = $this->wantsJsonRefreshResponse();
        if (!$this->assertPostWithCsrfForRefresh($ajax)) {
            return;
        }

        set_time_limit(300);
        $pdo = getDbConnection();
        $config = new SystemConfigRepository($pdo);
        $last = $config->get(self::KEY_LAST_REFRESH_AT);
        if ($last !== null && $last !== '' && ctype_digit($last) && (time() - (int)$last) < 60) {
            $remaining = 60 - (time() - (int)$last);
            $msg = "Please wait {$remaining}s before refreshing again.";
            $_SESSION['error'] = $msg;
            if ($ajax) {
                $this->jsonRefreshResponse(200, false, $msg, $msg);
            }
            $this->redirectAfterRefresh();

            return;
        }

        $skipLexPlugins = $this->timelineRefreshRequestsSkipLex();

        try {
            $results = RefreshAllService::boot($pdo)->runAll(true, $skipLexPlugins);
        } catch (RefreshMutexBusyException $e) {
            $msg = $e->getMessage();
            $_SESSION['error'] = $msg;
            if ($ajax) {
                $this->jsonRefreshResponse(409, false, $msg, $msg);
            }
            $this->redirectAfterRefresh();

            return;
        } catch (\Throwable $e) {
            error_log('Seismo diagnostics refresh_all: ' . $e->getMessage());
            $msg = 'Refresh all failed: ' . $e->getMessage();
            $_SESSION['error'] = $msg;
            if ($ajax) {
                $this->jsonRefreshResponse(200, false, $msg, $msg);
            }
            $this->redirectAfterRefresh();

            return;
        }

        $config->set(self::KEY_LAST_REFRESH_AT, (string)time());

        $agg = RefreshAllService::aggregatePluginRunResults($results);
        $detail = RefreshAllService::aggregateResultDetailAppendix($results);
        $summary = 'Refresh all: ' . $agg['summary'] . '.' . $detail;
        if ($skipLexPlugins) {
            $summary .= ' Lex legislation plugins were not run — use Settings → Diagnostics → Refresh all, or cron, for those sources.';
        }
        $_SESSION['success'] = $summary;
        if ($ajax) {
            $this->jsonRefreshResponse(200, true, $summary, null);
        }

        $this->redirectAfterRefresh();
    }

    private function wantsJsonRefreshResponse(): bool
    {
        return isset($_POST['ajax']) && (string) $_POST['ajax'] === '1';
    }

    private function assertPostWithCsrfForRefresh(bool $ajax): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            if ($ajax) {
                $this->jsonRefreshResponse(405, false, 'Method not allowed', 'Method not allowed');
            }
            header('Location: ' . getBasePath() . '/index.php?action=index', true, 303);
            exit;
        }
        // Do not rotate: timeline AJAX refresh shares the page with favourite/hide forms.
        if (!CsrfToken::verifyRequest(rotateOnSuccess: false)) {
            if ($ajax) {
                $this->jsonRefreshResponse(403, false, 'Session expired — please try again.', 'Session expired — please try again.');
            }
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirectAfterRefresh();

            return false;
        }

        return true;
    }

    /**
     * @param 'success'|null $message User-visible summary when ok; when not ok, used as error text if $error is null
     */
    private function jsonRefreshResponse(int $http, bool $ok, ?string $message, ?string $error): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($http);
        $errOut = $error ?? ($ok ? null : $message);
        echo json_encode([
            'ok'      => $ok,
            'message' => $ok ? $message : null,
            'error'   => $ok ? null : $errOut,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Satellite-callable refresh — validates `?key=` against
     * {@see seismoRemoteRefreshKey()}. JSON response; no session (safe for cross-origin
     * fetch from a public satellite page). Port of 0.4 `handleRefreshAllRemote`.
     *
     * Uses {@see RefreshAllService::runAll()} with Lex plugins skipped — same as
     * the mothership timeline toolbar Refresh (avoids long HTTP runs). Lex legislation
     * still updates via cron or Settings → Diagnostics.
     */
    public function refreshAllRemote(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $expected = seismoRemoteRefreshKey();
        if ($expected === '') {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'remote refresh disabled']);

            return;
        }

        if (isSatellite()) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'this instance is a satellite; call the mothership']);

            return;
        }

        $provided = (string)($_GET['key'] ?? $_POST['key'] ?? '');
        if (!hash_equals($expected, $provided)) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'invalid key']);

            return;
        }

        set_time_limit(300);

        $pdo = getDbConnection();
        $config = new SystemConfigRepository($pdo);
        $last = $config->get(self::KEY_LAST_REFRESH_AT);
        if ($last !== null && $last !== '' && ctype_digit($last) && (time() - (int)$last) < 60) {
            $remaining = 60 - (time() - (int)$last);
            http_response_code(429);
            echo json_encode([
                'ok' => false,
                'error' => "rate limited, retry in {$remaining}s",
                'retry_after' => $remaining,
            ]);

            return;
        }

        $startedAt = microtime(true);
        try {
            $results = RefreshAllService::boot($pdo)->runAll(true, true);
        } catch (RefreshMutexBusyException $e) {
            http_response_code(409);
            echo json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
                'busy' => true,
            ]);

            return;
        } catch (\Throwable $e) {
            error_log('Seismo refresh_all_remote: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);

            return;
        }

        $config->set(self::KEY_LAST_REFRESH_AT, (string)time());

        $hasErrors = false;
        $hasWarn = false;
        $messages = [];
        foreach ($results as $id => $r) {
            if ($r->status === 'error') {
                $hasErrors = true;
            }
            if ($r->status === 'warn') {
                $hasWarn = true;
            }
            if ($r->isOk()) {
                $messages[] = $id . ': ok (' . $r->count . ' items)';
            } elseif ($r->status === 'warn') {
                $messages[] = $id . ': partial (' . $r->count . ' items) — ' . (string)($r->message ?? '');
            } elseif ($r->status === 'skipped') {
                $messages[] = $id . ': skipped — ' . (string)($r->message ?? '');
            } else {
                $messages[] = $id . ': error — ' . (string)($r->message ?? '');
            }
        }

        $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
        echo json_encode([
            'ok' => !$hasErrors,
            'partial' => $hasWarn,
            'messages' => $messages,
            'elapsed_ms' => $elapsedMs,
        ]);
    }

    public function refreshPlugin(): void
    {
        if (!$this->guardPost()) {
            return;
        }

        $finish = function (): void {
            RefreshAjax::respondOrRedirect(function (): void {
                $this->redirectToDiagnostics();
            });
        };

        set_time_limit(300);

        $id = trim((string)($_POST['plugin_id'] ?? ''));
        $coreIds = [CoreRunner::ID_RSS, CoreRunner::ID_PARL_PRESS, CoreRunner::ID_SCRAPER, CoreRunner::ID_MAIL];
        $mediaCoreIds = [CoreRunner::ID_RSS_MEDIA, CoreRunner::ID_SCRAPER_MEDIA];
        $registry = new PluginRegistry();
        if ($id === ''
            || (!in_array($id, $coreIds, true)
                && !in_array($id, $mediaCoreIds, true)
                && !$registry->has($id))) {
            $_SESSION['error'] = 'Unknown plugin or core fetcher id.';
            $finish();

            return;
        }

        try {
            $pdo = getDbConnection();
            $refresh = RefreshAllService::boot($pdo);
            if (in_array($id, $mediaCoreIds, true)) {
                $result = $refresh->runMediaCoreFetcher($id, true);
            } elseif (in_array($id, $coreIds, true)) {
                $result = $refresh->runCoreFetcher($id, true);
            } else {
                $result = $refresh->runPlugin($id, true);
            }
        } catch (RefreshMutexBusyException $e) {
            $_SESSION['error'] = $e->getMessage();
            $finish();

            return;
        } catch (\Throwable $e) {
            error_log('Seismo diagnostics refresh_plugin: ' . $e->getMessage());
            $_SESSION['error'] = 'Refresh ' . $id . ' failed: ' . $e->getMessage();
            $finish();

            return;
        }

        if ($result->isOk()) {
            $_SESSION['success'] = sprintf('Refresh %s: %d row(s) processed.', $id, $result->count);
        } elseif ($result->status === 'warn') {
            $_SESSION['success'] = sprintf(
                'Refresh %s: %d row(s) processed. %s',
                $id,
                $result->count,
                $result->message ?? ''
            );
        } elseif ($result->status === 'skipped') {
            $_SESSION['error'] = 'Refresh ' . $id . ' skipped: ' . ($result->message ?? '');
        } else {
            $_SESSION['error'] = 'Refresh ' . $id . ' failed: ' . ($result->message ?? 'unknown error');
        }

        $finish();
    }

    public function test(): void
    {
        if (!$this->guardPost()) {
            return;
        }

        $id = trim((string)($_POST['plugin_id'] ?? ''));
        if ($id === '' || !(new PluginRegistry())->has($id)) {
            $_SESSION['error'] = 'Unknown plugin id.';
            $this->redirectToDiagnostics();

            return;
        }

        try {
            $pdo = getDbConnection();
            $peek = RefreshAllService::boot($pdo)->testPlugin($id, 5);
        } catch (\Throwable $e) {
            error_log('Seismo diagnostics test: ' . $e->getMessage());
            $_SESSION['error'] = 'Test ' . $id . ' failed: ' . $e->getMessage();
            $this->redirectToDiagnostics();

            return;
        }

        $_SESSION['plugin_test_result'] = [
            'id'    => $id,
            'count' => $peek['count'],
            'error' => $peek['error'],
            'items' => $peek['items'],
        ];

        $this->redirectToDiagnostics();
    }

    private function guardPost(): bool
    {
        $redirect = function (): void {
            $this->redirectToTarget($this->resolvePostReturnTarget());
        };

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            if (RefreshAjax::wantsJson()) {
                RefreshAjax::json(false, null, 'Method not allowed', 405);
            }
            $redirect();

            return false;
        }
        if (!CsrfToken::verifyRequest(rotateOnSuccess: false)) {
            $_SESSION['error'] = 'Session expired — please try again.';
            RefreshAjax::respondOrRedirect($redirect);

            return false;
        }

        return true;
    }

    /**
     * Timeline / filter toolbar Refresh posts `return_action` index|filter; skip Lex
     * plugins so the request finishes within upstream HTTP timeouts. Diagnostics
     * omits `return_action` (full run including Lex).
     */
    private function timelineRefreshRequestsSkipLex(): bool
    {
        $t = trim((string)($_POST['return_action'] ?? ''));

        return $t === 'index' || $t === 'filter';
    }

    /**
     * After `refresh_all`, honour optional `return_action` from the POST body
     * (dashboard “Refresh” posts `index`; Diagnostics omits the field).
     */
    private function redirectAfterRefresh(): void
    {
        $this->redirectToTarget($this->resolvePostReturnTarget());
    }

    private function resolvePostReturnTarget(): string
    {
        $t = trim((string)($_POST['return_action'] ?? ''));

        if ($t === 'index') {
            return 'index';
        }
        if ($t === 'filter') {
            return 'filter';
        }

        return 'settings_diagnostics';
    }

    private function redirectToTarget(string $action): void
    {
        $bp = getBasePath();
        if ($action === 'index') {
            header('Location: ' . $bp . '/index.php?action=index', true, 303);
            exit;
        }
        if ($action === 'filter') {
            header('Location: ' . $bp . '/index.php?action=filter', true, 303);
            exit;
        }
        header('Location: ' . $bp . '/index.php?action=settings&tab=diagnostics', true, 303);
        exit;
    }

    private function redirectToDiagnostics(): void
    {
        header('Location: ' . getBasePath() . '/index.php?action=settings&tab=diagnostics', true, 303);
        exit;
    }

    /**
     * @param array<string, array{label: string, min_interval: int, entry_type: string}> $metaById
     * @param array<string, ?array{status: string, run_at: \DateTimeImmutable, item_count: int, error_message: ?string, duration_ms: int}> $latest
     * @param ?PluginRunLogRepository $log
     *
     * @return array<string, array<string, mixed>>
     */
    private static function buildFetcherStatusMap(
        array $metaById,
        array $latest,
        DateTimeImmutable $now,
        bool $mailErrorCountsAsThrottle,
        ?PluginRunLogRepository $log = null,
    ): array {
        $out = [];
        foreach ($metaById as $id => $meta) {
            $row = $latest[$id] ?? null;
            $minInterval = (int)$meta['min_interval'];
            $nextAllowed = null;
            if ($row !== null && $minInterval > 0) {
                if (in_array($row['status'], ['ok', 'warn'], true)
                    || ($mailErrorCountsAsThrottle && $id === CoreRunner::ID_MAIL && $row['status'] === 'error')) {
                    $nextAllowed = $row['run_at']->modify('+' . $minInterval . ' seconds');
                }
            }

            $lastAttempt = null;
            if ($row !== null && $row['status'] === 'skipped' && $log !== null) {
                $lastAttempt = $log->lastNonSkippedRun($id);
            }

            $out[$id] = [
                'id'           => $id,
                'label'        => $meta['label'],
                'entry_type'   => $meta['entry_type'],
                'config_key'   => '—',
                'min_interval' => $minInterval,
                'last'         => $row,
                'next_allowed' => $nextAllowed,
                'is_throttled' => $nextAllowed !== null && $nextAllowed > $now,
                'is_core'      => true,
                'last_attempt' => $lastAttempt,
            ];
        }

        return $out;
    }
}
