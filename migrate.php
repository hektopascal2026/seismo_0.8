<?php
/**
 * Seismo schema migrator (CLI only).
 *
 * Applies versioned migrations in `src/Migration/`. The base migration (17)
 * loads `docs/db-schema.sql` (consolidated 0.4 schema, all CREATE IF NOT EXISTS).
 *
 * Safe on your live database: if `system_config.schema_version` is already
 * at the latest, nothing runs except a quick version check. Table was
 * named `magnitu_config` before Migration 005 (Slice 5a); the runner
 * reads both names during the transition.
 *
 * Usage:
 *   php migrate.php           # apply pending migrations
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

$statusOnly = in_array('--status', $argv, true);

echo "Seismo migrate — " . SEISMO_VERSION . "\n";

try {
    $pdo = getDbConnection();
} catch (Throwable $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    exit(2);
}

$dbVersion = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
echo "Connected to MySQL {$dbVersion}\n";

$runner = new MigrationRunner($pdo);
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

if (isSatellite()) {
    fwrite(STDERR, "Migrations only run on the mothership. This instance is in satellite mode — skip migrate.php here.\n");
    exit(4);
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
