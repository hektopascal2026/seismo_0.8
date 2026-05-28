<?php
/**
 * Minimal action-based router.
 *
 * Routes are registered by the single string key Seismo has used since 0.4:
 * `?action=<name>`. This keeps every existing URL shape valid as we port more
 * features.
 *
 * Two concerns that existed in the 0.4 front controller and are preserved here:
 *   1. Most read-only actions release the session lock early so PHP's file-based
 *      session handler doesn't serialise concurrent requests. Routes that render
 *      CSRF forms (`index`, `filter`, `lex`, `leg`, `calendar`, `settings`, `styleguide`, `logbook`, `feeds`, `scraper`, `mail`, `about`, `configuration`, …) skip early
 *      release — see {@see READONLY_KEEP_SESSION_FOR_CSRF}. Any future
 *      read-only route whose controller calls `CsrfToken::field()` MUST be
 *      added to that list; otherwise `session_write_close()` fires before
 *      the handler and the subsequent `session_start()` inside
 *      `CsrfToken::ensure()` reloads `$_SESSION` from disk, silently
 *      dropping any flash message the preceding POST stashed.
 *   2. Unknown actions fall back to a configured default rather than 404'ing,
 *      so the app stays usable when the default page isn't ported yet.
 *
 * This class has no framework-ish ambitions. It's a glorified switch that
 * knows how to instantiate a controller.
 */

declare(strict_types=1);

namespace Seismo\Http;

final class Router
{
    /**
     * Read-only routes that render CSRF-protected GET forms. We keep the
     * session open for the full request so the controller can call
     * {@see CsrfToken::field()} once and pass HTML into the view without
     * {@see session_write_close()} + a second {@see session_start()} in the view.
     *
     * @var array<string, true>
     */
    private const READONLY_KEEP_SESSION_FOR_CSRF = [
        'index'      => true,
        'filter'     => true,
        'lex'        => true,
        'leg'        => true,
        'calendar'   => true,
        // `retention` is intentionally NOT listed: since Slice 6 it is a
        // pure 303 redirect to `settings&tab=retention` and never renders
        // a CSRF form.
        'settings'   => true,
        'styleguide' => true,
        'logbook'    => true,
        'magnitu'          => true,
        'researcher' => true,
        'briefing_builder' => true,
        'label'            => true,
        'feeds'      => true,
        'scraper'    => true,
        'mail'       => true,
        'about'         => true,
        'configuration' => true,
        'mail_google_oauth_callback' => true,
    ];

    /** @var array<string, string> action => "Class::method" */
    private array $routes = [];

    /** @var array<string, bool> action => true if read-only */
    private array $readOnly = [];

    private string $default = '';

    /**
     * Register an action. Handler format: "Fully\\Qualified\\Class::method".
     */
    public function register(string $action, string $handler, bool $readOnly = false): void
    {
        $this->routes[$action] = $handler;
        if ($readOnly) {
            $this->readOnly[$action] = true;
        }
    }

    public function setDefault(string $action): void
    {
        $this->default = $action;
    }

    public function dispatch(string $action): void
    {
        if ($action === '' || !isset($this->routes[$action])) {
            $action = $this->default;
        }
        if ($action === '' || !isset($this->routes[$action])) {
            http_response_code(404);
            echo 'Unknown action.';
            return;
        }

        $this->maybeReleaseSession($action);

        [$class, $method] = explode('::', $this->routes[$action], 2);
        if (!class_exists($class) || !method_exists($class, $method)) {
            error_log(sprintf(
                "Seismo router: handler '%s' not found for action '%s'",
                $this->routes[$action],
                $action
            ));
            http_response_code(500);
            echo 'Internal server error.';
            return;
        }
        /** @var object $controller */
        $controller = new $class();
        $controller->{$method}();
    }

    /**
     * Release the session write lock for read-only routes while preserving
     * pending flash messages. Mirrors the pattern from 0.4's index.php.
     */
    private function maybeReleaseSession(string $action): void
    {
        if (!isset($this->readOnly[$action])) {
            return;
        }
        if (isset(self::READONLY_KEEP_SESSION_FOR_CSRF[$action])) {
            return;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $flashSuccess = $_SESSION['success'] ?? null;
        $flashError   = $_SESSION['error']   ?? null;
        unset($_SESSION['success'], $_SESSION['error']);
        session_write_close();
        if ($flashSuccess !== null) {
            $_SESSION['success'] = $flashSuccess;
        }
        if ($flashError !== null) {
            $_SESSION['error'] = $flashError;
        }
    }
}
