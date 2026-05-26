<?php
/**
 * Dashboard / timeline controller.
 *
 * Slice 1.5 adds read-only search (`?q=`), favourites view (`?view=favourites`),
 * per-card star buttons, and delegates the POST toggle to FavouriteController.
 */

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Http\CsrfToken;
use Seismo\Repository\EntryRepository;
use Seismo\Core\Scoring\ScoringService;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Repository\TimelineFilter;

final class DashboardController
{
    /** Fallback when `ui:dashboard_limit` is not set in system_config. */
    public const DEFAULT_LIMIT_FALLBACK = 30;

    /**
     * Deep-paging guard — must stay within {@see EntryRepository::MERGE_PER_SOURCE_CAP}
     * minus the largest allowed page size.
     */
    private const MAX_OFFSET = EntryRepository::MERGE_PER_SOURCE_CAP - EntryRepository::MAX_LIMIT;

    /** Session key for timeline newest vs favourites-only view. */
    private const SESSION_TIMELINE_VIEW = '_seismo_timeline_view';

    public function show(): void
    {
        $csrfField = CsrfToken::field();

        $limit  = $this->clampLimit($_GET['limit'] ?? null);
        $offset = $this->clampOffset($_GET['offset'] ?? null);

        $searchQuery = trim((string)($_GET['q'] ?? ''));
        $currentView = $this->resolveTimelineView();

        $dashboardError = null;
        $allItems        = [];
        $pillOpts       = ['feed_categories' => [], 'lex_sources' => [], 'email_tags' => []];
        $timelineFilter = TimelineFilter::fromQueryArray($_GET);
        $alertThreshold    = $this->resolveAlertThreshold();
        $sortByRelevance   = $currentView !== 'favourites' && $this->resolveSortByRelevance();
        $timelineMediaOn   = $this->resolveTimelineMediaOn();
        $excludeMediaCategory = !$timelineMediaOn;

        try {
            $pdo  = getDbConnection();
            $repo = new EntryRepository($pdo);
            $pillOpts        = $repo->getFilterPillOptions();
            $timelineFilter = TimelineFilter::fromHttpGet($_GET, $pillOpts);
            if ($currentView === 'favourites') {
                $allItems = $repo->getFavouritesTimeline($limit, $offset, $timelineFilter, $excludeMediaCategory);
            } elseif ($searchQuery !== '') {
                $allItems = $repo->searchTimeline(
                    $searchQuery,
                    $limit,
                    $offset,
                    $timelineFilter,
                    $sortByRelevance,
                    $excludeMediaCategory,
                );
            } else {
                $allItems = $repo->getLatestTimeline(
                    $limit,
                    $offset,
                    $timelineFilter,
                    $sortByRelevance,
                    $excludeMediaCategory,
                );
            }
        } catch (\Throwable $e) {
            error_log('Seismo dashboard: ' . $e->getMessage());
            $dashboardError = 'Database error. Check error_log for details.';
        }

        require_once SEISMO_ROOT . '/views/helpers.php';

        $showDaySeparators   = true;
        $showFavourites      = true;
        $showTimelineRefresh = self::shouldShowTimelineRefresh();
        $timelineRefreshAction = isSatellite() ? 'refresh_remote' : 'refresh_all';
        $timelineRefreshReturnAction = 'index';
        $returnQuery         = $this->buildReturnQuery('index', $currentView);

        $emptyTimelineHint = 'default';
        if ($dashboardError === null) {
            if ($currentView === 'favourites') {
                $emptyTimelineHint = 'favourites';
            } elseif ($searchQuery !== '') {
                $emptyTimelineHint = 'search';
            } elseif ($timelineFilter->isActive()) {
                $emptyTimelineHint = 'filters';
            }
        }

        require SEISMO_ROOT . '/views/index.php';
    }

    /**
     * Dashboard filter editor: checkbox pills + live timeline preview on the same page.
     */
    public function showFilter(): void
    {
        $csrfField = CsrfToken::field();

        $limit  = $this->clampLimit($_GET['limit'] ?? null);
        $offset = $this->clampOffset($_GET['offset'] ?? null);

        $searchQuery = trim((string)($_GET['q'] ?? ''));
        $currentView = $this->resolveTimelineView();

        $dashboardError = null;
        $allItems        = [];
        $pillOpts       = ['feed_categories' => [], 'lex_sources' => [], 'email_tags' => []];
        $timelineFilter = TimelineFilter::fromQueryArray($_GET);
        $alertThreshold    = $this->resolveAlertThreshold();
        $sortByRelevance   = $currentView !== 'favourites' && $this->resolveSortByRelevance();

        try {
            $pdo  = getDbConnection();
            $repo = new EntryRepository($pdo);
            $pillOpts        = $repo->getFilterPillOptions();
            $timelineFilter = TimelineFilter::fromHttpGet($_GET, $pillOpts);
            if ($currentView === 'favourites') {
                $allItems = $repo->getFavouritesTimeline($limit, $offset, $timelineFilter);
            } elseif ($searchQuery !== '') {
                $allItems = $repo->searchTimeline($searchQuery, $limit, $offset, $timelineFilter, $sortByRelevance);
            } else {
                $allItems = $repo->getLatestTimeline($limit, $offset, $timelineFilter, $sortByRelevance);
            }
        } catch (\Throwable $e) {
            error_log('Seismo dashboard filter page: ' . $e->getMessage());
            $dashboardError = 'Database error. Check error_log for details.';
        }

        require_once SEISMO_ROOT . '/views/helpers.php';

        $filterPillOptions = $pillOpts;
        $returnQuery      = $this->buildReturnQuery('filter', $currentView);

        $emptyTimelineHint = 'default';
        if ($dashboardError === null) {
            if ($currentView === 'favourites') {
                $emptyTimelineHint = 'favourites';
            } elseif ($searchQuery !== '') {
                $emptyTimelineHint = 'search';
            } elseif ($timelineFilter->isActive()) {
                $emptyTimelineHint = 'filters';
            }
        }

        $showTimelineRefresh = self::shouldShowTimelineRefresh();
        $timelineRefreshAction = isSatellite() ? 'refresh_remote' : 'refresh_all';
        $timelineRefreshReturnAction = 'filter';

        require SEISMO_ROOT . '/views/dashboard_filters.php';
    }

