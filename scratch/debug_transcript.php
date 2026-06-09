<?php
$logPath = '/Users/oliverfuchs/.gemini/antigravity/brain/84803976-4208-4759-a30f-2c8b18efd03a/.system_generated/logs/transcript.jsonl';
if (!is_file($logPath)) {
    echo "Transcript log not found.\n";
    exit(1);
}

$lines = file($logPath);
for ($i = count($lines) - 1; $i >= 0; $i--) {
    $data = json_decode($lines[$i], true);
    if (($data['source'] ?? '') === 'USER_EXPLICIT' || ($data['type'] ?? '') === 'USER_INPUT') {
        $content = $data['content'] ?? '';
        echo "Source: " . ($data['source'] ?? '') . "\n";
        echo "Type: " . ($data['type'] ?? '') . "\n";
        echo "Length of content: " . strlen($content) . "\n";
        echo "Beginning of content:\n" . substr($content, 0, 500) . "\n";
        echo "End of content:\n" . substr($content, -500) . "\n";
        break;
    }
}
