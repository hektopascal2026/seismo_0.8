<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Seismo\Core\Mail\EmailDigestSplitterService;
use Seismo\Core\Mail\DigestSplitStructureHint;

$raw = stream_get_contents(STDIN);
if ($raw === false || $raw === '') {
    fwrite(STDERR, "Paste raw email on stdin\n");
    exit(1);
}

// Strip headers — keep HTML from first <!DOCTYPE or <html
if (preg_match('/(<!DOCTYPE html[\s\S]*|<html[\s\S]*)/i', $raw, $m)) {
    $html = $m[1];
} else {
    $html = $raw;
}

// Decode quoted-printable soft breaks and =XX escapes
$html = quoted_printable_decode($html);

$splitter = new EmailDigestSplitterService();
$hint = new DigestSplitStructureHint();

echo "HTML length: " . strlen($html) . "\n\n";
echo "=== Structure hints ===\n";
foreach ($hint->candidatesFromHtml($html) as $row) {
    echo $row['selector'] . ' => ' . $row['count'] . "\n";
}

$candidates = [
    ['story' => 'div.csc-frame-default', 'title' => 'h1', 'link' => 'a', 'body' => 'p.bodytext'],
    ['story' => 'div.csc-frame', 'title' => 'h1.csc-firstHeader', 'link' => 'a', 'body' => 'p.bodytext'],
    ['story' => 'div.csc-frame-default', 'title' => 'h1.csc-firstHeader', 'link' => 'a.more', 'body' => 'p.bodytext'],
    ['story' => 'p.bodytext', 'title' => 'h1', 'link' => 'a', 'body' => ''],
    ['story' => 'table table table table td', 'title' => 'a', 'link' => 'a', 'body' => 'td'],
    ['story' => 'table table table td', 'title' => 'a', 'link' => 'a', 'body' => 'td'],
    ['story' => 'table table td', 'title' => 'a', 'link' => 'a', 'body' => 'td'],
    ['story' => 'a', 'title' => '', 'link' => '', 'body' => ''],
];

echo "\n=== Selector probes ===\n";
foreach ($candidates as $rules) {
    $config = [
        'is_digest' => true,
        'split_rules' => array_merge(['split_method' => 'html_selector'], $rules),
    ];
    $stories = $splitter->split($html, '', $config);
    $titles = array_slice(array_map(static fn ($s) => mb_substr($s['title'], 0, 50), $stories), 0, 4);
    echo sprintf(
        "%-45s => %2d  %s\n",
        $rules['story'],
        count($stories),
        $titles !== [] ? json_encode($titles, JSON_UNESCAPED_UNICODE) : ''
    );
}

// Gemini's broken config
$gemini = [
    'split_method' => 'html_selector',
    'story_selector' => 'table table table table td[style*="vertical-align:baseline"]',
    'title_selector' => 'a[style*="font-weight:bold"]',
    'link_selector' => 'a[style*="font-weight:bold"]',
    'body_selector' => 'td[style*="color:#262626"]',
];
$stories = $splitter->split($html, '', ['is_digest' => true, 'split_rules' => $gemini]);
echo "\nGemini config => " . count($stories) . " cards\n";
