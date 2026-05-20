<?php

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Config\CalendarConfigStore;
use Seismo\Http\CsrfToken;
use Seismo\Plugin\PluginLanguage;
use Seismo\Repository\CalendarEventRepository;
use Seismo\Repository\EntryScoreRepository;
use Seismo\Service\RefreshAllService;

/**
 * Leg (parliamentary business) page controller.
 *
 * Table/API names still say `calendar_*` for backwards compatibility with the
 * schema and Magnitu entry_type ENUM; user-facing labels say "Leg".
 */
final class LegController
{
    private const LIST_LIMIT = 100;

    public function show(): void
    {
        $csrfField = CsrfToken::field();
        $satellite = isSatellite();
        $viewParam = (string)($_GET['view'] ?? '');
        $view = ($viewParam === 'sources') ? 'sources' : 'items';

        $events = [];
        $calendarCfg = [];
        $enabledSources = [];
        $activeSources = [];
        $eventTypes = [];
        $eventType = '';
        $showPast = false;
        $lastBySource     = [];
        $pageError        = null;
        $totalRows        = 0;
        $hiddenPastRows   = 0;
        $legEntryScores   = [];

        try {
            $pdo = getDbConnection();
            $calendarCfg = (new CalendarConfigStore())->load();
            $enabledSources = array_values(array_filter(
                CalendarEventRepository::LEG_PAGE_SOURCES,
                static function (string $s) use ($calendarCfg): bool {
                    return !empty($calendarCfg[$s]['enabled']);
                }
            ));

            $repo = new CalendarEventRepository($pdo);
            $lastBySource = $repo->getLastFetchedBySources(CalendarEventRepository::LEG_PAGE_SOURCES);

            if ($view === 'items') {
                $sourcesSubmitted = isset($_GET['sources_submitted']);
                if ($sourcesSubmitted) {
                    $activeSources = isset($_GET['sources']) ? (array)$_GET['sources'] : [];
                } else {
                    $activeSources = $enabledSources;
                }
                $activeSources = array_values(array_intersect($activeSources, $enabledSources));

                $showPast = !empty($_GET['show_past']);
                $eventType = trim((string)($_GET['event_type'] ?? ''));

                if ($activeSources !== []) {
                    $typeFilter = $eventType !== '' ? $eventType : null;
                    $events = $repo->listBySources(
                        $activeSources,
                        self::LIST_LIMIT,
                        0,
                        $showPast,
                        $typeFilter
                    );
                    $eventTypes = $repo->distinctEventTypes($activeSources);

                    // When the upcoming-only view returns nothing, we still want
                    // to tell the user whether rows actually exist (so they can
                    // flip "Show all") or the DB really is empty. Skip the extra
                    // COUNT when rows were returned — nothing to disambiguate.
                    if ($events === [] && !$showPast) {
                        $totalRows = $repo->countBySources($activeSources, true, $typeFilter);
                        $hiddenPastRows = $totalRows;
                    }

                    $pairs = [];
                    foreach ($events as $ev) {
                        $eid = (int)($ev['id'] ?? 0);
                        if ($eid > 0) {
                            $pairs[] = ['calendar_event', $eid];
                        }
                    }
                    $legEntryScores = (new EntryScoreRepository($pdo))->fetchScoresIndexedByPairs($pairs);
                }
            }
        } catch (\Throwable $e) {
            error_log('Seismo leg: ' . $e->getMessage());
            $pageError = 'Could not load Leg entries. Check error_log for details.';
        }

        $lastFetchedBySource = $lastBySource;

        $basePath = getBasePath();
        $parlChCfg = is_array($calendarCfg['parliament_ch'] ?? null) ? $calendarCfg['parliament_ch'] : [];

        $showModuleRefresh       = !$satellite;
        $moduleRefreshAction     = 'refresh_parl_ch';
        $moduleRefreshLabel      = 'Refresh Parlament CH';
        $moduleRefreshReturnView = $view;

        require_once SEISMO_ROOT . '/views/helpers.php';
        require SEISMO_ROOT . '/views/leg.php';
    }

    public function refreshParlCh(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirectAfterLegRefresh();

            return;
        }

        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirectAfterLegRefresh();

            return;
        }

        try {
            $pdo = getDbConnection();
            $result = RefreshAllService::boot($pdo)->runPlugin('parl_ch', true);
        } catch (\Throwable $e) {
            error_log('Seismo refresh_parl_ch: ' . $e->getMessage());
            $_SESSION['error'] = 'Parlament CH refresh failed: ' . $e->getMessage();
            $this->redirectAfterLegRefresh();

            return;
        }

        if ($result->isOk()) {
            $_SESSION['success'] = 'Parlament CH refresh finished: ' . $result->count . ' row(s) processed.';
        } elseif ($result->status === 'skipped') {
            $_SESSION['error'] = $result->message ?? 'Parlament CH refresh skipped.';
        } else {
            $_SESSION['error'] = 'Parlament CH refresh failed: ' . ($result->message ?? 'unknown error');
        }

        $this->redirectAfterLegRefresh();
    }

    public function saveLegParlCh(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirectToLeg(['view' => 'sources']);

            return;
        }

        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirectToLeg(['view' => 'sources']);

            return;
        }

        $store = new CalendarConfigStore();
        $isEnabled = static function (string $field, bool $default = false): bool {
            if (!array_key_exists($field, $_POST)) {
                return $default;
            }
            $raw = $_POST[$field];
            if (is_array($raw)) {
                return $raw !== [];
            }
            $value = strtolower(trim((string)$raw));

            return in_array($value, ['1', 'true', 'yes', 'on'], true);
        };

        try {
            $full = $store->load();
            $block = is_array($full['parliament_ch'] ?? null) ? $full['parliament_ch'] : [];
            $block['enabled'] = $isEnabled('parliament_ch_enabled', (bool)($block['enabled'] ?? true));
            $block['language'] = PluginLanguage::parlCh(
                (string)($_POST['parliament_ch_language'] ?? $block['language'] ?? 'DE')
            );
            $block['lookforward_days'] = max(7, min(365, (int)($_POST['parliament_ch_lookforward_days'] ?? $block['lookforward_days'] ?? 90)));
            $block['lookback_days'] = max(1, min(90, (int)($_POST['parliament_ch_lookback_days'] ?? $block['lookback_days'] ?? 7)));
            $block['limit'] = max(10, min(500, (int)($_POST['parliament_ch_limit'] ?? $block['limit'] ?? 100)));
            $block['notes'] = trim((string)($_POST['parliament_ch_notes'] ?? $block['notes'] ?? ''));

            $store->saveParlChBlock($block);
            $_SESSION['success'] = 'Parlament CH settings saved.';
        } catch (\Throwable $e) {
            error_log('Seismo save_leg_parl_ch: ' . $e->getMessage());
            $_SESSION['error'] = 'Could not save Parlament CH settings.';
        }

        $this->redirectToLeg(['view' => 'sources']);
    }

    private function redirectAfterLegRefresh(): void
    {
        $v = trim((string)($_POST['return_view'] ?? ''));
        if ($v === 'sources') {
            $this->redirectToLeg(['view' => 'sources']);

            return;
        }
        $this->redirectToLeg();
    }

    /**
     * @param array<string, scalar|null> $query
     */
    private function redirectToLeg(array $query = []): void
    {
        $q = array_merge(['action' => 'leg'], $query);
        header('Location: ' . getBasePath() . '/index.php?' . http_build_query($q), true, 303);
        exit;
    }
}
