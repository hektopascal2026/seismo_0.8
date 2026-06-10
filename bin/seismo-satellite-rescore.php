<?php
/**
 * CLI recipe rescore for one path satellite desk (manual / debug / mothership cron).
 *
 *   php bin/seismo-satellite-rescore.php security
 *
 * Sets {@see SEISMO_SATELLITE_SLUG} before bootstrap so entry-source SQL uses
 * {@see SEISMO_ENTRIES_DB} while scores/config use the desk catalog.
 *
 * For production, prefer one mothership cron line (all desks from registry):
 *
 *   php /var/www/seismo/satellite_rescore_cron.php
 *
 * Requires DB_USER to have SELECT on `seismo.*` and ALL on `seismo_<slug>.*`.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$slug = $argv[1] ?? '';
if ($slug === '') {
    fwrite(STDERR, "Usage: php bin/seismo-satellite-rescore.php <slug>\n");
    exit(1);
}

define('SEISMO_SATELLITE_SLUG', $slug);
require dirname(__DIR__) . '/bootstrap.php';

use Seismo\Core\Scoring\ScoringService;
use Seismo\Repository\EntryFavouriteRepository;
use Seismo\Repository\EntryScoreRepository;
use Seismo\Repository\MagnituLabelRepository;

if (!isSatellite()) {
    fwrite(STDERR, "Invalid satellite slug.\n");
    exit(1);
}

$scoresDb = seismoScoresDbName();

try {
    $pdo = getDbConnection();
} catch (\Throwable $e) {
    fwrite(STDERR, "desk {$scoresDb}: connect failed — " . $e->getMessage() . "\n");
    exit(1);
}

try {
    $result = ScoringService::rescoreStoredRecipeRounds($pdo);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Rescore failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($result === null) {
    fwrite(STDERR, "No recipe_json on desk {$scoresDb}.\n");
    exit(2);
}

try {
    $scoresOrphans = (new EntryScoreRepository($pdo))->pruneOrphans();
    $favsOrphans   = (new EntryFavouriteRepository($pdo))->pruneOrphans();
    $labelsOrphans = (new MagnituLabelRepository($pdo))->pruneOrphans();
    if ($scoresOrphans > 0 || $favsOrphans > 0 || $labelsOrphans > 0) {
        echo sprintf(
            "[seismo] desk %s: pruned orphans (scores: %d, favourites: %d, labels: %d)\n",
            $slug,
            $scoresOrphans,
            $favsOrphans,
            $labelsOrphans,
        );
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "desk {$slug}: pruning orphans failed — " . $e->getMessage() . "\n");
}

$c     = $result['counts'];
$total = $c['feed_items'] + $c['lex_items'] + $c['emails'] + $c['calendar_events'];
echo sprintf(
    "[seismo] desk %s (%s): %d scored in %d pass(es) (feeds %d, lex %d, mail %d, leg %d)\n",
    $slug,
    $scoresDb,
    $total,
    (int)$result['rounds'],
    $c['feed_items'],
    $c['lex_items'],
    $c['emails'],
    $c['calendar_events'],
);

exit(0);
