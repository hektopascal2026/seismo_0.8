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

// Load DOM
$html = '<?xml encoding="UTF-8">' . $html;
$dom = new DOMDocument();
@$dom->loadHTML($html);

$xp = new DOMXPath($dom);
$q = "//*[@id='discussion']";
$list = $xp->query($q);
echo "XPath query '{$q}' matched " . $list->length . " nodes.\n";

foreach ($list as $n) {
    echo "Removing node: #" . $n->getAttribute('id') . "\n";
    $n->parentNode->removeChild($n);
}

// Let's see if the text 'Discussion about this post' is still in the DOM!
$list2 = $xp->query("//*[contains(text(), 'Discussion about this post')]");
echo "After removal, matches for 'Discussion about this post': " . $list2->length . "\n";
