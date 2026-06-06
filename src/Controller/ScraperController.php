<?php

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Core\Fetcher\ScraperFetchService;
use Seismo\Http\CsrfToken;
use Seismo\Http\RefreshAjax;
use Seismo\Repository\EntryRepository;
use Seismo\Repository\FeedItemRepository;
use Seismo\Repository\ScraperConfigRepository;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Service\Http\BaseClient;
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
        $finish = function (): void {
            RefreshAjax::respondOrRedirect(function (): void {
                $this->redirectAfterScraperRefresh();
            });
        };

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Location: ?action=scraper', true, 303);
            exit;
        }
        if (!CsrfToken::verifyRequest(rotateOnSuccess: false)) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $finish();

            return;
        }
        if (isSatellite()) {
            $_SESSION['error'] = 'Satellite mode: refresh runs on the mothership.';
            $finish();

            return;
        }

        set_time_limit(300);
        try {
            $pdo     = getDbConnection();
            $results = RefreshAllService::boot($pdo)->runScraperModuleCoreFetcher(true);
        } catch (RefreshMutexBusyException $e) {
            $_SESSION['error'] = $e->getMessage();
            $finish();

            return;
        } catch (\Throwable $e) {
            error_log('Seismo refresh_scraper_sources: ' . $e->getMessage());
            $_SESSION['error'] = 'Refresh failed: ' . $e->getMessage();
            $finish();

            return;
        }

        RefreshAllService::applySessionFlashForAggregateResults($results, 'Scraper sources');
        $finish();
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

    public function analyzeGemini(): void
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
            echo json_encode(['ok' => false, 'error' => 'Satellite mode — configure scraper sources on the mothership.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $url = trim((string)($_POST['url'] ?? ''));
        $linkPattern = trim((string)($_POST['link_pattern'] ?? ''));

        if ($url === '') {
            echo json_encode(['ok' => false, 'error' => 'Page URL is required.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $pdo = getDbConnection();
            $configRepo = new SystemConfigRepository($pdo);
            $apiKey = trim((string)($configRepo->get(SettingsController::KEY_GEMINI_API_KEY) ?? ''));
            if ($apiKey === '') {
                echo json_encode(['ok' => false, 'error' => 'Google Gemini API key is not configured. Please add it under Settings → General.'], JSON_UNESCAPED_UNICODE);
                return;
            }

            $modelConfigured = trim((string)($configRepo->get('gemini:model') ?? ''));
            $model = $modelConfigured !== '' ? $modelConfigured : 'gemini-3.5-flash';

            // Step 1: Fetch listing page HTML
            $http = new BaseClient();
            $res = $http->getWebPage($url);
            if ($res->status < 200 || $res->status >= 400) {
                echo json_encode(['ok' => false, 'error' => 'Failed to fetch Page URL (HTTP ' . $res->status . ').'], JSON_UNESCAPED_UNICODE);
                return;
            }
            if ($res->body === '') {
                echo json_encode(['ok' => false, 'error' => 'Empty response from Page URL.'], JSON_UNESCAPED_UNICODE);
                return;
            }

            $listingHtml = $res->body;
            $targetUrl = $url;
            $targetHtml = $listingHtml;

            // Step 2: If link pattern is set, find the first article URL
            if ($linkPattern !== '') {
                $articleUrl = $this->resolveFirstArticleUrl($url, $listingHtml, $linkPattern);
                if ($articleUrl === null) {
                    echo json_encode(['ok' => false, 'error' => 'No same-host links found containing the pattern: ' . $linkPattern], JSON_UNESCAPED_UNICODE);
                    return;
                }
                $targetUrl = $articleUrl;
                // Fetch the article HTML
                $resArticle = $http->getWebPage($targetUrl);
                if ($resArticle->status < 200 || $resArticle->status >= 400) {
                    echo json_encode(['ok' => false, 'error' => 'Failed to fetch article URL: ' . $targetUrl . ' (HTTP ' . $resArticle->status . ').'], JSON_UNESCAPED_UNICODE);
                    return;
                }
                $targetHtml = $resArticle->body;
            }

            // Step 3: Clean and truncate target HTML
            $cleanedHtml = $this->cleanHtmlForPrompt($targetHtml);

            // Step 4: Construct Gemini payload
            $systemInstruction = "You are an expert DOM Engineering and Web Scraping Assistant.\n"
                . "Analyze the provided HTML of a web page and determine:\n"
                . "1. The CSS selector or XPath query that points to the publication date of the main article. Preference order: 1) time tag, 2) meta tags like property=\"article:published_time\", 3) elements with class names containing 'date', 'time', or 'pub'. Do NOT select dates of other/teaser articles on listing pages.\n"
                . "2. A list of CSS selectors (one per line) representing boilerplate/noise elements (e.g. headers, footers, navigation menus, ads, share buttons, sidebar widgets, related posts) that should be excluded before content/text extraction.\n\n"
                . "Return your response ONLY as a JSON object matching this schema:\n"
                . "{\n"
                . "  \"date_selector\": \"CSS_SELECTOR_OR_XPATH\",\n"
                . "  \"exclude_selectors\": [\"SELECTOR_1\", \"SELECTOR_2\", ...],\n"
                . "  \"explanation\": \"Brief 1-2 sentence explanation of your choices.\"\n"
                . "}\n\n"
                . "Return ONLY valid JSON. No markdown, no comments, no extra text.";

            $prompt = "Target URL: " . $targetUrl . "\n\nHTML Content:\n" . $cleanedHtml;

            $generationConfig = [
                'responseMimeType' => 'application/json',
                'temperature' => 0.1,
            ];
            // If thinking level is supported by model
            if (str_contains($model, 'gemini-3.5') || str_contains($model, 'gemini-1.5')) {
                $generationConfig['thinkingConfig'] = ['thinkingLevel' => 'MINIMAL'];
            }

            $payload = [
                'systemInstruction' => [
                    'parts' => [['text' => $systemInstruction]],
                ],
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [['text' => $prompt]],
                    ],
                ],
                'generationConfig' => $generationConfig,
            ];

            $geminiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
            $geminiRes = $http->postJson($geminiUrl, $payload, ['x-goog-api-key' => $apiKey]);

            if ($geminiRes->status !== 200) {
                echo json_encode(['ok' => false, 'error' => 'Gemini API call failed (HTTP ' . $geminiRes->status . ').'], JSON_UNESCAPED_UNICODE);
                return;
            }

            $geminiData = json_decode($geminiRes->body, true);
            $text = trim((string)($geminiData['candidates'][0]['content']['parts'][0]['text'] ?? ''));
            
            // Clean markdown code blocks if Gemini returned them
            if (str_starts_with($text, '```json')) {
                $text = substr($text, 7);
            } elseif (str_starts_with($text, '```')) {
                $text = substr($text, 3);
            }
            if (str_ends_with($text, '```')) {
                $text = substr($text, 0, -3);
            }
            $text = trim($text);

            $extracted = json_decode($text, true);
            if (!is_array($extracted)) {
                echo json_encode(['ok' => false, 'error' => 'Failed to parse Gemini JSON output: ' . $text], JSON_UNESCAPED_UNICODE);
                return;
            }

            $dateSelector = trim((string)($extracted['date_selector'] ?? ''));
            $excludeArr = (array)($extracted['exclude_selectors'] ?? []);
            $excludeSelectors = implode("\n", array_filter(array_map('trim', $excludeArr)));
            $explanation = trim((string)($extracted['explanation'] ?? ''));

            echo json_encode([
                'ok' => true,
                'date_selector' => $dateSelector,
                'exclude_selectors' => $excludeSelectors,
                'explanation' => $explanation,
                'resolved_url' => $targetUrl,
            ], JSON_UNESCAPED_UNICODE);

        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    private function resolveFirstArticleUrl(string $listingUrl, string $html, string $linkPattern): ?string
    {
        $dom = new \DOMDocument();
        $libxmlPrev = libxml_use_internal_errors(true);
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($libxmlPrev);

        $links = $dom->getElementsByTagName('a');
        $listingParts = parse_url($listingUrl);
        $listingHost  = strtolower((string)($listingParts['host'] ?? ''));

        for ($i = 0; $i < $links->length; $i++) {
            $el = $links->item($i);
            if (!($el instanceof \DOMElement)) {
                continue;
            }
            $href = $el->getAttribute('href');
            $absolute = $this->resolveUrlAgainstBase($listingUrl, $href);
            if ($absolute === '') {
                continue;
            }
            if (str_contains($absolute, $linkPattern)) {
                // Verify host matches
                $targetParts = parse_url($absolute);
                $targetHost = strtolower((string)($targetParts['host'] ?? ''));
                if ($listingHost === '' || $this->hostsMatch($listingHost, $targetHost)) {
                    return $absolute;
                }
            }
        }
        return null;
    }

    private function resolveUrlAgainstBase(string $base, string $ref): string
    {
        if (preg_match('#^https?://#i', $ref)) {
            return $ref;
        }
        if (str_starts_with($ref, '//')) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $ref;
        }
        $parts = parse_url($base);
        if (!$parts || empty($parts['host'])) {
            return '';
        }
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        
        if (str_starts_with($ref, '/')) {
            return $scheme . '://' . $host . $port . $ref;
        }
        $path = $parts['path'] ?? '/';
        $dir = str_contains($path, '/') ? substr($path, 0, strrpos($path, '/') + 1) : '/';
        return $scheme . '://' . $host . $port . $dir . $ref;
    }

    private function hostsMatch(string $h1, string $h2): bool
    {
        $h1 = str_starts_with($h1, 'www.') ? substr($h1, 4) : $h1;
        $h2 = str_starts_with($h2, 'www.') ? substr($h2, 4) : $h2;
        return strtolower($h1) === strtolower($h2);
    }

    private function cleanHtmlForPrompt(string $html): string
    {
        // Remove style, script, svg, iframe, noscript blocks
        $html = preg_replace('#<script[^>]*>.*?</script>#is', '', $html) ?? $html;
        $html = preg_replace('#<style[^>]*>.*?</style>#is', '', $html) ?? $html;
        $html = preg_replace('#<svg[^>]*>.*?</svg>#is', '', $html) ?? $html;
        $html = preg_replace('#<noscript[^>]*>.*?</noscript>#is', '', $html) ?? $html;
        $html = preg_replace('#<iframe[^>]*>.*?</iframe>#is', '', $html) ?? $html;
        
        // Remove style attributes
        $html = preg_replace('#\s+style="[^"]*"#i', '', $html) ?? $html;
        $html = preg_replace('#\s+style=\'[^\']*\'#i', '', $html) ?? $html;
        
        // Remove comments
        $html = preg_replace('#<!--.*?-->#s', '', $html) ?? $html;
        
        // Remove empty lines and collapse spaces
        $html = preg_replace('#\s+#', ' ', $html) ?? $html;
        
        return mb_substr(trim($html), 0, 50000);
    }
}
