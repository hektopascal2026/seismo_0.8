<?php
/**
 * Migration 001 — base schema from Seismo 0.4 consolidated dump (schema version 17).
 *
 * Idempotent: all CREATE TABLE use IF NOT EXISTS. Safe on empty DBs and on
 * databases already populated by 0.4's initDatabase().
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use RuntimeException;

final class Migration001BaseSchema
{
    public const VERSION = 17;

    public function apply(PDO $pdo): void
    {
        $path = SEISMO_ROOT . '/docs/db-schema.sql';
        if (!is_file($path)) {
            throw new RuntimeException('Missing docs/db-schema.sql — expected consolidated schema dump.');
        }
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Could not read docs/db-schema.sql');
        }
        $statements = SqlStatementSplitter::statements($sql);
        foreach ($statements as $i => $stmt) {
            try {
                $pdo->exec($stmt);
            } catch (\PDOException $e) {
                throw new RuntimeException(
                    'Migration 001 failed on statement #' . ($i + 1) . ': ' . $e->getMessage(),
                    0,
                    $e
                );
            }
        }
    }
}
