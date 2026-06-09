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

echo "--- BUTTONS / CALL TO ACTION (CTA) ---\n";
$nodes = $xp->query("//*[contains(@class, 'button') or contains(@class, 'subscribe') or contains(@class, 'share') or contains(@class, 'cta')]");
foreach ($nodes as $n) {
    echo "<" . $n->nodeName . " class='" . $n->getAttribute('class') . "'> " . trim($n->textContent) . "\n";
}

echo "\n--- FOOTER / HEADER / NOISE CLASSES ---\n";
// Let's look for headers/footers
$nodes = $xp->query("//header | //footer | //div[contains(@class, 'header')] | //div[contains(@class, 'footer')] | //div[contains(@class, 'menu')] | //div[contains(@class, 'nav')]");
foreach (array_slice(iterator_to_array($nodes), 0, 15) as $n) {
    echo "<" . $n->nodeName . " class='" . $n->getAttribute('class') . "'> " . substr(trim($n->textContent), 0, 100) . "\n";
}
