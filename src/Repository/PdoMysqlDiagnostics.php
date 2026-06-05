<?php

declare(strict_types=1);

namespace Seismo\Repository;

use PDOException;

/**
 * Shared MySQL/MariaDB PDO error classification for repositories.
 */
final class PdoMysqlDiagnostics
{
    /**
     * True when the exception indicates a missing table (fresh install, wrong schema).
     */
    public static function isMissingTable(PDOException $e): bool
    {
        $code = $e->errorInfo[1] ?? null;
        if ((int)$code === 1146) {
            return true;
        }

        return stripos($e->getMessage(), "doesn't exist") !== false
            || stripos($e->getMessage(), 'Unknown table') !== false;
    }

    /**
     * True when the exception indicates a missing column (schema not migrated yet).
     */
    public static function isMissingColumn(PDOException $e, ?string $column = null): bool
    {
        $code = $e->errorInfo[1] ?? null;
        if ((int)$code === 1054) {
            if ($column === null || $column === '') {
                return true;
            }

            return stripos($e->getMessage(), $column) !== false;
        }

        $msg = $e->getMessage();
        if (!str_contains($msg, 'Unknown column')) {
            return false;
        }

        return $column === null || $column === '' || stripos($msg, $column) !== false;
    }
}
