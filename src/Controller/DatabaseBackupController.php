<?php

declare(strict_types=1);

namespace Seismo\Controller;

use PDO;

/**
 * Streaming database backup controller.
 */
final class DatabaseBackupController
{
    public function downloadSql(): void
    {
        if (isSatellite()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Satellite mode — databases are backed up from the mothership.\n";
            return;
        }

        try {
            $pdo = getDbConnection();
            $dbName = (string)SEISMO_ENTRIES_DB;
            $scoresDb = seismoScoresDbName();

            // Export both the entries database and the scores database (which might be the same or different).
            $databases = [$dbName];
            if ($scoresDb !== '' && $scoresDb !== $dbName) {
                $databases[] = $scoresDb;
            }

            // Clean output buffer to prevent corrupted/incomplete files
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            $fileName = 'seismo-db-backup-' . gmdate('Ymd-His') . '.sql';
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');

            echo "-- Seismo Database Backup\n";
            echo "-- Generated at: " . gmdate('Y-m-d H:i:s') . " UTC\n";
            echo "-- Seismo Version: " . (defined('SEISMO_VERSION') ? SEISMO_VERSION : 'unknown') . "\n\n";
            echo "SET FOREIGN_KEY_CHECKS=0;\n\n";

            foreach ($databases as $db) {
                $dbQuoted = '`' . str_replace('`', '``', $db) . '`';
                echo "-- ==========================================================\n";
                echo "-- DATABASE: " . $db . "\n";
                echo "-- ==========================================================\n";
                echo "CREATE DATABASE IF NOT EXISTS " . $dbQuoted . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
                echo "USE " . $dbQuoted . ";\n\n";

                // List tables
                $tables = [];
                $stmt = $pdo->query("SHOW TABLES FROM " . $dbQuoted);
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    $tables[] = $row[0];
                }

                foreach ($tables as $table) {
                    $tableQuoted = '`' . str_replace('`', '``', $table) . '`';
                    echo "-- Table structure for " . $tableQuoted . "\n";
                    echo "DROP TABLE IF EXISTS " . $tableQuoted . ";\n";

                    $createStmt = $pdo->query("SHOW CREATE TABLE " . $dbQuoted . "." . $tableQuoted);
                    $createRow = $createStmt->fetch(PDO::FETCH_NUM);
                    if ($createRow) {
                        echo $createRow[1] . ";\n\n";
                    }

                    echo "-- Dumping data for table " . $tableQuoted . "\n";
                    
                    // Streaming rows to prevent memory exhaustion
                    $dataStmt = $pdo->query("SELECT * FROM " . $dbQuoted . "." . $tableQuoted, PDO::FETCH_ASSOC);
                    $columnNames = null;
                    $colsList = '';
                    
                    while ($dataRow = $dataStmt->fetch()) {
                        if ($columnNames === null) {
                            $columnNames = array_map(function($col) {
                                return '`' . str_replace('`', '``', $col) . '`';
                            }, array_keys($dataRow));
                            $colsList = implode(', ', $columnNames);
                        }

                        $values = [];
                        foreach ($dataRow as $val) {
                            if ($val === null) {
                                $values[] = 'NULL';
                            } else {
                                $values[] = $pdo->quote((string)$val);
                            }
                        }
                        echo "INSERT INTO " . $tableQuoted . " (" . $colsList . ") VALUES (" . implode(', ', $values) . ");\n";
                    }
                    echo "\n";
                }
            }

            echo "SET FOREIGN_KEY_CHECKS=1;\n";
            exit;
        } catch (\Throwable $e) {
            error_log('Seismo database backup error: ' . $e->getMessage());
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
                echo "Could not build database backup SQL dump.\n";
            } else {
                echo "\n-- ERROR: Could not complete database backup. " . $e->getMessage() . "\n";
            }
            exit;
        }
    }
}
