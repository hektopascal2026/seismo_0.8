<?php
require_once __DIR__ . '/../bootstrap.php';

try {
    $pdo = getDbConnection();
} catch (\Throwable $e) {
    echo "DB Connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "=== LATEST PLUGIN RUN LOGS ===\n";
$stmt = $pdo->query("SELECT plugin_id, run_at, status, item_count, error_message, duration_ms FROM plugin_run_log ORDER BY run_at DESC LIMIT 20");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    printf("[%s] %-15s: status=%-8s count=%-3d duration=%-5dms err=%s\n",
        $row['run_at'], $row['plugin_id'], $row['status'], $row['item_count'], $row['duration_ms'], $row['error_message'] ?? 'none');
}

echo "\n=== LATEST ENTRIES BY SOURCE TYPE ===\n";
// Let's check feed_items, emails, and lex_items
$stmt = $pdo->query("SELECT 'feed_item' as type, MAX(created_at) as last_created, COUNT(*) as cnt FROM feed_items");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
printf("%-15s: count=%-5d last_created=%s\n", $row['type'], $row['cnt'], $row['last_created'] ?? 'none');

$stmt = $pdo->query("SELECT 'email_ingest' as type, MAX(created_at) as last_created, COUNT(*) as cnt FROM email_ingest");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
printf("%-15s: count=%-5d last_created=%s\n", $row['type'], $row['cnt'], $row['last_created'] ?? 'none');

$stmt = $pdo->query("SELECT 'lex_item' as type, MAX(created_at) as last_created, COUNT(*) as cnt FROM lex_items");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
printf("%-15s: count=%-5d last_created=%s\n", $row['type'], $row['cnt'], $row['last_created'] ?? 'none');

echo "\n=== CRON MUTEX STATUS ===\n";
$stmt = $pdo->query("SELECT * FROM system_config WHERE config_key LIKE '%mutex%' OR config_key LIKE '%refresh%'");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    printf("%s = %s\n", $row['config_key'], $row['config_value']);
}
