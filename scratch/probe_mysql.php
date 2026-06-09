<?php
$sockets = [
    '/tmp/mysql.sock',
    '/var/mysql/mysql.sock',
    '/opt/homebrew/var/mysql/mysql.sock',
    '/Users/oliverfuchs/.local/share/db.sock', // potential local sockets
];

$connected = false;
foreach ($sockets as $socket) {
    try {
        $dsn = "mysql:unix_socket={$socket};dbname=seismo;charset=utf8mb4";
        $pdo = new PDO($dsn, 'dummy', 'dummy', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        echo "Successfully connected via socket: {$socket}\n";
        $connected = true;
        break;
    } catch (\Throwable $e) {
        // echo "Failed {$socket}: " . $e->getMessage() . "\n";
    }
}

if (!$connected) {
    // Let's check if we can find any active mysql.sock files on the system
    echo "Probing filesystem for mysql.sock...\n";
    $output = [];
    exec("find /tmp /var /opt -name \"mysql.sock\" 2>/dev/null", $output);
    foreach ($output as $path) {
        try {
            $dsn = "mysql:unix_socket={$path};dbname=seismo;charset=utf8mb4";
            $pdo = new PDO($dsn, 'dummy', 'dummy');
            echo "Connected via found socket: {$path}\n";
            $connected = true;
            break;
        } catch (\Throwable $e) {
            echo "Failed found socket {$path}: " . $e->getMessage() . "\n";
        }
    }
}

if (!$connected) {
    echo "Could not connect to MySQL database.\n";
}