    /**
     * Satellite POST handler — proxies to the mothership {@see DiagnosticsController::refreshAllRemote()}
     * using {@see seismoMothershipBaseUrl()} and {@see seismoRemoteRefreshKey()}.
     * Same ingest scope as the mothership timeline toolbar (Lex plugins omitted).
     */
    public function refreshRemote(): void
    {
        if (!isSatellite()) {
            http_response_code(404);
            echo 'Unknown action.';

            return;
        }
        $ajax = isset($_POST['ajax']) && (string) $_POST['ajax'] === '1';
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Location: ' . getBasePath() . '/index.php?action=index', true, 303);
            exit;
        }
        // Same as mothership refresh_all — do not rotate; other toolbar POSTs reuse this token.
        if (!CsrfToken::verifyRequest(rotateOnSuccess: false)) {
            $msg = 'Session expired — please try again.';
            $_SESSION['error'] = $msg;
            if ($ajax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(403);
                echo json_encode(
                    [
                        'ok'      => false,
                        'message' => null,
                        'error'   => $msg,
                    ],
                    JSON_UNESCAPED_UNICODE
                );
                exit;
            }
            $this->redirectAfterRemoteRefresh();
        }

        $mother = seismoMothershipBaseUrl();
        $key = seismoRemoteRefreshKey();
        if ($key === '') {
            $_SESSION['error'] = 'Remote refresh is not configured — enable it on the mothership under Settings → Satellites.';

            $this->endRemoteRefreshOrJson($ajax);

            return;
        }

        $url = rtrim($mother, '/') . '/index.php?' . http_build_query([
            'action' => 'refresh_all_remote',
            'key'    => $key,
        ]);

        [$status, $body] = self::httpGet($url);

        if ($status === 0 && $body === '') {
            $_SESSION['error'] = 'Could not reach the mothership for refresh (network or TLS error).';

            $this->endRemoteRefreshOrJson($ajax);

            return;
        }

        /** @var mixed $json */
        $json = json_decode($body, true);
        if (!is_array($json)) {
            $_SESSION['error'] = $status >= 400
                ? 'Mothership refresh failed (HTTP ' . $status . ').'
                : 'Mothership returned a non-JSON response.';

            $this->endRemoteRefreshOrJson($ajax);

            return;
        }

        $ok = (bool)($json['ok'] ?? false);
        if ($status === 401 || ($status >= 400 && !$ok && (($json['error'] ?? '') === 'invalid key'))) {
            $_SESSION['error'] = 'Remote refresh rejected — rotate the refresh key on the mothership (Settings → Satellites).';
        } elseif ($status === 429) {
            $retry = (int)($json['retry_after'] ?? 0);
            $_SESSION['error'] = $retry > 0
                ? 'Mothership refresh rate limited — retry in ' . $retry . 's.'
                : 'Mothership refresh rate limited — try again shortly.';
        } elseif ($status >= 400 && !$ok) {
            $err = trim((string)($json['error'] ?? ''));
            $_SESSION['error'] = $err !== ''
                ? 'Mothership: ' . $err
                : 'Mothership refresh failed (HTTP ' . $status . ').';
        } elseif ($ok) {
            if (function_exists('isSatellite') && isSatellite()) {
                ScoringService::rescoreStoredRecipeBestEffort(getDbConnection());
            }
            $msgs = $json['messages'] ?? [];
            $summary = is_array($msgs) && $msgs !== []
                ? implode(' ', array_map(static fn ($m): string => (string)$m, $msgs))
                : 'Refresh completed.';
            $_SESSION['success'] = $summary;
        } else {
            $err = trim((string)($json['error'] ?? ''));
            $_SESSION['error'] = $err !== '' ? 'Mothership: ' . $err : 'Refresh finished with errors.';
        }

