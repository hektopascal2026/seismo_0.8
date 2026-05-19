<?php

declare(strict_types=1);

/**
 * Plesk / panel cron: HTTP GET this file with ?token=...
 * Example: https://www.example.org/seismo/newsbridge/cron.php?token=YOUR_CRON_TOKEN
 *
 * (Same security model as historical v0.4 staging `newsbridge/cron.php`.)
 */
header('Content-Type: text/plain; charset=UTF-8');

$configPath = __DIR__ . '/config.local.php';
if (!is_readable($configPath)) {
    http_response_code(500);
    echo "Missing newsbridge/config.local.php (copy from config.example.php)\n";
    exit(1);
}

/** @var array $config */
$config = require $configPath;

$token = $_GET['token'] ?? '';
if (!is_string($token) || !hash_equals((string) ($config['cron_token'] ?? ''), $token)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit(1);
}

$needExt = static function (array $names): void {
    $missing = [];
    foreach ($names as $name) {
        if (!extension_loaded($name)) {
            $missing[] = $name;
        }
    }
    if ($missing !== []) {
        http_response_code(500);
        echo 'Missing PHP extension(s): ' . implode(', ', $missing) . "\n";
        echo "newsbridge needs: curl, pdo_sqlite, dom\n";
        exit(1);
    }
};

$needExt(['curl', 'pdo_sqlite', 'dom']);

$key = trim((string) ($config['newsapi_key'] ?? ''));
if ($key === '' || $key === 'YOUR_NEWSAPI_ORG_KEY') {
    http_response_code(500);
    echo "Set newsapi_key in newsbridge/config.local.php\n";
    exit(1);
}

$base = (string) ($config['newsapi_base'] ?? 'https://newsapi.org/v2');
$timeout = (int) ($config['http_timeout'] ?? 25);

if (isset($_GET['diagnose']) && (string) $_GET['diagnose'] === '1') {
    require_once __DIR__ . '/lib/NewsApiClient.php';
    $realConfig = @realpath($configPath) ?: $configPath;
    $dir = @realpath(__DIR__) ?: __DIR__;
    $bom = '';
    $rawHead = @file_get_contents($configPath, false, null, 0, 4) ?: '';
    if (strncmp($rawHead, "\xEF\xBB\xBF", 3) === 0) {
        $bom = "WARNING: config.local.php starts with UTF-8 BOM — re-save as UTF-8 without BOM.\n";
    }
    $len = strlen($key);
    $suffix = $len >= 4 ? substr($key, -4) : '(key shorter than 4 chars)';
    $client = new NewsApiClient($key, $base, $timeout);
    $probe = $client->get('sources', ['pageSize' => '5']);

    echo "=== newsbridge diagnose ===\n";
    echo $bom;
    echo "cron.php directory: {$dir}\n";
    echo "config loaded from: {$realConfig}\n";
    echo "newsapi_key length: {$len} bytes\n";
    echo "newsapi_key last 4 chars: {$suffix} (compare to NewsAPI dashboard; should match exactly)\n";
    echo "newsapi_base: {$base}\n";
    echo "\n--- Probe: GET /v2/sources (minimal) ---\n";
    if ($probe['ok']) {
        echo "OK — key is accepted by News API from this host.\n";
        $total = $probe['data']['sources'] ?? [];
        echo 'sources returned: ' . (is_array($total) ? count($total) : 0) . " rows in this page\n";
    } else {
        echo "FAIL — HTTP {$probe['http_code']}: {$probe['error']}\n";
    }
    exit($probe['ok'] ? 0 : 1);
}

require_once __DIR__ . '/engine.php';

try {
    exit(seismo_newsbridge_run($config));
} catch (Throwable $e) {
    http_response_code(500);
    echo 'newsbridge fatal: ' . $e->getMessage() . "\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n";
    exit(1);
}
