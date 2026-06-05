<?php
require_once __DIR__ . '/../bootstrap.php';

$pdo = getDbConnection();

// Check email subscriptions
echo "--- EMAIL SUBSCRIPTIONS ---\n";
$stmt = $pdo->query("SELECT * FROM email_subscriptions WHERE display_name LIKE '%GZERO%' OR match_value LIKE '%GZERO%'");
$subs = $stmt->fetchAll();
foreach ($subs as $sub) {
    echo "ID: {$sub['id']} | Display: {$sub['display_name']} | Scope: {$sub['module_scope']} | Match: {$sub['match_type']} = {$sub['match_value']} | Disabled: {$sub['disabled']}\n";
}

// Check latest emails
echo "\n--- LATEST EMAILS ---\n";
$stmt = $pdo->query("SELECT id, subject, from_email, from_name FROM emails ORDER BY id DESC LIMIT 5");
$emails = $stmt->fetchAll();
foreach ($emails as $email) {
    echo "ID: {$email['id']} | Subject: {$email['subject']} | From: {$email['from_name']} <{$email['from_email']}>\n";
}
