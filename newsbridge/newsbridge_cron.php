<?php
/**
 * Newsbridge — CLI: News API → SQLite → RSS files (feeds/*.xml).
 *
 * Requirements: curl, pdo_sqlite, dom. Copy `config.example.php` to `config.local.php`
 * and set `newsapi_key` + `site_base_url`.
 *
 *   php /path/to/seismo/newsbridge/newsbridge_cron.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "newsbridge_cron.php is CLI-only. Register it in the system crontab.\n";
    exit(1);
}

$configPath = __DIR__ . '/config.local.php';
if (!is_readable($configPath)) {
    fwrite(STDERR, "Missing newsbridge/config.local.php — copy from config.example.php\n");
    exit(1);
}

/** @var array $config */
$config = require $configPath;

foreach (['curl', 'pdo_sqlite', 'dom'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "Missing PHP extension: {$ext} (newsbridge needs curl, pdo_sqlite, dom)\n");
        exit(1);
    }
}

require_once __DIR__ . '/engine.php';

try {
    exit(seismo_newsbridge_run($config));
} catch (Throwable $e) {
    fwrite(STDERR, 'newsbridge fatal: ' . $e->getMessage() . "\n");
    exit(1);
}
