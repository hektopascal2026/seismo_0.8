<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../bootstrap.php';

use Seismo\Service\Http\BaseClient;
use Seismo\Core\Fetcher\ArticlePageBodyExtractor;

$url = 'https://swissmacroandhistory.substack.com/p/the-ecbs-june-gamble';
$client = new BaseClient();

echo "Fetching via BaseClient...\n";
$res = $client->getWebPage($url);
echo "Response status: " . $res->status . "\n";
echo "Response body length: " . strlen($res->body) . "\n";

if ($res->isOk()) {
    $best = ArticlePageBodyExtractor::extractBestArticleBody($res->body);
    echo "Extracted length: " . strlen($best) . "\n";
    echo "Excerpt: " . substr(strip_tags($best), 0, 500) . "\n";
} else {
    echo "Request failed!\n";
}
