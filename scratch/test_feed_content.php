<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Core/Fetcher/RssFetchService.php';
require_once __DIR__ . '/../src/Core/Fetcher/RssFeedItemFilter.php';
require_once __DIR__ . '/../src/Core/Fetcher/ArticleLinkNormalizer.php';
require_once __DIR__ . '/../src/Core/Fetcher/GoogleNewsArticleUrlResolver.php';

$url = 'https://swissmacroandhistory.substack.com/feed';
$rss = new Seismo\Core\Fetcher\RssFetchService();
$items = $rss->fetchFeedItems($url);

echo "Total items found: " . count($items) . "\n\n";

foreach (array_slice($items, 0, 3) as $item) {
    echo "TITLE: " . $item['title'] . "\n";
    echo "LINK: " . $item['link'] . "\n";
    echo "DESC RAW (length " . strlen($item['description']) . "):\n" . substr($item['description'], 0, 200) . "...\n";
    echo "CONTENT RAW (length " . strlen($item['content']) . "):\n" . substr($item['content'], 0, 200) . "...\n";
    echo "----------------------------------------\n\n";
}
