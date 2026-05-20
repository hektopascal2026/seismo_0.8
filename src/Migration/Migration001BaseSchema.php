<?php
/**
 * Migration 001 — base schema from Seismo 0.4 consolidated dump (schema version 17).
 *
 * Idempotent: all CREATE TABLE use IF NOT EXISTS. Safe on empty DBs and on
 * databases already populated by 0.4's initDatabase().
 *
 * On the mothership: loads `docs/db-schema.sql`.
 * On a satellite scores DB: loads `docs/db-schema-local.sql`.
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use RuntimeException;

final class Migration001BaseSchema implements MigrationContract
{
    public const VERSION = 17;

    public static function migrationScope(): MigrationScope
    {
        return MigrationScope::Both;
    }

    public function apply(PDO $pdo, MigrationTarget $target): void
    {
        $path = $target === MigrationTarget::Scores
            ? SEISMO_ROOT . '/docs/db-schema-local.sql'
            : SEISMO_ROOT . '/docs/db-schema.sql';
        if (!is_file($path)) {
            throw new RuntimeException('Missing ' . basename($path) . ' — expected schema dump.');
        }
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Could not read ' . basename($path));
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
