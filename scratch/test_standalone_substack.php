<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Core/Fetcher/ScraperContentExtractor.php';
require_once __DIR__ . '/../src/Core/Fetcher/ArticlePageBodyExtractor.php';

use Seismo\Core\Fetcher\ArticlePageBodyExtractor;
use Seismo\Core\Fetcher\ScraperContentExtractor;

$url = 'https://swissmacroandhistory.substack.com/p/the-ecbs-june-gamble';
$options = [
    'http' => [
        'method' => 'GET',
        'header' => "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\n",
        'timeout' => 30,
        'follow_location' => 1,
    ]
];
$ctx = stream_context_create($options);
$html = file_get_contents($url, false, $ctx);

echo "HTML length: " . strlen($html) . "\n";

$jsonLd = ArticlePageBodyExtractor::extractJsonLdArticleBody($html);
echo "JSON-LD body length: " . strlen($jsonLd) . "\n";
if ($jsonLd !== '') {
    echo "JSON-LD Preview:\n" . substr($jsonLd, 0, 500) . "\n\n";
}

$read = ScraperContentExtractor::extractReadableContent($html);
$readable = trim((string)($read['content'] ?? ''));
echo "Readability body length: " . strlen($readable) . "\n";
if ($readable !== '') {
    echo "Readability Preview:\n" . substr(strip_tags($readable), 0, 500) . "\n\n";
}

$best = ArticlePageBodyExtractor::extractBestArticleBody($html);
echo "extractBestArticleBody length: " . strlen($best) . "\n";
echo "Best body preview:\n" . substr(strip_tags($best), 0, 1000) . "\n";
