<?php
/**
 * CLI recipe rescore for one path satellite desk (manual / debug).
 *
 *   php bin/seismo-satellite-rescore.php security
 *
 * For production, prefer one mothership cron line (all desks from registry):
 *
 *   php /var/www/seismo/satellite_rescore_cron.php
 *
 * Requires SEISMO_SATELLITE_SLUG (set below) and DB grants on seismo_<slug>.
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

if (!isSatellite()) {
    fwrite(STDERR, "Invalid satellite slug.\n");
    exit(1);
}

try {
    $result = ScoringService::rescoreStoredRecipeRounds(getDbConnection());
} catch (\Throwable $e) {
    fwrite(STDERR, 'Rescore failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($result === null) {
    fwrite(STDERR, "No recipe_json on desk " . seismoScoresDbName() . ".\n");
    exit(2);
}

$c = $result['counts'];
$total = $c['feed_items'] + $c['lex_items'] + $c['emails'] + $c['calendar_events'];
echo sprintf(
    "[%s] %d entries scored in %d pass(es) (feeds %d, lex %d, mail %d, leg %d)\n",
    seismoScoresDbName(),
    $total,
    $result['rounds'],
    $c['feed_items'],
    $c['lex_items'],
    $c['emails'],
    $c['calendar_events'],
);

exit(0);
