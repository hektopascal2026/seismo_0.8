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
}
