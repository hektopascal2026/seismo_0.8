<?php
require_once __DIR__ . '/../bootstrap.php';
$pdo = getDbConnection();
$stmt = $pdo->query("SELECT id, display_name, match_value, digest_split_config FROM email_subscriptions");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "ID: " . $row['id'] . "\n";
    echo "Name: " . $row['display_name'] . "\n";
    echo "Match: " . $row['match_value'] . "\n";
    echo "Config: " . $row['digest_split_config'] . "\n";
    echo "--------------------------------------------------\n";
}
