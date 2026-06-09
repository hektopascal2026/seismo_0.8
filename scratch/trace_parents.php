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

$nodes = $xp->query("//*[contains(text(), 'Discussion about this post')]");
echo "Found " . $nodes->length . " matches:\n\n";

foreach ($nodes as $idx => $n) {
    echo "Match " . ($idx + 1) . ":\n";
    $p = $n;
    $path = [];
    while ($p !== null && $p->nodeName !== 'html') {
        $path[] = $p->nodeName . ($p->getAttribute('class') ? '.' . str_replace(' ', '.', $p->getAttribute('class')) : '') . ($p->getAttribute('id') ? '#' . $p->getAttribute('id') : '');
        $p = $p->parentNode;
    }
    echo implode(" -> ", array_reverse($path)) . "\n";
    echo "----------------------------------------\n\n";
}
