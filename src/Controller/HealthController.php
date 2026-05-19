<?php
/**
 * Health check — the first route in 0.5.
 *
 * Intentionally tiny. Confirms the bootstrap works end-to-end:
 *   - PHP is able to load bootstrap.php and the autoloader.
 *   - config.local.php is present and defines DB credentials.
 *   - The database is reachable.
 *   - Satellite / mothership mode is detected correctly.
 *
 * Reports the current schema version so operators can tell whether
 * `php migrate.php` still needs to run. Once real features land this route
 * stays useful for uptime checks.
 *
 * All SQL is delegated to repositories; this controller only orchestrates.
 */

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Http\AuthGate;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Repository\SystemRepository;

final class HealthController
{
    public function show(): void
    {
        $dbStatus      = 'unknown';
        $dbVersion     = null;
        $schemaVersion = null;

        try {
            $pdo = getDbConnection();
            $dbStatus      = 'ok';
            $dbVersion     = (new SystemRepository($pdo))->mysqlVersion();
            $schemaVersion = (new SystemConfigRepository($pdo))->getSchemaVersion();
        } catch (\Throwable $e) {
            $dbStatus = 'error: ' . $e->getMessage();
        }

        // When auth is enabled but the visitor is not logged in, hide anything
        // that could help an attacker fingerprint the host: PHP version, MySQL
        // version, schema number, satellite layout, brand title, base path.
        // Uptime monitors still see a usable ok/not-ok status.
        $degraded = AuthGate::isEnabled() && !AuthGate::isLoggedIn();

        $isOk = $dbStatus === 'ok' && $schemaVersion !== null;

        if ($degraded) {
            $data = [
                'degraded'      => true,
                'dbStatus'      => $isOk ? 'ok' : 'not ok',
                'basePath'      => getBasePath(),
            ];
        } else {
            $data = [
                'degraded'      => false,
                'seismoVersion' => SEISMO_VERSION,
                'phpVersion'    => PHP_VERSION,
                'dbStatus'      => $dbStatus,
                'dbVersion'     => $dbVersion,
                'schemaVersion' => $schemaVersion,
                'satellite'     => isSatellite(),
                'mothershipDb'  => SEISMO_MOTHERSHIP_DB,
                'brandTitle'    => seismoBrandTitle(),
                'basePath'      => getBasePath(),
            ];
        }

        require SEISMO_ROOT . '/views/health.php';
    }
}
