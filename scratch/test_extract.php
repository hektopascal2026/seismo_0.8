<?php
require_once __DIR__ . '/../bootstrap.php';
use Seismo\Core\Fetcher\ScraperContentExtractor;
use Seismo\Core\Mail\NewsletterBodyExtractor;
use Seismo\Core\PlainTextNormalizer;

$url = 'https://ec.europa.eu/commission/presscorner/detail/en/ip_26_1166';
$options = [
    'http' => [
        'method' => 'GET',
        'header' => "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)\r\n",
        'timeout' => 30,
        'follow_location' => 1,
    ]
];
$ctx = stream_context_create($options);
$html = file_get_contents($url, false, $ctx);

echo "HTML length: " . strlen($html) . "\n";

$read = ScraperContentExtractor::extractReadableContent($html);
$plain = trim((string)($read['content'] ?? ''));
echo "ScraperContentExtractor extracted length: " . strlen($plain) . "\n";

if (strlen($plain) < 180) {
    echo "Fell back to NewsletterBodyExtractor...\n";
    $plain = NewsletterBodyExtractor::fromHtml($html);
    echo "NewsletterBodyExtractor extracted length: " . strlen($plain) . "\n";
}

$normalized = PlainTextNormalizer::forIngest($plain);
echo "Normalized extracted length: " . strlen($normalized) . "\n";
echo "--- PREVIEW OF EXTRACTED BODY ---\n";
echo substr($normalized, 0, 1000) . "\n";
echo "---------------------------------\n";
