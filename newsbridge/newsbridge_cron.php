<?php
/**
 * Newsbridge — CLI: same News API → SQLite → RSS files as v0.4 / gaia `cron.php`
 * (without HTTP or token).
 *
 * Requirements: curl, pdo_sqlite, dom. Copy `config.example.php` to `config.local.php`
 * and set `newsapi_key` + `site_base_url` (and optional `cron_token` if you also use `cron.php`).
 *
 *   php /path/to/seismo/newsbridge/newsbridge_cron.php
 *
 * Plesk can run this path with PHP CLI, or use `cron.php?token=...` in the browser cron.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "newsbridge_cron.php is CLI-only. For HTTP cron use newsbridge/cron.php?token=…\n";
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
