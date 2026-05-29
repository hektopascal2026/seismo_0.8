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
echo "HTML snippet (first 2000 chars):\n";
echo substr($html, 0, 2000) . "\n";
