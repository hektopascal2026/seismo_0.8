<?php
/**
 * Database-level system queries (version, status, etc.).
 *
 * Kept separate from feature repositories because it's not tied to any
 * domain table — it just talks to the connection itself.
 */

declare(strict_types=1);

namespace Seismo\Repository;

use PDO;

final class SystemRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * MySQL server version reported by the current connection.
     */
    public function mysqlVersion(): string
    {
        return (string)$this->pdo->query('SELECT VERSION()')->fetchColumn();
    }
}
