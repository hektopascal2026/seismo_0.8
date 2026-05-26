<?php
/**
 * Recipe rescore cron for all path satellites (mothership CLI only).
 *
 * Reads Settings → Satellites registry (`satellites_registry`) and runs
 * {@see \Seismo\Core\Scoring\ScoringService::rescoreStoredRecipeRounds()} on each
 * desk scores DB. New desks are picked up automatically — no per-slug cron lines.
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

use Seismo\Core\Scoring\ScoringService;
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

$errors = 0;
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

    $scoresDb = trim((string)($sat['db_name'] ?? ''));
    if ($scoresDb === '') {
        $scoresDb = 'seismo_' . $slug;
    }

    try {
        $deskPdo = seismoPdoForScoresCatalog($scoresDb);
    } catch (\Throwable $e) {
        $errors++;
        $log("[seismo] desk {$slug} ({$scoresDb}): connect failed — " . $e->getMessage() . "\n", true);
        continue;
    }

    try {
        $result = ScoringService::rescoreStoredRecipeRounds($deskPdo);
    } catch (\Throwable $e) {
        $errors++;
        $log("[seismo] desk {$slug}: rescore failed — " . $e->getMessage() . "\n", true);
        continue;
    }

    if ($result === null) {
        $log("[seismo] desk {$slug} ({$scoresDb}): no recipe_json — skipped\n");
        continue;
    }

    $c     = $result['counts'];
    $total = $c['feed_items'] + $c['lex_items'] + $c['emails'] + $c['calendar_events'];
    $log(sprintf(
        "[seismo] desk %s (%s): %d scored in %d pass(es) (feeds %d, lex %d, mail %d, leg %d)\n",
        $slug,
        $scoresDb,
        $total,
        (int)$result['rounds'],
        $c['feed_items'],
        $c['lex_items'],
        $c['emails'],
        $c['calendar_events'],
    ));
}

exit($errors > 0 ? 1 : 0);
