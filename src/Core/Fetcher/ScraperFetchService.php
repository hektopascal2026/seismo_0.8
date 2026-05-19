<?php

declare(strict_types=1);

namespace Seismo\Core\Fetcher;

use DateTimeImmutable;
use DateTimeZone;
use Seismo\Service\Http\BaseClient;

/**
 * Unified scraper pipeline: listing + optional substring link mode, same-host, readability
 * extraction, optional `date_selector` and multiline `exclude_selectors` (strip DOM chrome
 * before text extraction), `guid` = article URL, `content_hash` = md5(content).
 * Preview and CoreRunner share {@see self::fetchScraperFeedItems()}.
 */
final class ScraperFetchService
{
    /** Max articles per feed for cron / CoreRunner. */
    public const PRODUCTION_MAX_ARTICLES = 20;

    /** Max successful articles for the Sources preview. */
    public const PREVIEW_MAX_ITEMS = 5;

    /** Upper bound on hrefs scanned in DOM order after the substring filter. */
    private const LINKS_SCAN_CAP = 50;

    /** Inclusive random delay (seconds) between article page fetches in production. */
    private const DELAY_BETWEEN_ARTICLES_MIN_SEC = 1;

    private const DELAY_BETWEEN_ARTICLES_MAX_SEC = 3;

    /** ~Chrome 131 on Windows — paired with {@see BaseClient::getWebPage()}. */
    public const BROWSER_UA
        = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    public function __construct(private BaseClient $http = new BaseClient(BaseClient::DEFAULT_TIMEOUT, self::BROWSER_UA))
    {
    }

    /**
     * Dry-run: same item pipeline as production, cap {@see self::PREVIEW_MAX_ITEMS},
     * no inter-article delay.
     *
     * @return array{ok: bool, error?: string, warnings: list<string>, items: list<array<string, mixed>>}
     */
    public function preview(
        string $pageUrl,
        string $linkPattern,
        int $maxItems = self::PREVIEW_MAX_ITEMS,
        string $dateSelector = '',
        string $excludeSelectors = ''
    ): array {
        $pageUrl = trim($pageUrl);
        if ($pageUrl === '' || !$this->isNavigableHttpUrl($pageUrl)) {
            return ['ok' => false, 'error' => 'A valid http(s) URL is required.', 'warnings' => [], 'items' => []];
        }
        $maxItems = max(1, min($maxItems, self::PREVIEW_MAX_ITEMS));
        $out = $this->fetchScraperFeedItems(
            $pageUrl,
            trim($linkPattern),
            trim($dateSelector),
            trim($excludeSelectors),
            $maxItems,
            false
        );
        if ($out['fatal_error'] !== null) {
            return ['ok' => false, 'error' => $out['fatal_error'], 'warnings' => $out['warnings'], 'items' => []];
        }
        if ($out['items'] === []) {
            if (trim($linkPattern) === '') {
                return [
                    'ok'       => false,
                    'error'    => 'No article extracted for this URL.',
                    'warnings' => $out['warnings'],
                    'items'    => [],
                ];
            }
            if ($out['warnings'] !== []) {
                return [
                    'ok'         => false,
                    'error'      => 'Every matched link failed to load. See warnings.',
                    'warnings'   => $out['warnings'],
                    'items'      => [],
                ];
            }
            return [
                'ok'         => false,
                'error'      => 'No same-host links on the page contain the link pattern (substring, like 0.4).',
                'warnings'   => $out['warnings'],
                'items'      => [],
            ];
        }

        return ['ok' => true, 'warnings' => $out['warnings'], 'items' => $out['items']];
    }

