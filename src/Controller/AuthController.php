<?php

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Http\AuthGate;
use Seismo\Http\CsrfToken;

/**
 * Native password-based login. Dormant unless `SEISMO_ADMIN_PASSWORD_HASH`
 * is set in config.local.php. See `.cursor/rules/auth-dormant-by-default.mdc`.
 */
final class AuthController
{
    public function showLogin(): void
    {
        // If auth is off there's nothing to log into — avoid the weird UX of
        // a working login form that doesn't actually change anything.
        if (!AuthGate::isEnabled()) {
            $this->redirectHome();

            return;
        }

        if (AuthGate::isLoggedIn()) {
            $this->redirectHome();

            return;
        }

        $basePath = getBasePath();
        $errorMessage = null;
        if (isset($_SESSION['login_error'])) {
            $errorMessage = (string)$_SESSION['login_error'];
            unset($_SESSION['login_error']);
        }

        require SEISMO_ROOT . '/views/login.php';
    }

    public function handleLogin(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirectToLogin();

            return;
        }

        if (!AuthGate::isEnabled()) {
            $this->redirectHome();

            return;
        }

        if (!CsrfToken::verifyRequest()) {
            $_SESSION['login_error'] = 'Session expired — please try again.';
            $this->redirectToLogin();

            return;
        }

        $password = (string)($_POST['password'] ?? '');
        if ($password === '') {
            $_SESSION['login_error'] = 'Password is required.';
            $this->redirectToLogin();

            return;
        }

        if (!password_verify($password, (string)SEISMO_ADMIN_PASSWORD_HASH)) {
            // Deliberate brief pause to blunt online brute-forcing without
            // requiring a rate-limit table. Plenty for single-user admin.
            usleep(250_000);
            $_SESSION['login_error'] = 'Incorrect password.';
            $this->redirectToLogin();

            return;
        }

        AuthGate::markLoggedIn();
        $_SESSION['success'] = 'Logged in.';
        $this->redirectHome();
    }

    public function logout(): void
    {
        // Logout is a mutating operation: it changes session state. GET is
        // rejected so that <img src=".../?action=logout"> (or a cross-origin
        // link) cannot log an admin out. Browsers never send CSRF headers on
        // third-party image/link GETs, so enforcing POST + a session-bound
        // token is the whole defence.
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirectHome();

            return;
        }

        if (!CsrfToken::verifyRequest()) {
            // Silent no-op on bad CSRF — don't leak whether the user was logged in.
            $this->redirectHome();

            return;
        }

        AuthGate::logout();
        $_SESSION['success'] = 'Logged out.';
        $this->redirectToLogin();
    }

    private function redirectToLogin(): void
    {
        header('Location: ' . getBasePath() . '/index.php?action=login', true, 303);
        exit;
    }

    private function redirectHome(): void
    {
        header('Location: ' . getBasePath() . '/index.php?action=index', true, 303);
        exit;
    }
}
