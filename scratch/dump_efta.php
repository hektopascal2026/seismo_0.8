<?php

require_once __DIR__ . '/../bootstrap.php';

use Seismo\Repository\EmailSubscriptionRepository;

$pdo = getDbConnection();

$stmt = $pdo->query("SELECT * FROM mail_subscriptions WHERE match_value LIKE '%efta%' OR match_type LIKE '%efta%' OR cleanup_config LIKE '%efta%'");
$subs = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "--- SUBSCRIPTIONS ---\n";
print_r($subs);

if (empty($subs)) {
    echo "No EFTA subscription found.\n";
    exit(0);
}

$sub = $subs[0];
$matchType = $sub['match_type'];
$matchValue = $sub['match_value'];

echo "--- EMAILS MATCHING ---\n";
if ($matchType === 'email') {
    $stmt = $pdo->prepare("SELECT id, subject, derived_title, from_email, text_body, body_text, html_body, body_html, metadata FROM emails WHERE LOWER(from_email) = ? ORDER BY id DESC LIMIT 5");
    $stmt->execute([strtolower($matchValue)]);
} else {
    $domain = strtolower(ltrim(trim($matchValue), '@'));
    $stmt = $pdo->prepare("SELECT id, subject, derived_title, from_email, text_body, body_text, html_body, body_html, metadata FROM emails WHERE " . EmailSubscriptionRepository::sqlDomainHostMatch('from_email') . " ORDER BY id DESC LIMIT 5");
    $stmt->execute([$domain, $domain]);
}
$emails = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($emails as $email) {
    echo "ID: " . $email['id'] . "\n";
    echo "Subject: " . $email['subject'] . "\n";
    echo "From: " . $email['from_email'] . "\n";
    echo "Metadata: " . $email['metadata'] . "\n";
    echo "HTML length: " . strlen($email['html_body'] ?? $email['body_html'] ?? '') . "\n";
    echo "Text length: " . strlen($email['text_body'] ?? $email['body_text'] ?? '') . "\n";
    
    // Check if webview URL is extracted
    $html = trim((string)($email['html_body'] ?? $email['body_html'] ?? ''));
    $plain = trim((string)($email['text_body'] ?? $email['body_text'] ?? ''));
    
    $cfg = json_decode((string)$sub['cleanup_config'], true);
    $customKeywords = (is_array($cfg) && !empty($cfg['webview_keywords'])) ? (array)$cfg['webview_keywords'] : [];
    
    echo "Custom keywords: " . json_encode($customKeywords) . "\n";
    
    $profile = \Seismo\Core\Mail\EmailLocaleGuesser::profileForEmail($email['subject'], $plain);
    $ranks = \Seismo\Core\Mail\EmailAlternateLocalePolicy::preferredLocaleRanks($profile);
    $resolution = \Seismo\Core\Mail\EmailWebViewUrlExtractor::resolve($html, $plain, $ranks, $customKeywords);
    
    echo "Resolution: URL=" . ($resolution->url ?? 'NULL') . ", rank=" . ($resolution->localeRank ?? 'NULL') . ", hydrate=" . ($resolution->hydrateBody ? 'TRUE' : 'FALSE') . "\n";
    
    // Let's print out the first 500 characters of HTML to inspect it
    echo "HTML sample: " . substr($html, 0, 500) . "\n";
    echo "----------------------------------------\n";
}