    /**
     * Production ingest: up to $maxArticles rows, 1–3 s random delay before each
     * article request after the first (link-following mode only).
     *
     * @return array{items: list<array<string, mixed>>, warnings: list<string>, fatal_error: ?string}
     */
    public function fetchScraperFeedItems(
        string $listingUrl,
        string $linkPattern,
        string $dateSelector,
        string $excludeSelectors,
        int $maxArticles,
        bool $delayBetweenArticleFetches
    ): array {
        $warnings  = [];
        $listingUrl     = trim($listingUrl);
        $linkPattern    = trim($linkPattern);
        $dateSel        = trim($dateSelector);
        $exSel          = trim($excludeSelectors);
        $dsOpt          = $dateSel === '' ? null : $dateSel;
        $maxArticles    = max(1, $maxArticles);

        if ($listingUrl === '' || !$this->isNavigableHttpUrl($listingUrl)) {
            return ['items' => [], 'warnings' => [], 'fatal_error' => 'Invalid listing URL.'];
        }

        if ($linkPattern === '') {
            try {
                $row = $this->buildArticleRow($listingUrl, $dsOpt, $exSel);

                return ['items' => [$row], 'warnings' => [], 'fatal_error' => null];
            } catch (\Throwable $e) {
                return ['items' => [], 'warnings' => [], 'fatal_error' => $e->getMessage()];
            }
        }

        try {
            $html = $this->fetchHtmlBody($listingUrl);
        } catch (\Throwable $e) {
            return ['items' => [], 'warnings' => [], 'fatal_error' => $e->getMessage()];
        }

        $candidates = $this->collectMatchingLinkUrls($listingUrl, $html, $linkPattern, self::LINKS_SCAN_CAP);
        if ($candidates === []) {
            return [
                'items'       => [],
                'warnings'    => [],
                'fatal_error' => null,
            ];
        }

        $items   = [];
        $attempt = 0;
        foreach ($candidates as $targetUrl) {
            if (count($items) >= $maxArticles) {
                break;
            }
            if ($delayBetweenArticleFetches && $attempt > 0) {
                sleep(random_int(self::DELAY_BETWEEN_ARTICLES_MIN_SEC, self::DELAY_BETWEEN_ARTICLES_MAX_SEC));
            }
            ++$attempt;
            try {
                $row = $this->buildArticleRow($targetUrl, $dsOpt, $exSel);
                $items[] = $row;
            } catch (\Throwable $e) {
                $warnings[] = 'Failed to fetch ' . $targetUrl . ': ' . $e->getMessage();
            }
        }

        return ['items' => $items, 'warnings' => $warnings, 'fatal_error' => null];
    }

    /**
     * @return array<string, mixed> one feed_items-shaped row: guid=URL, content_hash=md5(content)
     */
    private function buildArticleRow(string $pageUrl, ?string $dateSelector, string $excludeSelectors = ''): array
    {
        $html = $this->fetchHtmlBody($pageUrl);
        $read = ScraperContentExtractor::extractReadableContent($html, $excludeSelectors);
        $content = trim($read['content'] ?? '');
        if ($content === '') {
            throw new \RuntimeException('No readable text extracted for ' . $pageUrl);
        }
        if (mb_strlen($content) > 50000) {
            $content = mb_substr($content, 0, 50000);
        }
        $title = trim($read['title'] ?? '');
        if ($title === '') {
            $title = $pageUrl;
        }
        $published = null;
        if ($dateSelector !== null && $dateSelector !== '') {
            $published = ScraperContentExtractor::extractPublishedDate($html, $dateSelector, $excludeSelectors);
        }
        if ($published === null) {
            $published = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        }
        $guid = mb_substr($pageUrl, 0, 500);

        return [
            'guid'             => $guid,
            'title'            => mb_substr($title, 0, 500),
            'link'             => mb_substr($pageUrl, 0, 500),
            'description'      => mb_substr($content, 0, 2000),
            'content'          => $content,
            'author'           => '',
            'published_date'   => $published,
            'content_hash'     => md5($content),
        ];
    }

    private function isNavigableHttpUrl(string $url): bool
    {
        $u = trim($url);
        if ($u === '' || $u === '#') {
            return false;
        }

        return (bool)preg_match('#^https?://#i', $u);
    }

    private function fetchHtmlBody(string $pageUrl): string
    {
        $res = $this->http->getWebPage($pageUrl);
        if ($res->status < 200 || $res->status >= 400) {
            throw new \RuntimeException('HTTP ' . $res->status . ' fetching ' . $pageUrl);
        }
        if ($res->body === '') {
            throw new \RuntimeException('Empty body for ' . $pageUrl);
        }

        return $res->body;
    }

    /**
     * @return list<string>
     */
    private function collectMatchingLinkUrls(string $listingUrl, string $html, string $linkPattern, int $maxScan): array
    {
        $maxScan = max(1, min($maxScan, 200));
        $libxmlPrev = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($libxmlPrev);
        if (!$loaded) {
            return [];
        }

        $listingParts = parse_url($listingUrl);
        $listingHost  = strtolower((string)($listingParts['host'] ?? ''));

        $links = $dom->getElementsByTagName('a');
        $normListing = $this->normalizeUrlForCompare($this->stripFragment($listingUrl));
        $seen = [];
        $out = [];
        for ($i = 0; $i < $links->length; $i++) {
            if (count($out) >= $maxScan) {
                break;
            }
            $el = $links->item($i);
            if (!($el instanceof \DOMElement)) {
                continue;
            }
            $rawHref = $el->getAttribute('href');
            $absolute = $this->resolveAgainstBase($listingUrl, $rawHref);
            if ($absolute === '' || !$this->isNavigableHttpUrl($absolute)) {
                continue;
            }
            $targetParts = parse_url($absolute);
            $targetHost  = strtolower((string)($targetParts['host'] ?? ''));
            if ($listingHost === '' || $targetHost !== $listingHost) {
                continue;
            }
            $canon = $this->stripFragment($absolute);
            if ($this->normalizeUrlForCompare($canon) === $normListing) {
                continue;
            }
            if (strpos($absolute, $linkPattern) === false) {
                continue;
            }
            if (!$this->hasArticleSlugBeyondListing($listingUrl, $canon)) {
                continue;
            }
            $dedupeKey = $this->normalizeUrlForCompare($canon);
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;
            $out[] = $absolute;
        }

        return $out;
    }

