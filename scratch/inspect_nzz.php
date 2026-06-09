<?php
require_once __DIR__ . '/../bootstrap.php';
$pdo = getDbConnection();
$stmt = $pdo->query("SELECT id, subject, SUBSTRING(text_body, 1, 1000) as txt, html_body FROM emails WHERE subject LIKE '%nzz%' OR from_address LIKE '%nzz%' ORDER BY id DESC LIMIT 2");
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($results)) {
    echo "No NZZ emails found.\n";
    // Let's print any emails just to see
    $stmt = $pdo->query("SELECT id, subject, from_address FROM emails ORDER BY id DESC LIMIT 5");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo "ID: {$row['id']} | Subject: {$row['subject']} | From: {$row['from_address']}\n";
    }
} else {
    foreach ($results as $row) {
        echo "ID: {$row['id']} | Subject: {$row['subject']}\n";
        echo "Text Sample:\n" . $row['txt'] . "\n";
        // Write HTML body to a scratch file so we can view it
        file_put_contents(__DIR__ . "/nzz_sample_{$row['id']}.html", $row['html_body']);
        echo "Saved HTML to scratch/nzz_sample_{$row['id']}.html\n";
        echo "--------------------------------------------------\n";
    }
}
