<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Seismo\Core\Mail\DigestSplitSelectorProber;
use Seismo\Core\Mail\EmailDigestSplitterService;

$html = <<<'HTML'
<html><body>
<div class="mj-column-per-100">
<table width="100%"><tbody><tr><td>
<table><tbody><tr><td>
<table><tbody><tr><td>
<table><tbody><tr><td>
<a style="font-weight:bold" href="https://example.com/1">Headline one is long enough</a>
</td></tr><tr><td style="font-size:12px">Body one has plenty of words in it for testing.</td></tr>
</tbody></table>
</td></tr></tbody></table>
</td></tr><tr><td>
<table><tbody><tr><td>
<a style="font-weight:bold" href="https://example.com/2">Headline two is long enough</a>
</td></tr><tr><td style="font-size:12px">Body two has plenty of words in it for testing.</td></tr>
</tbody></table>
</td></tr></tbody></table>
</td></tr></tbody></table>
</td></tr></tbody></table>
</div>
</body></html>
HTML;

$gemini = [
    'split_method' => 'html_selector',
    'story_selector' => 'table[width="100%"] tbody tr td table tbody tr td table tbody tr td table tbody, div.mj-column-per-100 table',
    'title_selector' => 'a[style*="bold"], p[style*="font-size: 20px"], p[style*="font-size:20px"]',
    'link_selector' => 'a',
    'body_selector' => 'td[style*="font-size:12px"], p[style*="font-size: 16px"], p[style*="font-size:16px"]',
    'exclude_selectors' => ['img', 'td[height]', 'td[width="15"]'],
];

$s = new EmailDigestSplitterService();
$stories = $s->split($html, '', ['split_rules' => $gemini]);
echo 'Gemini selector: ' . count($stories) . "\n";

foreach ([
    'div.mj-column-per-100 table',
    'div.mj-column-per-100',
    'table table table td',
    'a[style*="font-weight:bold"]',
    'table[width="100%"] td',
] as $sel) {
    $r = $s->split($html, '', [
        'split_rules' => [
            'split_method' => 'html_selector',
            'story_selector' => $sel,
            'title_selector' => 'a',
            'link_selector' => 'a',
            'body_selector' => 'td, p',
        ],
    ]);
    echo $sel . ' => ' . count($r) . "\n";
}

$probe = (new DigestSplitSelectorProber())->probeBest($html);
echo "\nProber:\n";
var_export($probe);
