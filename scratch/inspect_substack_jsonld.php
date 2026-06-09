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

if (preg_match_all('#<script[^>]+type=(["\'])application/ld\+json\1[^>]*>(.*?)</script>#is', $html, $matches)) {
    echo "Found " . count($matches[2]) . " JSON-LD script tags:\n\n";
    foreach ($matches[2] as $idx => $json) {
        echo "Tag " . ($idx + 1) . ":\n";
        $data = json_decode(trim($json), true);
        if (json_last_error() === JSON_ERROR_NONE) {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        } else {
            echo "Failed to decode JSON: " . json_last_error_msg() . "\n";
            echo substr(trim($json), 0, 500) . "...\n";
        }
        echo "----------------------------------------\n\n";
    }
} else {
    echo "No JSON-LD script tags found!\n";
}
