<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Core/Fetcher/RssFetchService.php';
require_once __DIR__ . '/../src/Core/Fetcher/RssFeedItemFilter.php';
require_once __DIR__ . '/../src/Core/Fetcher/ArticleLinkNormalizer.php';
require_once __DIR__ . '/../src/Core/Fetcher/GoogleNewsArticleUrlResolver.php';

$url = 'https://swissmacroandhistory.substack.com/feed';
$rss = new Seismo\Core\Fetcher\RssFetchService();
$items = $rss->fetchFeedItems($url);

foreach ($items as $item) {
    if (strpos($item['link'], 'the-ecbs-june-gamble') !== false) {
        echo "TITLE: " . $item['title'] . "\n";
        echo "LINK: " . $item['link'] . "\n";
        echo "CONTENT LENGTH: " . strlen($item['content']) . "\n";
        echo "CONTENT PLAIN LENGTH: " . strlen(strip_tags($item['content'])) . "\n";
        echo "CONTENT RAW START:\n" . substr($item['content'], 0, 1000) . "\n...\n";
        echo "CONTENT RAW END:\n" . substr($item['content'], -1000) . "\n";
    }
}
