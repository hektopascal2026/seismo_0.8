<?php

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Core\Fetcher\RssArticleHydrator;
use Seismo\Core\Fetcher\RssFetchService;
use Seismo\Feed\FeedModule;
use Seismo\Http\CsrfToken;
use Seismo\Repository\EntryRepository;
use Seismo\Repository\FeedRepository;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Service\RefreshAllService;
use Seismo\Service\RefreshMutexBusyException;

/**
 * Shared Feeds / Media admin module ({@see FeedModule}).
 */
final class FeedModuleHandler
{
    private const LIST_LIMIT = 50;

    public const PREVIEW_MAX_ITEMS = 5;

    public function __construct(private readonly FeedModule $module)
    {
    }

    public function show(): void
    {
        $csrfField = CsrfToken::field();
        $basePath  = getBasePath();
        $satellite = isSatellite();

        $view   = (isset($_GET['view']) && (string)$_GET['view'] === 'sources') ? 'sources' : 'items';
        $editId = (int)($_GET['edit'] ?? 0);

        $allItems            = [];
        $feedsList           = [];
        $editRow             = null;
        $pageError           = null;
        $alertThreshold      = 0.60;
        $categorySuggestions = [];

        try {
            $pdo = getDbConnection();
            $config = new SystemConfigRepository($pdo);
            $raw    = $config->get('alert_threshold');
            if ($raw !== null && $raw !== '' && is_numeric($raw)) {
                $alertThreshold = max(0.0, min(1.0, (float)$raw));
            }

            $entryRepo = new EntryRepository($pdo);
            $allItems  = $this->module->isMedia()
                ? $entryRepo->getMediaModuleTimeline(self::LIST_LIMIT, 0)
                : $entryRepo->getRssModuleTimeline(self::LIST_LIMIT, 0);

            $feedRepo  = new FeedRepository($pdo);
            $feedsList = $feedRepo->listRssSubstackModuleSources(FeedRepository::MAX_LIMIT, 0, $this->module->scope);
            if (!$satellite) {
                $categorySuggestions = $this->module->showCategoryField
                    ? $feedRepo->listUsedCategories()
                    : [];
            }
            if ($editId > 0) {
                $editRow = $feedRepo->findById($editId);
                if ($editRow !== null && !$this->feedBelongsToModule($editRow)) {
                    $editRow = null;
                }
            }
        } catch (\Throwable $e) {
            error_log('Seismo ' . $this->module->action . ': ' . $e->getMessage());
            $pageError = 'Could not load ' . $this->module->pageTitle . ' page. Check error_log for details.';
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
        $moduleRefreshAction     = $this->module->refreshAction;
        $moduleRefreshLabel      = 'Refresh ' . $this->module->pageTitle;
        $moduleRefreshReturnView = $view;
        $feedModule              = $this->module;

        require SEISMO_ROOT . '/views/feed_module.php';
    }

    public function refreshSources(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirect([]);

            return;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirectAfterRefresh();

            return;
        }
        if (isSatellite()) {
            $_SESSION['error'] = 'Satellite mode: refresh runs on the mothership.';
            $this->redirectAfterRefresh();

            return;
        }

        set_time_limit(300);
        try {
            $pdo     = getDbConnection();
            $service = RefreshAllService::boot($pdo);
            $results = $this->module->isMedia()
                ? $service->runMediaModuleCoreFetchers(true)
                : $service->runFeedModuleCoreFetchers(true);
        } catch (RefreshMutexBusyException $e) {
            $_SESSION['error'] = $e->getMessage();
            $this->redirectAfterRefresh();

            return;
        } catch (\Throwable $e) {
            error_log('Seismo ' . $this->module->refreshAction . ': ' . $e->getMessage());
            $_SESSION['error'] = 'Refresh failed: ' . $e->getMessage();
            $this->redirectAfterRefresh();

            return;
        }

        RefreshAllService::applySessionFlashForAggregateResults($results, $this->module->refreshFlashLabel);
        $this->redirectAfterRefresh();
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
            $_SESSION['error'] = 'Satellite mode — feed configuration is managed on the mothership only.';
            $this->redirect(['view' => 'sources']);

            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo  = getDbConnection();
            $repo = new FeedRepository($pdo);
            if ($id > 0) {
                $existing = $repo->findById($id);
                if ($existing === null || !$this->feedBelongsToModule($existing)) {
                    throw new \InvalidArgumentException('Feed not found in this module.');
                }
            }

            $sourceType = (string)($_POST['source_type'] ?? 'rss');
            if (!$this->module->allowParlPress && $sourceType === 'parl_press') {
                $sourceType = 'rss';
            }

            $category = $this->module->fixedCategory ?? trim((string)($_POST['category'] ?? ''));

            $payload = [
                'url'               => (string)($_POST['url'] ?? ''),
                'title'             => (string)($_POST['title'] ?? ''),
                'source_type'       => $sourceType,
                'description'       => (string)($_POST['description'] ?? ''),
                'link'              => (string)($_POST['link'] ?? ''),
                'category'          => $category,
                'disabled'          => ((string)($_POST['disabled'] ?? '0')) === '1',
                'extract_full_text' => ((string)($_POST['extract_full_text'] ?? '0')) === '1',
            ];
            if ($id > 0) {
                $repo->update($id, $payload);
                $_SESSION['success'] = 'Source updated.';
            } else {
                $newId = $repo->insert($payload);
                $_SESSION['success'] = 'Source added (#' . $newId . ').';
            }
        } catch (\Throwable $e) {
            error_log('Seismo ' . $this->module->saveAction . ': ' . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();
        }

        $this->redirect(['view' => 'sources']);
    }

