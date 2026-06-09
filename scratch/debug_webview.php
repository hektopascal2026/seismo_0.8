<?php
require_once __DIR__ . '/../bootstrap.php';

$html = <<<'HTML'
<div class="newsletter-header-footer-html">
<p style="text-align: center" align="center">
Send tips <a href="https://y3r710.r.eu-west-1.awstrack.me/L0/https:%2F%2Fdmp.politico.eu%2F%3Femail=seismofetcher@gmail.com%26destination=mailto:brusselsplaybookers@politico.eu/1/0102019ea59407cb-acb93219-fc50-4e83-b007-5ac6e935845d-000000/E032Vlmbj4-mX17GbB_Hl5_7B7I=473" target="_blank">here</a> | 
Follow us <a class="c-link" href="https://y3r710.r.eu-west-1.awstrack.me/L0/https:%2F%2Fdmp.politico.eu%2F%3Femail=seismofetcher@gmail.com%26destination=https://x.com/gerardofortuna" target="_blank">@gerardofortuna</a> 
<a class="c-link" href="https://y3r710.r.eu-west-1.awstrack.me/L0/https:%2F%2Fdmp.politico.eu%2F%3Femail=seismofetcher@gmail.com%26destination=https://bsky.app/profile/gabrielgavin.bsky.social" target="_blank">@GabrielGavin</a> | 
<a href="https://y3r710.r.eu-west-1.awstrack.me/L0/https:%2F%2Fdmp.politico.eu%2F%3Femail=seismofetcher@gmail.com%26destination=https://www.politico.eu/newsletter/brussels-playbook/bardellas-eu-game-plan/" target="_blank">Listen to Playbook and view in your browser</a>
</p>
</div>
HTML;

$plain = "Send tips here | Follow us @gerardofortuna @GabrielGavin | Listen to Playbook and view in your browser";

echo "=== RAW RESOLVE (no custom keywords) ===\n";
$res = \Seismo\Core\Mail\EmailWebViewUrlExtractor::resolve($html, $plain, [0]);
echo "Extracted URL: " . $res->url . "\n\n";

echo "=== RESOLVE WITH CUSTOM KEYWORDS ===\n";
$keywords = ["Listen to Playbook and view in your browser"];
$res2 = \Seismo\Core\Mail\EmailWebViewUrlExtractor::resolve($html, $plain, [0], $keywords);
echo "Extracted URL: " . $res2->url . "\n\n";