        $this->endRemoteRefreshOrJson($ajax);
    }

    /**
     * After building flash in {@see self::refreshRemote()}, either JSON (timeline AJAX)
     * or 303 redirect.
     */
    private function endRemoteRefreshOrJson(bool $ajax): void
    {
        if (!$ajax) {
            $this->redirectAfterRemoteRefresh();
        }
        $success = $_SESSION['success'] ?? null;
        $error = $_SESSION['error'] ?? null;
        $ok = ! (is_string($error) && $error !== '');
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(200);
        echo json_encode([
            'ok'      => $ok,
            'message' => is_string($success) ? $success : null,
            'error'   => is_string($error) ? $error : null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Timeline + filter pages: show Refresh when mothership refresh is possible.
     */
    private static function shouldShowTimelineRefresh(): bool
    {
        if (!isSatellite()) {
            return true;
        }

        return seismoRemoteRefreshKeyConfigured();
    }

    private function redirectAfterRemoteRefresh(): void
    {
        $t = trim((string)($_POST['return_action'] ?? ''));

        $action = $t === 'filter' ? 'filter' : 'index';
        header('Location: ' . getBasePath() . '/index.php?action=' . rawurlencode($action), true, 303);
        exit;
    }

    /**
     * @return array{0: int, 1: string} HTTP status (0 if unknown), response body
     */
    private static function httpGet(string $url): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 320,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

            return [$code, $body === false ? '' : (string)$body];
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout'       => 320,
                'header'        => "Accept: application/json\r\n",
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (!empty($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
            $code = (int)$m[1];
        }

        return [$code, $body === false ? '' : (string)$body];
    }

    /**
     * Newest vs favourites for index/filter. Persists in session when toggled.
     *
     * @return 'newest'|'favourites'
     */
    /** Main index timeline: include `feeds.category = media` rows when `?show_media=1`. */
    private function resolveTimelineMediaOn(): bool
    {
        return isset($_GET['show_media']) && (string)$_GET['show_media'] === '1';
    }

    private function resolveTimelineView(): string
    {
        if (isset($_GET['view'])) {
            $raw = trim((string)$_GET['view']);
            if ($raw === 'favourites') {
                $_SESSION[self::SESSION_TIMELINE_VIEW] = 'favourites';

                return 'favourites';
            }
            if ($raw === 'newest' || $raw === '') {
                $_SESSION[self::SESSION_TIMELINE_VIEW] = 'newest';

                return 'newest';
            }
        }

        $stored = $_SESSION[self::SESSION_TIMELINE_VIEW] ?? 'newest';

        return $stored === 'favourites' ? 'favourites' : 'newest';
    }

    /**
     * Preserve dashboard GET state for favourite form round-trips (no leading "?").
     *
     * @param 'newest'|'favourites' $currentView
     */
    private function buildReturnQuery(string $action = 'index', string $currentView = 'newest'): string
    {
        $p = $_GET;
        if (!is_array($p)) {
            $p = [];
        }
        $p['action'] = $action === 'filter' ? 'filter' : 'index';
        if ($currentView === 'favourites') {
            $p['view'] = 'favourites';
        } else {
            unset($p['view']);
        }

        return http_build_query($p);
    }

    private function clampLimit(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return $this->resolveDefaultLimitFromConfig();
        }
        $n = (int)$raw;
        if ($n <= 0) {
            return $this->resolveDefaultLimitFromConfig();
        }
        if ($n > EntryRepository::MAX_LIMIT) {
            return EntryRepository::MAX_LIMIT;
        }

        return $n;
    }

    /**
     * Magnitu "alert" badge threshold (0.0–1.0). Stored in `system_config`;
     * defaults to **0.60** when unset (matches Magnitu settings form default).
     *
     * Lowered from 0.75 in May 2026 — with the current recipe weights (typical
     * unigram = 0.12, multi-word = 0.24), 0.75 is unreachable in practice;
     * 0.60 corresponds roughly to "two or three anchor-concept matches in one
     * document". See README "Scoring tuning (May 2026)".
     */
    private function resolveAlertThreshold(): float
    {
        try {
            return (new SystemConfigRepository(getDbConnection()))->getAlertThreshold();
        } catch (\Throwable $e) {
            // fall through
        }

        return 0.60;
    }

    /**
     * When true, merged timeline sorts by relevance_score then entry date
     * (newest + search views only — not favourites).
     */
    private function resolveSortByRelevance(): bool
    {
        try {
            $config = new SystemConfigRepository(getDbConnection());

            return ((string)$config->get('sort_by_relevance')) === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function resolveDefaultLimitFromConfig(): int
    {
        try {
            $config = new SystemConfigRepository(getDbConnection());
            $raw    = $config->get(SettingsController::KEY_DASHBOARD_LIMIT);
            if ($raw !== null && $raw !== '' && ctype_digit($raw)) {
                return max(1, min(EntryRepository::MAX_LIMIT, (int)$raw));
            }
        } catch (\Throwable $e) {
            // Fresh install / transient DB — fall back.
        }

        return self::DEFAULT_LIMIT_FALLBACK;
    }

    private function clampOffset(mixed $raw): int
    {
        $n = (int)$raw;
        if ($n <= 0) {
            return 0;
        }
        return $n > self::MAX_OFFSET ? self::MAX_OFFSET : $n;
    }
}
