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
$xp = new DOMXPath($dom);

$selectors = [
    'header', 'nav', 'footer', '#discussion', '.main-menu', '.navbar-buttons',
    '.post-header', '.post-footer', '.subscription-widget', '.subscribe-widget',
    '.button-wrapper', '.post-ufi-button', '.like-button-container', '.footer-wrap',
    '.publication-footer', '.footer', '.single-post-section', '.comments-page',
    '.portable-archive', '.comments', '.comment-section', '.comments-section',
    '.subscribe-section', 'button'
];

$extractor = new ReflectionClass('Seismo\Core\Fetcher\ScraperContentExtractor');
$method = $extractor->getMethod('dateSelectorToXPath');
$method->setAccessible(true);

foreach ($selectors as $sel) {
    $q = $method->invoke(null, $sel);
    $list = $xp->query($q);
    if ($list->length > 0) {
        echo "Selector '{$sel}' (XPath '{$q}') matched " . $list->length . " node(s).\n";
    }
}
