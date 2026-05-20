<?php

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Core\Fetcher\ScraperFetchService;
use Seismo\Http\CsrfToken;
use Seismo\Repository\EntryRepository;
use Seismo\Repository\FeedItemRepository;
use Seismo\Repository\ScraperConfigRepository;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Service\RefreshAllService;
use Seismo\Service\RefreshMutexBusyException;

final class ScraperController
{
    private const LIST_LIMIT = 50;

    public function show(): void
    {
        $csrfField = CsrfToken::field();
        $basePath  = getBasePath();
        $satellite = isSatellite();

        $view = (isset($_GET['view']) && (string)$_GET['view'] === 'sources') ? 'sources' : 'items';
        $editId = (int)($_GET['edit'] ?? 0);

        $allItems    = [];
        $configsList = [];
        $editRow     = null;
        $pageError   = null;
        $alertThreshold = 0.60;

        try {
            $pdo = getDbConnection();
            $config = new SystemConfigRepository($pdo);
            $raw    = $config->get('alert_threshold');
            if ($raw !== null && $raw !== '' && is_numeric($raw)) {
                $alertThreshold = max(0.0, min(1.0, (float)$raw));
            }

            $entryRepo = new EntryRepository($pdo);
            $allItems = $entryRepo->getScraperModuleTimeline(self::LIST_LIMIT, 0);

            $scRepo = new ScraperConfigRepository($pdo);
            $configsList = $scRepo->listAll(ScraperConfigRepository::MAX_LIMIT, 0);
            if ($editId > 0) {
                $editRow = $scRepo->findById($editId);
            }
        } catch (\Throwable $e) {
            error_log('Seismo scraper: ' . $e->getMessage());
            $pageError = 'Could not load scraper page. Check error_log for details.';
        }

        require_once SEISMO_ROOT . '/views/helpers.php';

        $showDaySeparators = true;
        $showFavourites    = true;
        $searchQuery       = '';
        $returnQuery       = $this->buildReturnQuery();
        $currentView       = 'newest';
        $emptyTimelineHint = 'default';
        $timelineFilter    = \Seismo\Repository\TimelineFilter::fromQueryArray([]);
        $filterPillOptions = ['feed_categories' => [], 'lex_sources' => [], 'email_tags' => []];
        $dashboardError    = $pageError;

        $showModuleRefresh       = !$satellite;
        $moduleRefreshAction     = 'refresh_scraper_sources';
        $moduleRefreshLabel      = 'Refresh Scraper';
        $moduleRefreshReturnView = $view;

        require SEISMO_ROOT . '/views/scraper.php';
    }

    public function refreshScraperSources(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Location: ?action=scraper', true, 303);
            exit;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirectAfterScraperRefresh();

            return;
        }
        if (isSatellite()) {
            $_SESSION['error'] = 'Satellite mode: refresh runs on the mothership.';
            $this->redirectAfterScraperRefresh();

            return;
        }

        set_time_limit(300);
        try {
            $pdo     = getDbConnection();
            $results = RefreshAllService::boot($pdo)->runScraperModuleCoreFetcher(true);
        } catch (RefreshMutexBusyException $e) {
            $_SESSION['error'] = $e->getMessage();
            $this->redirectAfterScraperRefresh();

            return;
        } catch (\Throwable $e) {
            error_log('Seismo refresh_scraper_sources: ' . $e->getMessage());
            $_SESSION['error'] = 'Refresh failed: ' . $e->getMessage();
            $this->redirectAfterScraperRefresh();

            return;
        }

