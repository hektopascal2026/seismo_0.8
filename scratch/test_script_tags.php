<?php
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

echo "Searching for script tags:\n";
preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $html, $matches);
foreach ($matches[0] as $i => $fullTag) {
    echo "Script tag $i: " . substr($fullTag, 0, 300) . "... [len: " . strlen($fullTag) . "]\n";
}
