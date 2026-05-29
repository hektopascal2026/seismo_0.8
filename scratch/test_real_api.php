<?php
$url = 'https://ec.europa.eu/commission/presscorner/api/pressrelease?reference=IP/24/2202&language=en';
echo "Fetching: $url\n";
$options = [
    'http' => [
        'method' => 'GET',
        'header' => "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)\r\n",
        'timeout' => 10,
        'follow_location' => 1,
    ]
];
$ctx = stream_context_create($options);
$html = @file_get_contents($url, false, $ctx);
if ($html === false) {
    $headers = $http_response_header ?? [];
    echo "Failed: " . ($headers[0] ?? 'unknown error') . "\n";
} else {
    echo "Success! Length: " . strlen($html) . "\n";
    echo "Preview: " . substr($html, 0, 500) . "\n\n";
}
