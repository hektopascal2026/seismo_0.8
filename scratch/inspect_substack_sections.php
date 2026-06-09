<?php
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

$nodes = $xp->query("//div[contains(@class, 'single-post-section')]");
echo "Found " . $nodes->length . " elements with class 'single-post-section':\n\n";
foreach ($nodes as $idx => $n) {
    echo "Section " . ($idx + 1) . ":\n";
    echo "Class: " . $n->getAttribute('class') . "\n";
    echo "HTML preview:\n" . substr(trim($n->textContent), 0, 200) . "...\n";
    echo "----------------------------------------\n\n";
}
