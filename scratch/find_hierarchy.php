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

foreach (["Discussion about this post", "Ready for more?"] as $term) {
    echo "--- MATCHES FOR '{$term}' ---\n";
    $nodes = $xp->query("//*[contains(text(), '{$term}')]");
    foreach ($nodes as $n) {
        echo "Tag: " . $n->nodeName . "\n";
        echo "Class: " . $n->getAttribute('class') . "\n";
        echo "Parent Tag: " . $n->parentNode->nodeName . "\n";
        echo "Parent Class: " . $n->parentNode->getAttribute('class') . "\n";
        echo "Grandparent Tag: " . $n->parentNode->parentNode->nodeName . "\n";
        echo "Grandparent Class: " . $n->parentNode->parentNode->getAttribute('class') . "\n";
        echo "------------------\n";
    }
}
