<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Core/Fetcher/ArticlePageBodyExtractor.php';
require_once __DIR__ . '/../src/Core/Fetcher/ScraperContentExtractor.php';

use Seismo\Core\Fetcher\ArticlePageBodyExtractor;
use Seismo\Core\Fetcher\ScraperContentExtractor;

$url = 'https://swissmacroandhistory.substack.com/p/the-ecbs-june-gamble';
$ua = 'Seismo/0.8.0 (+https://hektopascal.org)';

$options = [
    'http' => [
        'method' => 'GET',
        'header' => "User-Agent: {$ua}\r\n",
        'timeout' => 30,
        'follow_location' => 1,
    ]
];
$ctx = stream_context_create($options);
$html = file_get_contents($url, false, $ctx);

echo "HTML length with Seismo UA: " . strlen($html) . "\n";

$best = ArticlePageBodyExtractor::extractBestArticleBody($html);
echo "extractBestArticleBody length: " . strlen($best) . "\n";
echo "Best body preview:\n" . substr(strip_tags($best), 0, 1000) . "\n";
