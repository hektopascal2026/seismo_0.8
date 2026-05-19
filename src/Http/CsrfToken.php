<?php

declare(strict_types=1);

namespace Seismo\Http;

/**
 * Session-bound CSRF token for mutating POST routes.
 *
 * Shape:
 *   - One token per session, stored in $_SESSION['seismo_csrf'].
 *   - {@see ensure()} generates it lazily the first time it's needed.
 *   - {@see field()} renders a hidden `<input name="_csrf">`.
 *   - {@see verify()} is timing-safe and, by default, replaces the token on
 *     success (defence-in-depth — a stolen token is single-use). Read-only
 *     POSTs may pass $rotateOnSuccess = false (e.g. {@see ScraperController::preview}).
 *
 * Why a single rotating token rather than per-form? Seismo is a single-user
 * admin app with a short session lifetime. Per-form tokens add a lot of
 * plumbing for no practical attacker resistance at our scale.
 *
 * The token is required on every state-changing POST (`toggle_favourite`,
 * `refresh_fedlex`, `refresh_parl_ch`, `refresh_feed_sources`, `refresh_scraper_sources`,
 * `refresh_mail_ingest`, `refresh_lex_all`, `refresh_all`, `refresh_plugin`,
 * `save_lex_ch`, `save_leg_parl_ch`, `plugin_test`). Routes handling
 * GET are untouched. Magnitu API routes authenticate with Bearer tokens
 * and never see session cookies, so CSRF does not apply.
 *
 * Pages that render CSRF fields use {@see field()} once in the controller and
 * pass the HTML into the view. {@see Router} skips early
 * {@see session_write_close()} for those actions so the session is not
 * re-opened during rendering (see `READONLY_KEEP_SESSION_FOR_CSRF`).
 */
final class CsrfToken
{
    private const SESSION_KEY = 'seismo_csrf';
    private const FIELD_NAME  = '_csrf';

    public static function ensure(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $token = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_KEY] = $token;
        }

        return $token;
    }

    public static function verify(?string $token, bool $rotateOnSuccess = true): bool
    {
        if ($token === null || $token === '') {
            return false;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $expected = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($expected) || $expected === '') {
            return false;
        }
        if (!hash_equals($expected, $token)) {
            return false;
        }
        if ($rotateOnSuccess) {
            // Single-use rotation: most mutating POSTs get a fresh token.
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return true;
    }

    /**
     * True when the current POST carries a matching _csrf token.
     *
     * @param bool $rotateOnSuccess If false, the session token is not rotated
     *                              (for stateless "dry run" POSTs that must not
     *                              invalidate the same page’s form token).
     */
    public static function verifyRequest(bool $rotateOnSuccess = true): bool
    {
        $raw = $_POST[self::FIELD_NAME] ?? null;

        return is_string($raw) && self::verify($raw, $rotateOnSuccess);
    }

    public static function fieldName(): string
    {
        return self::FIELD_NAME;
    }

    /**
     * Hidden input for inline use in forms: `<?= CsrfToken::field() ?>`
     * Already escaped — safe to echo raw.
     */
    public static function field(): string
    {
        $token = self::ensure();

        return '<input type="hidden" name="' . self::FIELD_NAME . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}
