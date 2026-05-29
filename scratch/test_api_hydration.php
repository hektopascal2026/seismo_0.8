<?php
require_once __DIR__ . '/../bootstrap.php';

$url = 'https://ec.europa.eu/commission/presscorner/detail/en/ip_26_1166';
echo "Original URL: $url\n";

if (preg_match('#/detail/([a-z]{2})/([a-z0-9_-]+)#i', $url, $m)) {
    $lang = $m[1];
    $refRaw = $m[2];
    $parts = explode('_', $refRaw);
    $refFormatted = strtoupper($parts[0]) . '/' . implode('/', array_slice($parts, 1));
    
    $apiUrl = "https://ec.europa.eu/commission/presscorner/api/documents?reference=" . urlencode($refFormatted) . "&language=" . urlencode($lang);
    echo "Resolved API URL: $apiUrl\n";
    
    $options = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)\r\n",
            'timeout' => 10,
            'follow_location' => 1,
        ]
    ];
    $ctx = stream_context_create($options);
    $jsonStr = file_get_contents($apiUrl, false, $ctx);
    if ($jsonStr !== false) {
        $data = json_decode($jsonStr, true);
        $htmlContent = $data['docuLanguageResource']['htmlContent'] ?? '';
        $text = trim(html_entity_decode(strip_tags($htmlContent), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        echo "Extracted Text Length: " . strlen($text) . "\n";
        echo "--- TEXT PREVIEW ---\n";
        echo substr($text, 0, 500) . "\n";
        echo "--------------------\n";
    } else {
        echo "API Request Failed\n";
    }
} else {
    echo "No regex match\n";
}
