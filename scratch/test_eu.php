<?php
$url = 'https://ec.europa.eu/commission/presscorner/detail/en/ip_26_1166';
echo "Fetching via stream:\n";
$options = [
    'http' => [
        'method' => 'GET',
        'header' => "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)\r\n",
        'timeout' => 30,
        'follow_location' => 1,
    ]
];
$ctx = stream_context_create($options);
$body = file_get_contents($url, false, $ctx);
if ($body === false) {
    echo "Stream fetch failed\n";
} else {
    echo "Stream fetch success! Body length: " . strlen($body) . "\n";
}
