<?php

declare(strict_types=1);

/**
 * @param list<string> $allowedDomains
 */
function newsbridge_url_matches_domains(string $url, array $allowedDomains): bool
{
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return false;
    }
    $host = strtolower($host);
    foreach ($allowedDomains as $d) {
        if ($host === $d || str_ends_with($host, '.' . $d)) {
            return true;
        }
    }

    return false;
}

/**
 * Fetch from News API, store in SQLite, write feeds/{id}.xml. Same pipeline as
 * historical gaia/v0.4 newsbridge.
 *
 * @param array<string, mixed> $config from config.local.php
 * @return int exit code (0 = success)
 */
function seismo_newsbridge_run(array $config): int
{
    $base = __DIR__;
    require_once $base . '/lib/NewsApiClient.php';
    require_once $base . '/lib/Store.php';
    require_once $base . '/lib/RssWriter.php';

    $key = trim((string) ($config['newsapi_key'] ?? ''));
    if ($key === '' || $key === 'YOUR_NEWSAPI_ORG_KEY') {
        echo "Set newsapi_key in newsbridge/config.local.php\n";

        return 1;
    }

    $apiBase  = (string) ($config['newsapi_base'] ?? 'https://newsapi.org/v2');
    $timeout  = (int) ($config['http_timeout'] ?? 25);
    $pageSize = min(100, max(1, (int) ($config['page_size'] ?? 100)));
    $retentionDays = max(1, (int) ($config['retention_days'] ?? 14));
    $rssMax = max(10, (int) ($config['rss_max_items'] ?? 200));
    $siteBase = rtrim((string) ($config['site_base_url'] ?? ''), '/');

    /** @var mixed $streams */
    $streams = require $base . '/streams.php';
    if (!is_array($streams)) {
        echo "streams.php must return an array\n";

        return 1;
    }

    $dataDir = $base . '/data';
    if (!is_dir($dataDir) && !mkdir($dataDir, 0750, true) && !is_dir($dataDir)) {
        echo "Cannot create data/ directory (check permissions next to engine.php)\n";

        return 1;
    }

    $logFile = $dataDir . '/cron.log';
    $log     = static function (string $line) use ($logFile): void {
        $line = gmdate('Y-m-d H:i:s') . ' ' . $line . "\n";
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    };

    $sqlitePath = $dataDir . '/newsbridge.sqlite';
    $feedsDir   = $base . '/feeds';
    if (!is_dir($feedsDir) && !mkdir($feedsDir, 0755, true) && !is_dir($feedsDir)) {
        echo "Cannot create feeds directory\n";

        return 1;
    }

    try {
        $store = new Store($sqlitePath);
    } catch (Throwable $e) {
        echo 'Store init failed: ' . $e->getMessage() . "\n";

        return 1;
    }

    $client    = new NewsApiClient($key, $apiBase, $timeout);
    $cutoff    = gmdate('c', time() - $retentionDays * 86400);
    $store->prunePublishedBefore($cutoff);
    $fromDate  = gmdate('Y-m-d', time() - $retentionDays * 86400);
    $errors    = 0;
    $errorLines = [];

    foreach ($streams as $stream) {
        if (!is_array($stream)) {
            continue;
        }
        $id = (string) ($stream['id'] ?? '');
        if ($id === '' || preg_match('/[^a-z0-9_-]/i', $id)) {
            $log('skip invalid stream id');
            $errorLines[] = 'Invalid stream id in streams.php';
            ++$errors;

            continue;
        }

        $mode = (string) ($stream['mode'] ?? 'everything');
        $params = $stream['params'] ?? [];
        if (!is_array($params)) {
            $params = [];
        }

        $q = [];
        foreach ($params as $k => $v) {
            if (!is_string($k)) {
                continue;
            }
            if (is_int($v) || is_float($v)) {
                $q[$k] = (string) $v;
            } elseif (is_string($v)) {
                $q[$k] = $v;
            }
        }

        $q['pageSize'] = (string) $pageSize;
        $q['page'] = '1';

        if ($mode === 'everything') {
            $q['from'] = $fromDate;
            $path = 'everything';
        } elseif ($mode === 'top-headlines') {
            $path = 'top-headlines';
        } else {
            $log("stream {$id}: unknown mode {$mode}");
            $errorLines[] = "stream {$id}: unknown mode {$mode}";
            ++$errors;

            continue;
        }

        $res = $client->get($path, $q);
        if (!$res['ok']) {
            $log("stream {$id}: API error {$res['http_code']} — {$res['error']}");
            $errorLines[] = "stream {$id}: HTTP {$res['http_code']} — {$res['error']}";
            ++$errors;

            continue;
        }

        $data = $res['data'];
        $articles = $data['articles'] ?? [];
        if (!is_array($articles)) {
            $log("stream {$id}: no articles array");
            $errorLines[] = "stream {$id}: response missing articles[]";
            ++$errors;

            continue;
        }

        $store->deleteAllForStream($id);

        $domainAllow = [];
        if (isset($q['domains']) && is_string($q['domains']) && $q['domains'] !== '') {
            foreach (explode(',', $q['domains']) as $d) {
                $d = strtolower(trim($d));
                if ($d !== '') {
                    $domainAllow[] = $d;
                }
            }
        }

        $n = 0;
        $skippedDomain = 0;
        foreach ($articles as $article) {
            if (!is_array($article)) {
                continue;
            }
            $url = trim((string) ($article['url'] ?? ''));
            $title = trim((string) ($article['title'] ?? ''));
            if ($url === '' || $title === '') {
                continue;
            }
            if ($domainAllow !== [] && !newsbridge_url_matches_domains($url, $domainAllow)) {
                ++$skippedDomain;

                continue;
            }
            $desc = isset($article['description']) ? trim((string) $article['description']) : null;
            if ($desc === '') {
                $desc = null;
            }
            $published = trim((string) ($article['publishedAt'] ?? ''));
            if ($published === '') {
                $published = gmdate('c');
            }
            $source = $article['source'] ?? null;
            $sourceName = null;
            if (is_array($source) && isset($source['name'])) {
                $sourceName = trim((string) $source['name']);
                if ($sourceName === '') {
                    $sourceName = null;
                }
            }

            $store->upsertArticle($id, $url, $title, $desc, $published, $sourceName);
            ++$n;
        }

        $totalResults = $data['totalResults'] ?? null;
        if ($n === 0) {
            $log("stream {$id}: upserted 0 (API totalResults=" . ($totalResults !== null ? (string) $totalResults : 'n/a')
                . ', raw articles=' . count($articles)
                . ($skippedDomain > 0 ? ", skipped wrong host={$skippedDomain}" : '') . ')');
        } else {
            $log("stream {$id}: upserted {$n} articles"
                . ($skippedDomain > 0 ? " (dropped {$skippedDomain} not on domain allowlist)" : ''));
        }

        $channelTitle = (string) ($stream['channel_title'] ?? $id);
        $channelDesc = (string) ($stream['channel_description'] ?? '');
        $channelLink = $siteBase !== '' ? $siteBase : 'https://newsapi.org';
        $selfUrl = $channelLink . '/feeds/' . rawurlencode($id) . '.xml';

        $items = $store->listForStream($id, $rssMax);
        $xml = RssWriter::buildChannel($channelTitle, $channelDesc, $channelLink, $selfUrl, $items);
        $feedPath = $feedsDir . '/' . $id . '.xml';
        if (file_put_contents($feedPath, $xml) === false) {
            $log("stream {$id}: failed to write {$feedPath}");
            $errorLines[] = "stream {$id}: cannot write {$feedPath} (check feeds/ permissions)";
            ++$errors;
        }
    }

    if ($errors === 0) {
        echo "OK\n";
    } else {
        echo "Done with {$errors} error(s).\n";
        if ($errorLines !== []) {
            echo "\n";
            foreach ($errorLines as $line) {
                echo $line . "\n";
            }
        }
        echo "\nFull log: newsbridge/data/cron.log\n";
    }

    return $errors === 0 ? 0 : 1;
}
