<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Core/Fetcher/ArticlePageBodyExtractor.php';
require_once __DIR__ . '/../src/Core/Fetcher/ScraperContentExtractor.php';

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

$exclude = <<<'SEL'
header
nav
footer
[id="discussion"]
[class*="pubInvertedTheme"]
.main-menu
.navbar-buttons
.post-header
.post-footer
.subscription-widget
.subscribe-widget
.button-wrapper
.post-ufi-button
.like-button-container
.footer-wrap
.publication-footer
.footer
.single-post-section
.comments-page
.portable-archive
.comments
.comment-section
.comments-section
.subscribe-section
button
SEL;

$candidates = [];

$jsonLd = ArticlePageBodyExtractor::extractJsonLdArticleBody($html);
if ($jsonLd !== '') {
    $candidates[] = ['source' => 'json_ld', 'content' => $jsonLd];
}

$read = ScraperContentExtractor::extractReadableContent($html, $exclude);
$readable = trim($read['content'] ?? '');
if ($readable !== '') {
    $candidates[] = ['source' => 'readability', 'content' => $readable];
}

$og = ArticlePageBodyExtractor::extractBestArticleBody($html); // wait, let's call private method using Reflection if needed, or just print candidate info
echo "JSON-LD length: " . strlen($jsonLd) . "\n";
echo "Readability length: " . strlen($readable) . "\n";

$best = ArticlePageBodyExtractor::pickBestCandidate($candidates);
echo "Best Candidate source chose length: " . strlen($best) . "\n";
echo "--- PLAIN BODY PREVIEW ---\n";
echo ArticlePageBodyExtractor::toPlainText($best) . "\n";
echo "--------------------------\n";
