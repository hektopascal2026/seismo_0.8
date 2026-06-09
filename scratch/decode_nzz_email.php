<?php
$logPath = '/Users/oliverfuchs/.gemini/antigravity/brain/84803976-4208-4759-a30f-2c8b18efd03a/.system_generated/logs/transcript.jsonl';
if (!is_file($logPath)) {
    echo "Transcript log not found.\n";
    exit(1);
}

$lines = file($logPath);
$foundBase64 = null;
// Iterate backwards to find the last user message
for ($i = count($lines) - 1; $i >= 0; $i--) {
    $data = json_decode($lines[$i], true);
    if (($data['source'] ?? '') === 'USER_EXPLICIT' || ($data['type'] ?? '') === 'USER_INPUT') {
        $content = $data['content'] ?? '';
        if (preg_match('/MIME-Version: 1.0\s+Content-Transfer-Encoding: base64\s+(PCFET0NU[A-Za-z0-9\+\/=\s]+)/s', $content, $matches)) {
            $foundBase64 = preg_replace('/\s+/', '', $matches[1]);
            break;
        }
    }
}

if ($foundBase64) {
    $decoded = base64_decode($foundBase64);
    $outputPath = __DIR__ . '/nzz_email.html';
    file_put_contents($outputPath, $decoded);
    echo "Successfully decoded and saved email HTML to scratch/nzz_email.html (" . strlen($decoded) . " bytes)\n";
} else {
    echo "Could not extract base64 email content from transcript.\n";
}
