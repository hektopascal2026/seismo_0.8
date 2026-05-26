<?php
/**
 * Seismo schema migrator (CLI only).
 *
 * Default: mothership migrations on the database from config.local.php
 * (scores catalog = `seismo`, includes entry sources).
 *
 * Satellite scores DB:
 *   php migrate.php --scores-db=seismo_security
 *
 * Usage:
 *   php migrate.php           # apply pending mothership migrations
 *   php migrate.php --status  # print current schema version and exit
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "migrate.php is a CLI tool. Run it from a terminal:\n\n  php migrate.php\n";
    exit(1);
}

require __DIR__ . '/bootstrap.php';

use Seismo\Migration\MigrationRunner;
use Seismo\Migration\MigrationTarget;

$statusOnly = in_array('--status', $argv, true);
$scoresDb   = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--scores-db=')) {
        $scoresDb = substr($arg, strlen('--scores-db='));
    }
}

$target = MigrationTarget::Mothership;
if ($scoresDb !== null && $scoresDb !== '') {
    $target = MigrationTarget::Scores;
}

echo "Seismo migrate — " . SEISMO_VERSION . " ({$target->value})\n";

try {
    $pdo = seismoPdoForScoresCatalog(
        $scoresDb !== null && $scoresDb !== ''
            ? $scoresDb
            : (defined('SEISMO_ENTRIES_DB') ? (string)SEISMO_ENTRIES_DB : DB_NAME),
    );
} catch (Throwable $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    exit(2);
}

$dbVersion = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
$dbName    = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
echo "Connected to MySQL {$dbVersion} — database `{$dbName}`\n";

$runner = new MigrationRunner($pdo, $target);
$current = $runner->getCurrentVersion();
echo "Current schema version: {$current}\n";

if ($statusOnly) {
    echo "Latest built-in migration: " . MigrationRunner::LATEST_VERSION . "\n";
    exit(0);
}

if ($current >= MigrationRunner::LATEST_VERSION) {
    echo "Nothing to do — schema is already at version " . MigrationRunner::LATEST_VERSION . ".\n";
    exit(0);
}

try {
    $runner->run(static function (string $line): void {
        echo $line;
    });
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(3);
}

echo "Done.\n";
exit(0);
