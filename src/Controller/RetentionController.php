<?php
/**
 * Retention settings — read the current per-family policy, preview
 * how many rows would be deleted today, edit the policy, and run a
 * real prune on demand.
 *
 * Four actions:
 *   - `?action=retention`            GET   — render the grid + edit form.
 *   - `?action=retention_preview`    POST  — redirect back with fresh counts.
 *   - `?action=retention_save`       POST  — persist per-family days + keeps.
 *   - `?action=retention_prune`      POST  — run the actual DELETE, flash the
 *                                             row counts to the admin.
 *
 * Satellite safety: `retention_prune` refuses to run on a satellite
 * (entry-source tables are owned by the mothership). The page still
 * renders a read-only preview so the admin can see what the mothership
 * would do. Every state-changing POST requires a CSRF token + an
 * optional auth login (per `auth-dormant-by-default.mdc` — retention
 * is a protected action, never whitelisted).
 *
 * GET `?action=retention` redirects to Settings → Retention (`?action=settings&tab=retention`).
 * POST handlers (`retention_*`) still flash and redirect back to that tab.
 */

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Http\CsrfToken;
use Seismo\Service\RetentionService;

final class RetentionController
{
    private const FAMILIES = ['feed_items', 'emails', 'lex_items', 'calendar_events'];

    public function show(): void
    {
        header('Location: ' . getBasePath() . '/index.php?action=settings&tab=retention', true, 303);
        exit;
    }

    /**
     * Handler for the "Refresh preview" button on the Retention page.
     * The `show()` path already recomputes counts on every render, so
     * this action is effectively just a "redirect back with the flash
     * message that policies are current". Kept as a POST to keep the
     * button CSRF-protected rather than offering a bare GET that a
     * random link could trigger a preview on.
     */
    public function preview(): void
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
        $_SESSION['success'] = 'Preview refreshed.';
        $this->redirect();
    }

    public function save(): void
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
            $pdo     = getDbConnection();
            $service = RetentionService::boot($pdo);

            foreach (self::FAMILIES as $family) {
                $rawDays = $_POST[$family . '_days'] ?? null;
                if ($rawDays === null || $rawDays === '') {
                    // Empty field means "unlimited" (no auto-prune).
                    $service->savePolicy($family, null, $this->keepsForFamily($family));
                    continue;
                }
                $days = (int)$rawDays;
                if ($days <= 0) {
                    $service->savePolicy($family, null, $this->keepsForFamily($family));
                    continue;
                }
                $service->savePolicy($family, $days, $this->keepsForFamily($family));
            }

            $_SESSION['success'] = 'Retention policies saved.';
        } catch (\Throwable $e) {
            error_log('Seismo retention save: ' . $e->getMessage());
            $_SESSION['error'] = 'Could not save retention policies.';
        }

        $this->redirect();
    }

    public function runPrune(): void
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
        if (isSatellite()) {
            $_SESSION['error'] = 'Satellite mode — retention runs on the mothership only.';
            $this->redirect();
            return;
        }

        try {
            $pdo     = getDbConnection();
            $service = RetentionService::boot($pdo);
            $results = $service->pruneAll();
        } catch (\Throwable $e) {
            error_log('Seismo retention prune: ' . $e->getMessage());
            $_SESSION['error'] = 'Retention prune failed: ' . $e->getMessage();
            $this->redirect();
            return;
        }

        $total = array_sum($results);
        if ($total === 0) {
            $_SESSION['success'] = 'Retention run complete — nothing to delete.';
        } else {
            $parts = [];
            foreach ($results as $family => $n) {
                if ($n > 0) {
                    $parts[] = $family . ': ' . $n;
                }
            }
            $_SESSION['success'] = 'Retention run complete — deleted ' . $total . ' row(s) (' . implode(', ', $parts) . ').';
        }

        $this->redirect();
    }

    /**
     * Keep-predicate tokens for a family from the POSTed form. Absent
     * checkboxes collapse to the built-in default set so an admin
     * saving the form with the boxes cleared doesn't silently disable
     * protection for favourites / labelled rows — the form always
     * reflects the current keeps when re-rendered.
     *
     * @return list<string>
     */
    private function keepsForFamily(string $family): array
    {
        $raw = $_POST[$family . '_keep'] ?? null;
        if (!is_array($raw)) {
            return RetentionService::DEFAULT_KEEPS;
        }
        $allowed = [
            RetentionService::KEEP_FAVOURITED,
            RetentionService::KEEP_HIGH_SCORE,
            RetentionService::KEEP_LABELLED,
        ];
        $keeps = array_values(array_intersect(array_map('strval', $raw), $allowed));
        return $keeps === [] ? RetentionService::DEFAULT_KEEPS : $keeps;
    }

    private function redirect(): void
    {
        header('Location: ' . getBasePath() . '/index.php?action=settings&tab=retention', true, 303);
        exit;
    }
}
