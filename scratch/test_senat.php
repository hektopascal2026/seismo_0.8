<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Seismo\Core\Fetcher\ArticlePageBodyExtractor;

$html = file_get_contents(__DIR__ . '/senat_test.html');
$best = ArticlePageBodyExtractor::extractBestArticleBody($html);
echo "--- EXTRACTED BODY ---\n";
echo $best . "\n";
echo "----------------------\n";
echo "Length: " . strlen($best) . "\n";
