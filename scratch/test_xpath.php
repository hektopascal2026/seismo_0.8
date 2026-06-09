<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Core/Fetcher/ScraperContentExtractor.php';

$extractor = new ReflectionClass('Seismo\Core\Fetcher\ScraperContentExtractor');
$method = $extractor->getMethod('dateSelectorToXPath');
$method->setAccessible(true);

foreach (['[class*="pubInvertedTheme"]', '#discussion', '.single-post-section', 'header', 'footer'] as $sel) {
    echo "Selector '{$sel}' -> XPath: " . $method->invoke(null, $sel) . "\n";
}
