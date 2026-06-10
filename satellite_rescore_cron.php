<?php
/**
 * Recipe rescore cron for all path satellites (mothership CLI only).
 *
 * Reads Settings → Satellites registry (`satellites_registry`) and runs
 * {@see bin/seismo-satellite-rescore.php} per desk so {@see entryTable()} resolves
 * entry sources in {@see SEISMO_ENTRIES_DB} while scores live in each desk DB.
 *
 * Suggested VPS entry (every 5 minutes, alongside refresh_cron.php):
 *
 *   0,5,10,15,20,25,30,35,40,45,50,55 * * * * /usr/bin/php /var/www/seismo/satellite_rescore_cron.php
 *
 * Requires `seismo_user` (or DB_USER) to have SELECT on `seismo.*` and ALL on each
 * `seismo_<slug>.*` (see bin/seismo-satellite-provision.sh).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "satellite_rescore_cron.php is CLI-only.\n";
    exit(1);
}

require_once __DIR__ . '/bootstrap.php';

use Seismo\Repository\CronMutexRepository;

if (isSatellite()) {
    fwrite(STDERR, "Run from the mothership tree without SEISMO_SATELLITE_SLUG.\n");
    exit(1);
}

$logPath = SEISMO_ROOT . '/logs/satellite_rescore_cron.log';
@mkdir(dirname($logPath), 0755, true);
if (is_file($logPath) && filesize($logPath) > 1048576) {
    @rename($logPath, $logPath . '.1');
}
$logFh = @fopen($logPath, 'ab');

$log = static function (string $line, bool $err = false) use ($logFh): void {
    fwrite($err ? STDERR : STDOUT, $line);
    if (is_resource($logFh)) {
        @fwrite($logFh, $line);
    }
};

try {
    $motherPdo = getDbConnection();
} catch (\Throwable $e) {
    $log('[seismo] DB connection failed: ' . $e->getMessage() . "\n", true);
    exit(1);
}

$mutex = new CronMutexRepository($motherPdo);
if (!$mutex->tryAcquireSatelliteRescoreCron()) {
    $log("[seismo] satellite rescore skipped — another tick is still running.\n");
    exit(0);
}
register_shutdown_function(static function () use ($mutex): void {
    try {
        $mutex->releaseSatelliteRescoreCron();
    } catch (\Throwable) {
    }
});

$registry = seismoSatellitesRegistry();
if ($registry === []) {
    $log("[seismo] satellite rescore @ " . gmdate('Y-m-d\TH:i:s\Z') . " — no desks in satellites_registry.\n");
    exit(0);
}

$log('[seismo] satellite rescore @ ' . gmdate('Y-m-d\TH:i:s\Z') . ' — ' . count($registry) . " desk(s)\n");
$log('[seismo] log file: ' . $logPath . "\n");

$phpBin     = PHP_BINARY;
$rescoreBin = SEISMO_ROOT . '/bin/seismo-satellite-rescore.php';
$errors     = 0;

foreach ($registry as $sat) {
    if (!is_array($sat)) {
        continue;
    }
    $slug = seismoNormaliseSatelliteSlug((string)($sat['slug'] ?? ''));
    if ($slug === '') {
        continue;
    }
    $status = (string)($sat['status'] ?? 'pending');
    if ($status === 'removed') {
        continue;
    }

    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($rescoreBin) . ' ' . escapeshellarg($slug);
    $output = [];
    $code   = 0;
    exec($cmd . ' 2>&1', $output, $code);

    foreach ($output as $line) {
        $log(rtrim($line, "\r\n") . "\n", $code !== 0 && $code !== 2);
    }

    if ($code === 2) {
        $scoresDb = trim((string)($sat['db_name'] ?? ''));
        if ($scoresDb === '') {
            $scoresDb = 'seismo_' . $slug;
        }
        $log("[seismo] desk {$slug} ({$scoresDb}): no recipe_json — skipped\n");
        continue;
    }

    if ($code !== 0) {
        $errors++;
    }
}

exit($errors > 0 ? 1 : 0);
