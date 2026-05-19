<?php

declare(strict_types=1);

namespace Seismo\Http;

/**
 * Dormant-by-default auth gate for the web UI.
 *
 * The gate is CONTROLLED by the `SEISMO_ADMIN_PASSWORD_HASH` constant in
 * `config.local.php`:
 *
 *   - Unset or empty → auth is OFF. `check()` is a no-op. Every route is
 *     reachable. User's experience is identical to an unauthenticated app.
 *   - Non-empty `password_hash()` output → auth is ON. Protected routes
 *     redirect unauthenticated visitors to `?action=login`. Magnitu API
 *     routes are unaffected — they authenticate with Bearer tokens.
 *
 * See `.cursor/rules/auth-dormant-by-default.mdc` for rationale.
 *
 * Public whitelist — always reachable even when auth is ON:
 *   - `health` (degraded output when not logged in; see HealthController)
 *   - `login`, `logout`
 *   - `migrate` (already protected by SEISMO_MIGRATE_KEY)
 *   - `magnitu_*` (separate Bearer-token auth — Magnitu write key)
 *   - `export_*`  (separate Bearer-token auth — read-only export key)
 *
 * `configuration` (legacy: `setup`) is allowed without a session **only** while
 * `config.local.php` is missing (first-run); see {@see AuthGate::check()}.
 */
final class AuthGate
{
    private const SESSION_FLAG = 'seismo_admin';

    private const PUBLIC_ACTIONS = [
        'health'               => true,
        'login'                => true,
        'logout'               => true,
        'migrate'              => true,
        'magnitu_entries'      => true,
        'magnitu_scores'       => true,
        'magnitu_recipe'       => true,
        'magnitu_labels'       => true,
        'magnitu_status'       => true,
        'export_entries'       => true,
        'export_briefing'      => true,
        /** Session-less JSON; gated by {@see SEISMO_REMOTE_REFRESH_KEY} (satellite → mothership refresh). */
        'refresh_all_remote'   => true,
    ];

    public static function isEnabled(): bool
    {
        return defined('SEISMO_ADMIN_PASSWORD_HASH')
            && is_string(SEISMO_ADMIN_PASSWORD_HASH)
            && SEISMO_ADMIN_PASSWORD_HASH !== '';
    }

    public static function isLoggedIn(): bool
    {
        if (!self::isEnabled()) {
            return true;
        }
        // Avoid session_start() on anonymous health pings — monitoring clients
        // rarely send cookies; opening a session per request spams session files.
        $cookieName = session_name();
        if ($cookieName !== '' && empty($_COOKIE[$cookieName])) {
            return false;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        return ($_SESSION[self::SESSION_FLAG] ?? false) === true;
    }

    public static function markLoggedIn(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        session_regenerate_id(true);
        $_SESSION[self::SESSION_FLAG] = true;
    }

    public static function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        unset($_SESSION[self::SESSION_FLAG]);
        session_regenerate_id(true);
    }

    /**
     * Called by the router BEFORE dispatch. Redirects to the login screen
     * when auth is enabled and the current action is not in the public
     * whitelist. No-op when auth is off.
     */
    public static function check(string $action): void
    {
        if (!self::isEnabled()) {
            return;
        }
        if (isset(self::PUBLIC_ACTIONS[$action])) {
            return;
        }
        if (($action === 'configuration' || $action === 'setup')
            && defined('SEISMO_ROOT')
            && !is_file(SEISMO_ROOT . '/config.local.php')) {
            return;
        }
        if (self::isLoggedIn()) {
            return;
        }

        $base = function_exists('getBasePath') ? getBasePath() : '';
        header('Location: ' . $base . '/index.php?action=login', true, 303);
        exit;
    }
}
