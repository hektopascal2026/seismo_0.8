<?php
require_once __DIR__ . '/../bootstrap.php';
$pdo = getDbConnection();

echo "=== Email Subscriptions matching oeffent ===\n";
$stmt = $pdo->query("SELECT id, display_name, match_type, match_value, subject_filter, digest_split_config FROM email_subscriptions WHERE display_name LIKE '%öffentlich%' OR match_value LIKE '%öffentlich%' OR display_name LIKE '%oeffent%' OR match_value LIKE '%oeffent%'");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    print_r($row);
}

echo "=== Latest Emails matching subject ===\n";
$stmt = $pdo->query("SELECT id, subject, from_email, received_at, body_hash, subscription_id, message_id FROM emails WHERE subject LIKE '%öffentlich%' OR from_email LIKE '%öffentlich%' OR subject LIKE '%oeffent%' OR from_email LIKE '%oeffent%' ORDER BY id DESC LIMIT 10");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    print_r($row);
}
