<?php
/**
 * Seismo 0.5 front controller.
 *
 * Kept thin on purpose. All it does is:
 *   - bootstrap the app,
 *   - build a route table,
 *   - dispatch the request.
 *
 * Satellite installs (`SEISMO_SATELLITE_MODE`) load a minimal route table from
 * `routes_satellite.inc.php` — timeline, highlights, settings (general +
 * Magnitu), Magnitu API, POST `refresh_remote` (proxy to mothership refresh),
 * auth, health, migrate. The mothership uses `routes_mothership.inc.php` for
 * the full surface.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', '0');

$__action = $_GET['action'] ?? '';
if (!is_string($__action)) {
    $__action = '';
}
// Anonymous `?action=health` uptime checks rarely send a cookie; skip
// session_start() so we do not allocate a session file per poll. Every other
// route (and health with an existing session cookie) still opens the session.
$__sessName = session_name();
if ($__action !== 'health' || ($__sessName !== '' && !empty($_COOKIE[$__sessName]))) {
    session_start();
}

require __DIR__ . '/bootstrap.php';

use Seismo\Http\AuthGate;
use Seismo\Http\Router;

$router = new Router();

if (isSatellite()) {
    require __DIR__ . '/routes_satellite.inc.php';
} else {
    require __DIR__ . '/routes_mothership.inc.php';
}

$router->setDefault('index');

$action = $__action;

// POST to ?action=login uses showLogin's own path (the controller branches on
// REQUEST_METHOD), so overlay the handler only on POST.
if ($action === 'login' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $router->register('login', \Seismo\Controller\AuthController::class . '::handleLogin', false);
}
if (!isSatellite()
    && ($action === 'configuration' || $action === 'setup')
    && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $router->register($action, \Seismo\Controller\SetupController::class . '::handlePost', false);
}

// Dormant-by-default auth gate — runs before dispatch. When
// SEISMO_ADMIN_PASSWORD_HASH is empty/unset this is a no-op.
AuthGate::check($action);

$router->dispatch($action);
