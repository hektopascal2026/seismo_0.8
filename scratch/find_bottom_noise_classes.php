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

echo "--- MATCHING TEXTS ---\n";
foreach (["Discussion", "No posts", "Ready for more"] as $term) {
    $nodes = $xp->query("//*[contains(text(), '{$term}')]");
    foreach ($nodes as $n) {
        $p = $n;
        while ($p !== null && $p->nodeName !== 'body') {
            echo "MATCH '{$term}': <" . $p->nodeName . " class='" . $p->getAttribute('class') . "'>\n";
            $p = $p->parentNode;
        }
        echo "------------------\n";
    }
}
