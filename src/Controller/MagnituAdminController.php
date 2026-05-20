<?php
/**
 * Admin-side companion to {@see MagnituController}.
 *
 * `MagnituController` serves the Bearer-authenticated HTTP API that the
 * Magnitu Python app hits (`?action=magnitu_*`). This controller handles the
 * three POST actions the Settings → Magnitu tab wires up, which are session-
 * authenticated (dormant-by-default) rather than Bearer-authenticated:
 *
 *   - `?action=settings_save_magnitu`             — persist `alert_threshold`
 *                                                   and `sort_by_relevance`.
 *   - `?action=settings_regenerate_magnitu_key`   — mint a fresh Magnitu API
 *                                                   key and store it in
 *                                                   `system_config.api_key`.
 *   - `?action=settings_clear_magnitu_scores`     — wipe `entry_scores` and
 *                                                   reset the recipe config
 *                                                   rows. The "Danger Zone"
 *                                                   button.
 *
 * Keeping the two controllers distinct makes the auth boundary unambiguous:
 * nothing here ever reaches for `BearerAuth`, and nothing in MagnituController
 * ever reaches for `CsrfToken` or `$_SESSION`. Ports cleanly onto the 0.4
 * handlers `handleSaveMagnituConfig` / `handleRegenerateMagnituKey` /
 * `handleClearMagnituScores` in `controllers/magnitu.php`.
 */

declare(strict_types=1);

namespace Seismo\Controller;

use PDOException;
use Seismo\Http\CsrfToken;
use Seismo\Repository\EntryScoreRepository;
use Seismo\Repository\SystemConfigRepository;

final class MagnituAdminController
{
    /**
     * Session flash: Settings → Magnitu reads this once after regenerate so the
     * API key field always shows the freshly minted value even if the next SELECT
     * misses (proxy/cache/host edge cases).
     *
     * @see SettingsController::show()
     */
    public const SESSION_API_KEY_FLASH = '_seismo_magnitu_api_key_flash_v1';

    /**
     * Keys we reset when wiping scores. `recipe_json` + `recipe_version`
     * pair with the DELETE so the next Magnitu sync gets a clean slate;
     * `last_sync_at` is reset so the Settings display reverts to
     * "No sync yet" rather than showing a stale timestamp.
     */
    private const RECIPE_RESET_KEYS = ['recipe_json', 'recipe_version', 'last_sync_at'];

    public function saveConfig(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirect();
            return;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirect();
            return;
        }

        $thresholdRaw = $_POST['alert_threshold'] ?? '0.60';
        $threshold    = SystemConfigRepository::normalizeAlertThreshold((float)$thresholdRaw);
        $sort         = isset($_POST['sort_by_relevance']) ? '1' : '0';

        try {
            $config = new SystemConfigRepository(getDbConnection());
            $config->set('alert_threshold', (string)$threshold);
            $config->set('sort_by_relevance', $sort);
            $_SESSION['success'] = 'Magnitu settings saved.';
        } catch (\Throwable $e) {
            error_log('Seismo settings_save_magnitu: ' . $e->getMessage());
            $_SESSION['error'] = 'Could not save Magnitu settings.';
        }

        $this->redirect();
    }

    public function regenerateKey(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirect();
            return;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirect();
            return;
        }

        try {
            $key    = bin2hex(random_bytes(16));
            $config = new SystemConfigRepository(getDbConnection());
            $config->set('api_key', $key);
            $verify = $config->get('api_key');
            if (trim((string)$verify) !== $key) {
                error_log('Seismo settings_regenerate_magnitu_key: read-back mismatch after set');
                $_SESSION['error'] = 'Could not persist API key (verification failed).';

                $this->redirect();

                return;
            }
            $_SESSION[self::SESSION_API_KEY_FLASH] = $key;
            $_SESSION['success'] = 'New Magnitu API key generated. Copy it into Magnitu\'s magnitu_config.json.';
        } catch (PDOException $e) {
            error_log('Seismo settings_regenerate_magnitu_key: ' . $e->getMessage());
            $_SESSION['error'] = self::pdoUserHint($e);
        } catch (\Throwable $e) {
            error_log('Seismo settings_regenerate_magnitu_key: ' . $e->getMessage());
            $_SESSION['error'] = 'Could not regenerate API key. Check server error_log for details.';
        }

        $this->redirect();
    }

    public function clearScores(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirect();
            return;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirect();
            return;
        }

        try {
            $pdo    = getDbConnection();
            $scores = new EntryScoreRepository($pdo);
            $config = new SystemConfigRepository($pdo);

            $deleted = $scores->clearAll();
            foreach (self::RECIPE_RESET_KEYS as $key) {
                // Empty string (not NULL) preserves 0.4 semantics: the row
                // stays present so `system_config` lookups always hit a row,
                // but rendering code treats empty === not-yet-synced.
                $config->set($key, '');
            }
            $config->set('recipe_version', '0');

            $_SESSION['success'] = 'Cleared ' . $deleted . ' score row(s) and reset the recipe.';
        } catch (\Throwable $e) {
            error_log('Seismo settings_clear_magnitu_scores: ' . $e->getMessage());
            $_SESSION['error'] = 'Could not clear Magnitu scores.';
        }

        $this->redirect();
    }

    private function redirect(): void
    {
        header('Location: ' . getBasePath() . '/index.php?action=settings&tab=magnitu', true, 303);
        exit;
    }

    /**
     * Human hints for common satellite misconfiguration (read-only DB user, missing table).
     */
    private static function pdoUserHint(PDOException $e): string
    {
        $msg  = $e->getMessage();
        $drv  = (string)($e->errorInfo[1] ?? '');
        $sql  = (string)($e->errorInfo[0] ?? '');

        if ($drv === '1146'
            || str_contains($msg, '1146')
            || str_contains($msg, "doesn't exist")
            || str_contains($msg, 'Unknown table')) {
            return 'Could not save API key — table `system_config` is missing. Import sql/install.sql (satellite DB) or run migrations.';
        }

        if (($sql === 'HY000' && ($drv === '1044' || str_contains($msg, '1044')))
            || (str_contains($msg, 'Access denied') && str_contains($msg, 'database'))) {
            return 'Could not save API key — MySQL denied access to this database. Check DB_NAME and GRANT ALL on the satellite database for DB_USER.';
        }

        if (str_contains($msg, 'INSERT command denied')
            || str_contains($msg, 'UPDATE command denied')
            || str_contains($msg, 'DELETE command denied')) {
            return 'Could not save API key — DB user needs INSERT/UPDATE on `system_config`. Example: GRANT ALL ON `' . self::satelliteDbNameForHint() . '`.* TO your user@localhost;';
        }

        return 'Could not regenerate API key. Check server error_log for the SQL error.';
    }

    private static function satelliteDbNameForHint(): string
    {
        return defined('DB_NAME') && is_string(DB_NAME) && DB_NAME !== '' ? DB_NAME : 'your_satellite_db';
    }
}
