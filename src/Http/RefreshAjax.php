<?php

declare(strict_types=1);

namespace Seismo\Http;

/**
 * Timeline / module refresh buttons POST `ajax=1` and expect JSON instead of a 303.
 */
final class RefreshAjax
{
    public static function wantsJson(): bool
    {
        return isset($_POST['ajax']) && (string) $_POST['ajax'] === '1';
    }

    /**
     * @return never
     */
    public static function json(bool $ok, ?string $message, ?string $error, int $http = 200): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($http);
        echo json_encode([
            'ok'      => $ok,
            'message' => $ok ? $message : null,
            'error'   => $ok ? null : ($error ?? $message ?? 'Refresh could not be completed.'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * After setting {@see $_SESSION} flash, either JSON (reload on client) or redirect.
     */
    public static function respondOrRedirect(callable $redirect): void
    {
        if (!self::wantsJson()) {
            $redirect();

            return;
        }

        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        unset($_SESSION['error'], $_SESSION['success']);
        $ok = !(is_string($error) && $error !== '');
        $msg = $ok
            ? (is_string($success) && $success !== '' ? $success : 'Refresh completed.')
            : (is_string($error) && $error !== '' ? $error : 'Refresh could not be completed.');
        self::json($ok, $ok ? $msg : null, $ok ? null : $msg);
    }
}
