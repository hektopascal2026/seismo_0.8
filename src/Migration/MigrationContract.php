<?php

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;

interface MigrationContract
{
    public static function migrationScope(): MigrationScope;

    public function apply(PDO $pdo, MigrationTarget $target): void;
}
