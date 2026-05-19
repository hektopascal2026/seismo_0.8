<?php
/**
 * Master Cron for Seismo 0.5.
 *
 * This is the ONLY cron job a shared-host admin needs to register. Suggested
 * Plesk entry (runs every 5 minutes):
 *
 *   *\/5 * * * *  /usr/bin/php /path/to/seismo/refresh_cron.php
 *
 * The script is a thin shell around RefreshAllService::runAll(). Per-plugin
 * throttling lives inside the service: plugins whose
 * getMinIntervalSeconds() hasn't elapsed since the last successful run (`ok` or `warn`) are
 * skipped silently (stdout only, no DB row). Anything else — success, error,
 * "satellite mode", "disabled in config" — is persisted to plugin_run_log
 * and visible at Settings → Diagnostics (?action=settings&tab=diagnostics).
 *
 * Hard rules:
 *   - CLI only. A browser-triggered run would be a DoS vector; we refuse.
 *   - Satellite mode is a no-op. Satellites read entries cross-DB from the
 *     mothership; they have no upstreams to refresh.
 *   - Overlap guard: a MySQL advisory lock ({@see CronMutexRepository}) ensures
 *     only one master-cron tick runs per database at a time. If your scheduler
 *     fires more often than a tick can finish (e.g. every minute with slow/chunked
 *     work), overlapping invocations exit 0 immediately instead of duplicating fetches.
 *     Web UI refresh uses the same lock via {@see RefreshAllService} so manual refresh
 *     cannot race cron on chunked RSS/scraper cursors.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "refresh_cron.php is CLI-only. Use ?action=refresh_all from the web UI (protected by auth + CSRF).\n";
    exit(1);
}

require_once __DIR__ . '/bootstrap.php';

use Seismo\Repository\CronMutexRepository;
use Seismo\Service\RefreshAllService;
use Seismo\Service\RetentionService;

// Tee every cron line to both the original stream (so Plesk keeps
// emailing the master cron output) AND a persistent log file on disk.
// Plesk's notification emails truncate long output, so when the admin
// wants the FULL tick (e.g. retention totals, post-run diagnostics),
// they open `logs/refresh_cron.log` from Plesk File Manager and get
// everything.
//
// Failure to open the file is non-fatal: cron continues printing to
// stdout/stderr exactly like before. We never let logging get in the
// way of the actual work.
$seismoLogPath = SEISMO_ROOT . '/logs/refresh_cron.log';
@mkdir(dirname($seismoLogPath), 0755, true);
// Single-sidecar rotation: when the main log crosses 1 MB, move it to
// `.1` (overwriting any previous rotation) and start fresh. Keeps disk
// usage bounded without pulling in a logging library.
if (is_file($seismoLogPath) && filesize($seismoLogPath) > 1048576) {
    @rename($seismoLogPath, $seismoLogPath . '.1');
}
$seismoLogFh = @fopen($seismoLogPath, 'ab');

/**
 * Emit a line to the requested stream AND the persistent log file.
 * Matches the pre-existing `fwrite(STDOUT|STDERR, …)` call sites one for
 * one so the visible cron output and the exit semantics don't change.
 */
$log = static function (string $line, bool $err = false) use ($seismoLogFh): void {
    fwrite($err ? STDERR : STDOUT, $line);
    if (is_resource($seismoLogFh)) {
        @fwrite($seismoLogFh, $line);
    }
};

if (isSatellite()) {
    $log("[seismo] satellite mode — refresh_cron skipped.\n");
    exit(0);
}

try {
    $pdo = getDbConnection();
} catch (\Throwable $e) {
    $log('[seismo] DB connection failed: ' . $e->getMessage() . "\n", true);
    exit(1);
}

$cronMutex = new CronMutexRepository($pdo);
if (!$cronMutex->tryAcquireRefreshCron()) {
    $log("[seismo] skipped — another refresh_cron tick is still running (advisory lock).\n");
    exit(0);
}
register_shutdown_function(static function () use ($cronMutex): void {
    try {
        $cronMutex->releaseRefreshCron();
    } catch (\Throwable) {
        // Connection may already be gone; MySQL also releases locks when the session ends.
    }
});

$start = microtime(true);
$log('[seismo] master cron tick @ ' . gmdate('Y-m-d\TH:i:s\Z') . "\n");
$log('[seismo] log file: ' . $seismoLogPath . "\n");

$results = RefreshAllService::boot($pdo)->runAll(false, false, true);

$errorCount = 0;
foreach ($results as $result) {
    if ($result->status === 'error') {
        $errorCount++;
    }
}

foreach ($results as $id => $result) {
    $line = sprintf(
        '[seismo] plugin %-10s %-8s %s%s',
        $id,
        $result->status,
        ($result->status === 'ok' || $result->status === 'warn') ? ('count=' . $result->count) : '',
        $result->message !== null ? ' msg=' . $result->message : ''
    );
    $log($line . "\n");
}

// Retention: prune entry-source rows past their family's retention
// cutoff, respecting keep-predicates (favourites, high scores, labels).
// Mothership only — RetentionService::pruneAll() short-circuits on
// satellites. Kept inside try/catch so a broken retention query never
// masks upstream plugin errors in the exit code.
try {
    $pruned = RetentionService::boot($pdo)->pruneAll();
    if ($pruned === []) {
        $log("[seismo] retention: no policies active (all families unlimited).\n");
    } else {
        $total = array_sum($pruned);
        foreach ($pruned as $family => $n) {
            $log(sprintf("[seismo] retention %-16s deleted=%d\n", $family, $n));
        }
        $log("[seismo] retention: deleted {$total} row(s) total.\n");
    }
} catch (\Throwable $e) {
    $log('[seismo] retention failed: ' . $e->getMessage() . "\n", true);
    // Do not exit here — a retention failure should not mask an otherwise
    // successful plugin run. The error is logged via STDERR / error_log.
    error_log('Seismo retention cron: ' . $e->getMessage());
}

$duration = (int)((microtime(true) - $start) * 1000);
$log("[seismo] master cron done in {$duration}ms\n");
if ($errorCount > 0) {
    $log("[seismo] master cron exiting with code 2 ({$errorCount} plugin error(s)).\n", true);
    exit(2);
}
exit(0);
