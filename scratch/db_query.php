<?php
define('DB_HOST', '127.0.0.1');
require_once __DIR__ . '/../bootstrap.php';

try {
    // Connect directly using PDO with 127.0.0.1 if host is localhost
    $host = '127.0.0.1';
    $dsn = 'mysql:host=' . $host . ';dbname=seismo;charset=utf8mb4';
    $db = new PDO($dsn, 'dummy', 'dummy', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    // Let's find feeds that match senat
    $stmt = $db->prepare("SELECT * FROM feeds WHERE url LIKE :url");
    $stmt->execute(['url' => '%senat.fr%']);
    $rows = $stmt->fetchAll();
    
    echo "--- FEEDS MATCHING senat.fr ---\n";
    foreach ($rows as $row) {
        print_r($row);
    }
    echo "-------------------------------\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
