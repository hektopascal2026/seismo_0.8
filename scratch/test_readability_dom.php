<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Core/Fetcher/ScraperContentExtractor.php';

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

$dom = new DOMDocument();
@$dom->loadHTML('<?xml encoding="UTF-8">' . $html);

// Remove using reflection
$extractor = new ReflectionClass('Seismo\Core\Fetcher\ScraperContentExtractor');
$method = $extractor->getMethod('removeElementsMatchingExcludeSelectors');
$method->setAccessible(true);

$method->invoke(null, $dom, "#discussion\n.single-post-section");

$savedHtml = $dom->saveHTML();

echo "Is 'Discussion about this post' in saved HTML? " . (strpos($savedHtml, 'Discussion about this post') !== false ? 'YES' : 'NO') . "\n";
echo "Is 'TopLatestDiscussionsNo posts' in saved HTML? " . (strpos($savedHtml, 'No posts') !== false ? 'YES' : 'NO') . "\n";
