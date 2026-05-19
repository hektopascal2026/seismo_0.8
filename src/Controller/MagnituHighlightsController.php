<?php
/**
 * Read-only **Highlights** timeline (session UI).
 *
 * Lists entries whose current score (Magnitu **or** recipe) is at/above
 * `alert_threshold` from `system_config`. Originally restricted to
 * Magnitu-sourced rows in Slice 7a; widened to include recipe scores so an
 * entry can reach Highlights from Seismo's own deterministic scorer without
 * waiting for **Magnitu v3** to pull, score, and push back. Magnitu's ML
 * output stays authoritative — once a Magnitu score arrives the row's
 * `score_source` flips to `magnitu` and the recipe row is overwritten by the
 * precedence rule in {@see EntryScoreRepository::upsertRecipeScore()}.
 *
 * The Bearer Magnitu API stays on {@see MagnituController}; this controller
 * is human-facing navigation only. Controller class name and `?action=magnitu`
 * route kept for URL stability — the nav label is already "Highlights".
 */

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Http\CsrfToken;
use Seismo\Repository\EntryRepository;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Repository\TimelineFilter;

final class MagnituHighlightsController
{
    public function show(): void
    {
        $csrfField = CsrfToken::field();

        $allItems       = [];
        $pageError      = null;
        $alertThreshold = 0.60;

        try {
            $pdo    = getDbConnection();
            $config = new SystemConfigRepository($pdo);
            $raw    = $config->get('alert_threshold');
            if ($raw !== null && $raw !== '' && is_numeric($raw)) {
                $alertThreshold = max(0.0, min(1.0, (float)$raw));
            }

            $repo   = new EntryRepository($pdo);
            $limit  = $this->clampLimit($_GET['limit'] ?? null, $pdo);
            $offset = max(0, (int)($_GET['offset'] ?? 0));
            $allItems = $repo->getHighlightsTimeline($alertThreshold, $limit, $offset);
        } catch (\Throwable $e) {
            error_log('Seismo magnitu highlights: ' . $e->getMessage());
            $pageError = 'Could not load highlights. Check error_log for details.';
        }

        require_once SEISMO_ROOT . '/views/helpers.php';

        $showDaySeparators = true;
        $showFavourites    = true;
        $searchQuery       = '';
        $returnQuery       = $this->buildReturnQuery();
        $currentView       = 'newest';
        $emptyTimelineHint = $pageError === null && $allItems === [] ? 'highlights' : 'default';
        $timelineFilter    = TimelineFilter::fromQueryArray([]);
        $filterPillOptions = ['feed_categories' => [], 'lex_sources' => [], 'email_tags' => []];
        $dashboardError    = $pageError;

        require SEISMO_ROOT . '/views/magnitu.php';
    }

    /**
     * Preserve GET state for favourite form round-trips (no leading "?").
     */
    private function buildReturnQuery(): string
    {
        $p = $_GET;
        if (!is_array($p)) {
            $p = [];
        }
        $p['action'] = 'magnitu';

        return http_build_query($p);
    }

    private function clampLimit(mixed $raw, \PDO $pdo): int
    {
        if ($raw === null || $raw === '') {
            return $this->defaultTimelineLimit($pdo);
        }
        $n = (int)$raw;
        if ($n <= 0) {
            return $this->defaultTimelineLimit($pdo);
        }

        return min(EntryRepository::MAX_LIMIT, max(1, $n));
    }

    private function defaultTimelineLimit(\PDO $pdo): int
    {
        try {
            $config = new SystemConfigRepository($pdo);
            $raw    = $config->get(SettingsController::KEY_DASHBOARD_LIMIT);
            if ($raw !== null && $raw !== '' && ctype_digit($raw)) {
                return max(1, min(EntryRepository::MAX_LIMIT, (int)$raw));
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return DashboardController::DEFAULT_LIMIT_FALLBACK;
    }
}