    public function preview(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method not allowed. Use POST.'], JSON_UNESCAPED_UNICODE);

            return;
        }
        if (!CsrfToken::verifyRequest(rotateOnSuccess: false)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Session expired or invalid CSRF — reload the page.'], JSON_UNESCAPED_UNICODE);

            return;
        }
        if (isSatellite()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Satellite mode — configure sources on the mothership.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $url = trim((string)($_POST['url'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            echo json_encode(['ok' => false, 'error' => 'A valid http(s) feed URL is required.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $allowedTypes = $this->module->allowParlPress
            ? ['rss', 'substack', 'parl_press']
            : ['rss', 'substack'];
        $sourceType = strtolower(trim((string)($_POST['source_type'] ?? 'rss')));
        if (!in_array($sourceType, $allowedTypes, true)) {
            $sourceType = 'rss';
        }
        if ($sourceType === 'parl_press') {
            echo json_encode([
                'ok'    => false,
                'error' => 'Preview is for RSS and Substack only. Parliament Medien (parl_press) uses the SharePoint API — save the feed and use Refresh in Diagnostics to verify.',
            ], JSON_UNESCAPED_UNICODE);

            return;
        }

        $feedTitle = trim((string)($_POST['title'] ?? ''));
        if ($feedTitle === '') {
            $feedTitle = 'Preview feed';
        }
        $category = $this->module->fixedCategory ?? trim((string)($_POST['category'] ?? ''));

        try {
            $rows = (new RssFetchService())->fetchFeedItems($url);
        } catch (\Throwable $e) {
            echo json_encode([
                'ok'    => false,
                'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Could not load or parse the feed.',
            ], JSON_UNESCAPED_UNICODE);

            return;
        }

        if ($rows === []) {
            echo json_encode(['ok' => false, 'error' => 'No items in this feed.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $rows     = array_slice($rows, 0, self::PREVIEW_MAX_ITEMS);
        $loopType = $sourceType === 'substack' ? 'substack' : 'feed';

        $warnings = [];
        $hydrate  = ((string)($_POST['extract_full_text'] ?? '0')) === '1';
        if ($hydrate) {
            $hydrator = new RssArticleHydrator();
            $rows     = $hydrator->hydrateThinItems($rows, true, self::PREVIEW_MAX_ITEMS);
            foreach ($rows as $row) {
                if (!$hydrator->needsHydration($row)) {
                    continue;
                }
                $title = trim((string)($row['title'] ?? ''));
                $warnings[] = $title !== ''
                    ? 'Still thin after article fetch: ' . $title
                    : 'Still thin after article fetch for one preview item.';
            }
        }

        $html = $this->renderRssPreviewCards($rows, $feedTitle, $category, $loopType, $url, $sourceType);
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
            $_SESSION['error'] = 'Satellite mode — feed configuration is managed on the mothership only.';
            $this->redirect(['view' => 'sources']);

            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['error'] = 'Invalid feed.';
            $this->redirect(['view' => 'sources']);

            return;
        }

        try {
            $repo = new FeedRepository(getDbConnection());
            $row  = $repo->findById($id);
            if ($row === null || !$this->feedBelongsToModule($row)) {
                throw new \InvalidArgumentException('Feed not found in this module.');
            }
            $repo->delete($id);
            $_SESSION['success'] = 'Source deleted.';
        } catch (\Throwable $e) {
            error_log('Seismo ' . $this->module->deleteAction . ': ' . $e->getMessage());
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
            $_SESSION['error'] = 'Satellite mode — feed configuration is managed on the mothership only.';
            $this->redirect(['view' => 'sources']);

            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['error'] = 'Invalid feed.';
            $this->redirect(['view' => 'sources']);

            return;
        }

        try {
            $repo = new FeedRepository(getDbConnection());
            $row  = $repo->findById($id);
            if ($row === null || !$this->feedBelongsToModule($row)) {
                throw new \InvalidArgumentException('Feed not found in this module.');
            }
            $nowOff = $repo->toggleDisabled($id);
            $_SESSION['success'] = $nowOff
                ? 'Source disabled — refresh will skip it until you enable it again.'
                : 'Source enabled.';
        } catch (\Throwable $e) {
            error_log('Seismo ' . $this->module->toggleAction . ': ' . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();
        }

        $this->redirect(['view' => 'sources']);
    }

    /**
     * @param array<string, mixed> $feed
     */
    private function feedBelongsToModule(array $feed): bool
    {
        $cat = trim((string)($feed['category'] ?? ''));
        if ($this->module->isMedia()) {
            return $cat === FeedModule::CATEGORY_MEDIA;
        }

        return $cat !== FeedModule::CATEGORY_MEDIA && $cat !== 'scraper';
    }

    private function redirectAfterRefresh(): void
    {
        $v = trim((string)($_POST['return_view'] ?? ''));
        if ($v === 'sources') {
            $this->redirect(['view' => 'sources']);

            return;
        }
        $this->redirect([]);
    }

    /**
     * @param array<string, scalar|null> $query
     */
    private function redirect(array $query): void
    {
        $q = array_merge(['action' => $this->module->action], $query);
        header('Location: ?' . http_build_query($q), true, 303);
        exit;
    }

    private function buildReturnQuery(): string
    {
        $p = $_GET;
        if (!is_array($p)) {
            $p = [];
        }
        $p['action'] = $this->module->action;

        return http_build_query($p);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function renderRssPreviewCards(
        array $rows,
        string $feedName,
        string $feedCategory,
        string $loopType,
        string $feedUrl,
        string $sourceType
    ): string {
        require_once SEISMO_ROOT . '/views/helpers.php';

        $searchQuery        = '';
        $returnQuery        = 'action=' . $this->module->action . '&view=sources';
        $showFavourites     = false;
        $csrfField          = '';
        $relevanceScore     = null;
        $predictedLabel     = null;
        $scoreBadgeClass    = '';
        $favouriteEntryType = 'feed_item';
        $favouriteEntryId   = 0;
        $isFavourite        = false;
        $cat                = $feedCategory;
        if ($cat === 'unsortiert') {
            $cat = '';
        }

        ob_start();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $padded      = $this->padPreviewFeedItemRow($row, $feedName, $cat, $sourceType, $feedUrl);
            $itemWrapper = $this->buildFeedPreviewItemWrapper($padded, $loopType);
            require SEISMO_ROOT . '/views/partials/entry_card_rss_substack.php';
        }

        return (string)ob_get_clean();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function buildFeedPreviewItemWrapper(array $data, string $loopType): array
    {
        $ts   = (string)($data['published_date'] ?? '');
        $date = $ts !== '' ? (int)strtotime($ts) : 0;
        if ($date < 0) {
            $date = 0;
        }

        return [
            'type'         => $loopType,
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
    private function padPreviewFeedItemRow(
        array $row,
        string $feedName,
        string $category,
        string $sourceType,
        string $feedUrl
    ): array {
        return array_merge($row, [
            'id'                => 0,
            'feed_id'           => 0,
            'feed_name'         => $feedName,
            'feed_title'        => $feedName,
            'feed_source_type'  => $sourceType,
            'feed_category'     => $category,
            'feed_url'          => $feedUrl,
            'scraper_config_id' => 0,
            'cached_at'         => $row['published_date'] ?? null,
        ]);
    }
}