    private function stripFragment(string $url): string
    {
        $p = strpos($url, '#');
        if ($p === false) {
            return $url;
        }

        return substr($url, 0, $p);
    }

    private function normalizeUrlForCompare(string $url): string
    {
        $u = rtrim($url, '/');

        return strtolower($u);
    }

    /**
     * Listing pages match the link pattern too (e.g. `/worte/magazin/`). Require at
     * least one path segment after the listing directory so we ingest articles, not
     * the index (which yields title "SPRIND | Magazin" and year-filter chrome).
     */
    private function hasArticleSlugBeyondListing(string $listingUrl, string $candidateUrl): bool
    {
        $basePath = parse_url($listingUrl, PHP_URL_PATH);
        $candPath = parse_url($candidateUrl, PHP_URL_PATH);
        if (!is_string($basePath) || !is_string($candPath)) {
            return true;
        }
        $basePath = rtrim($basePath, '/') ?: '/';
        $candPath = rtrim($candPath, '/') ?: '/';
        if ($candPath === $basePath) {
            return false;
        }
        $prefix = $basePath . '/';
        if (!str_starts_with($candPath, $prefix)) {
            return false;
        }
        $suffix = substr($candPath, strlen($prefix));

        return $suffix !== '' && !str_contains($suffix, '/');
    }

    private function resolveAgainstBase(string $base, string $ref): string
    {
        $ref = str_replace(["\0", "\r", "\n"], '', trim($ref));
        if ($ref === '' || str_starts_with($ref, '#') || str_starts_with(strtolower($ref), 'javascript:')
            || str_starts_with(strtolower($ref), 'mailto:') || str_starts_with(strtolower($ref), 'tel:')) {
            return '';
        }
        if (preg_match('#^https?://#i', $ref)) {
            return $ref;
        }
        $b = parse_url($base);
        if ($b === false || ($b['host'] ?? '') === '') {
            return '';
        }
        $scheme = $b['scheme'] ?? 'https';
        $user = $b['user'] ?? '';
        $pass = $b['pass'] ?? '';
        $auth = $user !== '' ? $user . ($pass !== '' ? ':' . $pass : '') . '@' : '';
        $host = $b['host'];
        $port = isset($b['port']) ? ':' . $b['port'] : '';
        if (str_starts_with($ref, '//')) {
            return $scheme . ':' . $ref;
        }
        $path = (string)($b['path'] ?? '');
        if ($path === '') {
            $path = '/';
        }
        if (str_starts_with($ref, '/')) {
            $newPath = $ref;
        } elseif (str_starts_with($ref, '?')) {
            return $scheme . '://' . $auth . $host . $port . $path . $ref;
        } else {
            $dir = str_contains($path, '/') ? substr($path, 0, (int)strrpos($path, '/') + 1) : '/';
            $newPath = $dir . $ref;
        }
        $newPath = $this->collapsePathSegments($newPath);
        if (!str_starts_with($newPath, '/')) {
            $newPath = '/' . $newPath;
        }

        return $scheme . '://' . $auth . $host . $port . $newPath;
    }

    private function collapsePathSegments(string $path): string
    {
        $isAbs = str_starts_with($path, '/');
        $raw = $isAbs ? substr($path, 1) : $path;
        $parts = $raw === '' ? [] : explode('/', $raw);
        $stack = [];
        foreach ($parts as $p) {
            if ($p === '' || $p === '.') {
                continue;
            }
            if ($p === '..') {
                if ($stack !== []) {
                    array_pop($stack);
                }
                continue;
            }
            $stack[] = $p;
        }
        $out = ($isAbs ? '/' : '') . implode('/', $stack);

        return $out === '' && $isAbs ? '/' : $out;
    }
}
