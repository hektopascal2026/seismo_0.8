<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Seismo\Core\Mail\EmailDigestSplitterService;

$block = <<<'HTML'
<table width="100%"><tbody><tr><td>
<table width="100%"><tbody><tr><td style="vertical-align:baseline;">
<table width="100%"><tbody>
<tr><td style="vertical-align:baseline;"><a style="font-weight:bold" href="https://example.com/amag">AMAG Group reduziert CO2-Emissionen erneut deutlich</a></td></tr>
<tr><td style="color:#262626;font-size:12px;">Cham ZG - Die AMAG Group hat ihre CO2-Emissionen im Jahr 2025 reduziert. Mehr</td></tr>
</tbody></table>
</td></tr></tbody></table>
</td></tr></tbody></table>
HTML;

$triple = '<html><body>' . $block . $block . $block . '</body></html>';
$html = file_get_contents(__DIR__ . '/zhk_sample.html');

$config = [
    'split_rules' => [
        'story_selector' => 'table table table table td[style*="vertical-align:baseline"]',
        'title_selector' => 'a[style*="font-weight:bold"]',
        'link_selector' => 'a[style*="font-weight:bold"]',
        'body_selector' => 'td[style*="color:#262626"]',
    ],
];

$svc = new EmailDigestSplitterService();
foreach ([$triple, $html] as $label => $doc) {
    if ($doc === false) {
        continue;
    }
    $stories = $svc->split($doc, '', $config);
    echo $label === 0 ? "triple duplicate blocks" : 'zhk_sample';
    echo ' => ' . count($stories) . "\n";
    foreach ($stories as $i => $s) {
        echo '  ' . ($i + 1) . ': ' . $s['title'] . ' | ' . ($s['link'] ?? '') . "\n";
    }
}