        RefreshAllService::applySessionFlashForAggregateResults($results, 'Scraper sources');
        $this->redirectAfterScraperRefresh();
    }

    private function redirectAfterScraperRefresh(): void
    {
        $v = trim((string)($_POST['return_view'] ?? ''));
        if ($v === 'sources') {
            header('Location: ?' . http_build_query(['action' => 'scraper', 'view' => 'sources']), true, 303);
        } else {
            header('Location: ?action=scraper', true, 303);
        }
        exit;
    }

    public function save(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirect(['view' => 'sources']);

            return;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirect(['view' => 'sources']);

            return;
        }
        if (isSatellite()) {
            $_SESSION['error'] = 'Satellite mode — scraper configuration is managed on the mothership only.';
            $this->redirect(['view' => 'sources']);

            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo       = getDbConnection();
            $repo      = new ScraperConfigRepository($pdo);
            $feedItems = new FeedItemRepository($pdo);
            $payload = [
                'name'                => (string)($_POST['name'] ?? ''),
                'url'                 => (string)($_POST['url'] ?? ''),
                'link_pattern'        => (string)($_POST['link_pattern'] ?? ''),
                'date_selector'       => (string)($_POST['date_selector'] ?? ''),
                'exclude_selectors'   => (string)($_POST['exclude_selectors'] ?? ''),
                'category'            => (string)($_POST['category'] ?? 'scraper'),
                'disabled'            => ((string)($_POST['disabled'] ?? '0')) === '1',
            ];
            $newUrl = trim($payload['url']);
            if ($id > 0) {
                $existing = $repo->findById($id);
                $oldUrl = trim((string)($existing['url'] ?? ''));
                $repo->update($id, $payload);
                if ($oldUrl !== '' && $oldUrl !== $newUrl) {
                    // URL changed: retire the old feeds row so it stops being
                    // listed as an orphan scraper-feed.
                    $feedItems->disableFeedsByUrl($oldUrl);
                }
                if (!$payload['disabled'] && $newUrl !== '') {
                    $feedItems->ensureScraperFeed($newUrl, $payload['name'], $payload['category']);
                }
                $_SESSION['success'] = 'Scraper source updated.';
            } else {
                $newId = $repo->insert($payload);
                if (!$payload['disabled'] && $newUrl !== '') {
                    $feedItems->ensureScraperFeed($newUrl, $payload['name'], $payload['category']);
                }
                $_SESSION['success'] = 'Scraper source added (#' . $newId . ').';
            }
        } catch (\Throwable $e) {
            error_log('Seismo scraper_save: ' . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();
        }

        $this->redirect(['view' => 'sources']);
    }

    /**
     * Dry-run preview: fetch listing / detail pages in memory, return HTML cards. No DB writes.
     * POST + CSRF only — never triggered by GET / refresh.
     */
    public function preview(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method not allowed. Use POST.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        // No token rotation: dry-run must not burn the form’s CSRF; user may preview
        // many times then submit Save with the same page token.
        if (!CsrfToken::verifyRequest(rotateOnSuccess: false)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Session expired or invalid CSRF — reload the page.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (isSatellite()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Satellite mode — configure scraper sources on the mothership.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            $name = 'Preview source';
        }
        $url = trim((string)($_POST['url'] ?? ''));
        $linkPattern = trim((string)($_POST['link_pattern'] ?? ''));
        $dateSelector = trim((string)($_POST['date_selector'] ?? ''));
        $excludeSelectors = trim((string)($_POST['exclude_selectors'] ?? ''));
        $category = trim((string)($_POST['category'] ?? 'scraper'));
        if ($category === '') {
            $category = 'scraper';
        }

        $fetcher  = new ScraperFetchService();
        $result   = $fetcher->preview(
            $url,
            $linkPattern,
            ScraperFetchService::PREVIEW_MAX_ITEMS,
            $dateSelector,
            $excludeSelectors
        );
        $warnings = $result['warnings'] ?? [];
        if (empty($result['ok']) || !empty($result['error'])) {
            echo json_encode([
                'ok'       => false,
                'error'    => (string)($result['error'] ?? 'Preview failed.'),
                'warnings' => $warnings,
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $items = $result['items'] ?? [];
        if ($items === []) {
            echo json_encode([
                'ok'       => false,
                'error'    => 'No items extracted.',
                'warnings' => $warnings,
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $html = $this->renderScraperPreviewCards($items, $name, $category);
        echo json_encode(
            [
                'ok'       => true,
                'html'     => $html,
                'warnings' => $warnings,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    public function delete(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirect(['view' => 'sources']);

            return;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirect(['view' => 'sources']);

            return;
        }
        if (isSatellite()) {
            $_SESSION['error'] = 'Satellite mode — scraper configuration is managed on the mothership only.';
            $this->redirect(['view' => 'sources']);

            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['error'] = 'Invalid scraper id.';

            $this->redirect(['view' => 'sources']);

            return;
        }

        try {
            $pdo  = getDbConnection();
            $repo = new ScraperConfigRepository($pdo);
            $row  = $repo->findById($id);
            if ($row === null) {
                $_SESSION['error'] = 'Scraper config not found.';
                $this->redirect(['view' => 'sources']);

                return;
            }
            $url = trim((string)($row['url'] ?? ''));
            $repo->delete($id);
            if ($url !== '') {
                $feedItems = new FeedItemRepository($pdo);
                $feedItems->disableFeedsByUrl($url);
            }
            $_SESSION['success'] = 'Scraper source deleted.';
        } catch (\Throwable $e) {
            error_log('Seismo scraper_delete: ' . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();
        }

        $this->redirect(['view' => 'sources']);
    }

    public function toggleDisabled(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirect(['view' => 'sources']);

            return;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirect(['view' => 'sources']);

            return;
        }
        if (isSatellite()) {
            $_SESSION['error'] = 'Satellite mode — scraper configuration is managed on the mothership only.';
            $this->redirect(['view' => 'sources']);

            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['error'] = 'Invalid scraper id.';
            $this->redirect(['view' => 'sources']);

            return;
        }

        try {
            $repo   = new ScraperConfigRepository(getDbConnection());
            $nowOff = $repo->toggleDisabled($id);
            $_SESSION['success'] = $nowOff
                ? 'Scraper source disabled — refresh will skip it until you enable it again.'
                : 'Scraper source enabled.';
        } catch (\Throwable $e) {
            error_log('Seismo scraper_toggle_disabled: ' . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();
        }

        $this->redirect(['view' => 'sources']);
    }

    /**
     * @param array<string, scalar|null> $query
     */
    private function redirect(array $query): void
    {
        $q = array_merge(['action' => 'scraper'], $query);
        header('Location: ?' . http_build_query($q), true, 303);
        exit;
    }

    private function buildReturnQuery(): string
    {
        $p = $_GET;
        if (!is_array($p)) {
            $p = [];
        }
        $p['action'] = 'scraper';

        return http_build_query($p);
    }

    /**
     * Pads each synthetic row to the shape the dashboard card partial expects
     * (avoids undefined index notices on feed_name, id, etc.).
     *
     * @param list<array<string, mixed>> $rows
     */
    private function renderScraperPreviewCards(array $rows, string $feedName, string $feedCategory): string
    {
        require_once SEISMO_ROOT . '/views/helpers.php';

        $searchQuery   = '';
        $returnQuery   = 'action=scraper&view=sources';
        $showFavourites = false;
        $csrfField     = '';
        $relevanceScore = null;
        $predictedLabel = null;
        $scoreBadgeClass = '';
        $favouriteEntryType = 'feed_item';
        $favouriteEntryId   = 0;
        $isFavourite        = false;

        ob_start();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $itemWrapper = $this->buildPreviewItemWrapper($row, $feedName, $feedCategory);
            require SEISMO_ROOT . '/views/partials/entry_card_scraper.php';
        }

        return (string)ob_get_clean();
    }

    /**
     * @param array<string, mixed> $row Normalised row from ScraperFetchService
     * @return array<string, mixed>
     */
    private function buildPreviewItemWrapper(array $row, string $feedName, string $feedCategory): array
    {
        $data = $this->padPreviewFeedItemRow($row, $feedName, $feedCategory);
        $ts   = (string)($data['published_date'] ?? '');
        $date = $ts !== '' ? (int)strtotime($ts) : 0;
        if ($date < 0) {
            $date = 0;
        }

        return [
            'type'         => 'scraper',
            'entry_type'   => 'feed_item',
            'entry_id'     => 0,
            'date'         => $date,
            'data'         => $data,
            'score'        => null,
            'is_favourite' => false,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function padPreviewFeedItemRow(array $row, string $feedName, string $feedCategory): array
    {
        return array_merge($row, [
            'id'                 => 0,
            'feed_id'            => 0,
            'feed_name'          => $feedName,
            'feed_source_type'  => 'scraper',
            'feed_category'     => $feedCategory,
            'scraper_config_id' => 0,
            'cached_at'         => $row['published_date'] ?? null,
            'title'             => (string)($row['title'] ?? ''),
            'link'              => (string)($row['link'] ?? ''),
            'description'      => (string)($row['description'] ?? ''),
            'content'            => (string)($row['content'] ?? ''),
            'author'             => (string)($row['author'] ?? ''),
            'guid'               => (string)($row['guid'] ?? ''),
            'content_hash'       => (string)($row['content_hash'] ?? ''),
            'published_date'     => $row['published_date'] ?? null,
        ]);
    }
}
