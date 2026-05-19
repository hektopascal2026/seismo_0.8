<?php
/**
 * Run database migrations over HTTP for hosts without SSH / PHP CLI.
 *
 * Protected by SEISMO_MIGRATE_KEY. Key resolution order (first match wins):
 *   1. Authorization: Bearer <key>
 *   2. POST key= (e.g. form) — avoids putting the secret in query strings / logs
 *   3. GET key= — convenient but may appear in access logs; prefer 1 or 2
 *
 * Disable web migrations by leaving SEISMO_MIGRATE_KEY unset or empty — then
 * only `php migrate.php` works (for developers with CLI).
 */

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Migration\MigrationRunner;
use Throwable;

final class MigrateController
{
    public function runWeb(): void
    {
        header('Content-Type: text/plain; charset=utf-8');

        if (!defined('SEISMO_MIGRATE_KEY') || SEISMO_MIGRATE_KEY === '') {
            http_response_code(403);
            echo "Web migrations are disabled (SEISMO_MIGRATE_KEY not set in config.local.php).\n";
            echo "If you have shell access, run: php migrate.php\n";
            return;
        }

        $key = $this->resolveMigrateKey();
        if ($key === '' || !hash_equals((string)SEISMO_MIGRATE_KEY, $key)) {
            http_response_code(403);
            echo "Forbidden.\n";
            return;
        }

        try {
            $pdo = getDbConnection();
            $runner = new MigrationRunner($pdo);
            $current = $runner->getCurrentVersion();

            echo 'Seismo migrate — ' . SEISMO_VERSION . "\n";
            echo "Current schema version: {$current}\n";

            if ($current >= MigrationRunner::LATEST_VERSION) {
                echo 'Nothing to do — schema is already at version ' . MigrationRunner::LATEST_VERSION . ".\n";
                return;
            }

            if (isSatellite()) {
                http_response_code(403);
                echo "Migrations only run on the mothership. This instance is in satellite mode.\n";
                return;
            }

            $runner->run(static function (string $line): void {
                echo $line;
            });
            echo "Done.\n";
        } catch (Throwable $e) {
            http_response_code(500);
            echo 'Migration failed: ' . $e->getMessage() . "\n";
        }
    }

    private function resolveMigrateKey(): string
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (is_string($auth) && preg_match('/^\s*Bearer\s+(\S+)/i', $auth, $m)) {
            return $m[1];
        }
        $post = $_POST['key'] ?? null;
        if (is_string($post) && $post !== '') {
            return $post;
        }
        $get = $_GET['key'] ?? null;
        return is_string($get) ? $get : '';
    }
}
