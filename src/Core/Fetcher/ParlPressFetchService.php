<?php

declare(strict_types=1);

namespace Seismo\Core\Fetcher;

use DateTimeImmutable;
use DateTimeZone;
use Seismo\Service\Http\BaseClient;

/**
 * Swiss Parliament press releases (parlament.ch SharePoint REST).
 *
 * Fetches via **GET** the configured list `…/items` OData endpoint. **SDA** uses the news
 * site list (`/de/services/news/…/Seiten`); **Medienmitteilungen** use `press-releases/…/Pages`.
 * Rows are filtered by `guid_prefix` (`parl_mm` vs `parl_sda`) using list URL / `FileRef` path and slug shape.
 *
 * Configuration comes from the parent `feeds` row:
 * - `url` — SharePoint **list items** URL (`…/items`).
 * - `description` — optional JSON:
 *   - `lookback_days`, `limit`, `language` — same as always.
 *   - `guid_prefix` — `parl_mm` (default) vs `parl_sda`.
 *     When `odata_title_substring` is set and `guid_prefix` is omitted, defaults to **`parl_sda`** (legacy hint).
 *   - `odata_title_substring` — optional OData `$filter` substring on `Title` (SDA list).
 */
final class ParlPressFetchService
{
    private const DEFAULT_LOOKBACK = 90;

    private const DEFAULT_LIMIT = 50;

    private readonly BaseClient $http;

    /** @var list<string> */
    private const LANGUAGES = ['de', 'fr', 'it', 'en', 'rm'];

    /** SDA-Meldungen (news site `Seiten` list) — not `press-releases/Pages`. */
    public const DEFAULT_SDA_LIST_ITEMS_URL = 'https://www.parlament.ch/de/services/news/_api/web/lists/getByTitle(\'Seiten\')/items';

    /** Base list columns; {@see listODataSelect()} adds per-language Title_* / Content_*. */
    private const LIST_ODATA_SELECT_BASE = 'Title,FileRef,EncodedAbsUrl,FileLeafRef,Created,ArticleStartDate,PublishingPageContent,Lead';

    public function __construct(?BaseClient $http = null)
    {
        $this->http = $http ?? new BaseClient(BaseClient::DEFAULT_TIMEOUT, self::defaultBrowserUserAgent());
    }

    /**
     * @param array<string, mixed> $feedRow Full `feeds` row (must have `source_type` parl_press).
     *
     * @return list<array<string, mixed>>
     */
    public function fetchForFeed(array $feedRow): array
    {
        if (($feedRow['source_type'] ?? '') !== 'parl_press') {
            return [];
        }

        $apiBase = trim((string)($feedRow['url'] ?? ''));
        if ($apiBase === '') {
            throw new \RuntimeException('Parl press feed has an empty API URL.');
        }

        $opts = $this->parseOptions((string)($feedRow['description'] ?? ''));
        $lookback = max(1, min(365, (int)($opts['lookback_days'] ?? self::DEFAULT_LOOKBACK)));
        $limit    = max(1, min(200, (int)($opts['limit'] ?? self::DEFAULT_LIMIT)));
        $lang     = $this->normaliseLanguage((string)($opts['language'] ?? 'de'));
        $titleNeedle = trim((string)($opts['odata_title_substring'] ?? ''));
        $guidPrefixRaw = array_key_exists('guid_prefix', $opts) ? $opts['guid_prefix'] : null;
        if ($guidPrefixRaw === null && $titleNeedle !== '') {
            $guidPrefix = 'parl_sda';
        } else {
            $guidPrefix = $this->normaliseGuidPrefix((string)($guidPrefixRaw ?? 'parl_mm'));
        }

        $listItems = $this->fetchListODataItems($apiBase, $lookback, $limit, $titleNeedle, $lang);

        return $this->buildRowsFromListItems($listItems, $lang, $guidPrefix, $limit);
    }

    /**
     * @param list<array<string, mixed>> $listItems SharePoint list `d.results` rows.
     *
     * @return list<array<string, mixed>>
     */
    private function buildRowsFromListItems(array $listItems, string $lang, string $guidPrefix, int $limit): array
    {
        $out = [];
        foreach ($listItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (!$this->itemMatchesGuidPrefix($item, $guidPrefix)) {
                continue;
            }
            $row = $this->tryBuildParlPressRow($item, $lang, $guidPrefix);
            if ($row !== null) {
                $out[] = $row;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * SharePoint list OData (`feeds.url` …/items).
     *
     * @return list<array<string, mixed>>
     */
    private function fetchListODataItems(
        string $listItemsUrl,
        int $lookbackDays,
        int $limit,
        string $titleNeedle,
        string $lang,
    ): array {
        $since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . $lookbackDays . ' days')
            ->format('Y-m-d\TH:i:s\Z');
        $fetchTop = min(max($limit * 4, $limit), 200);
        $filter = "Created ge datetime'" . $since . "'";
        if ($titleNeedle !== '') {
            $escaped = str_replace("'", "''", $titleNeedle);
            $filter .= " and substringof('" . $escaped . "', Title)";
        }
        $query = http_build_query([
            '$top'     => (string)$fetchTop,
            '$orderby' => 'Created desc',
            '$filter'  => $filter,
            '$select'  => $this->listODataSelect($lang),
        ]);
        $url = $this->listItemsBaseUrl($listItemsUrl) . '?' . $query;

        $response = $this->http->get($url, $this->sharePointJsonHeaders());
        if ($response->status < 200 || $response->status >= 300) {
            if ($this->responseLooksLikeEdgeWaf($response)) {
                throw $this->edgeWafException('list OData GET', $response->status);
            }
            throw new \RuntimeException(
                'parlament.ch list OData GET HTTP ' . $response->status . ': ' . mb_substr($response->body, 0, 300)
            );
        }
        if ($this->responseLooksLikeEdgeWaf($response)) {
            throw $this->edgeWafException('list OData GET', $response->status);
        }

        $data = json_decode($response->body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('parlament.ch list OData returned invalid JSON.');
        }
        if (isset($data['error'])) {
            $msg = $data['error']['message']['value'] ?? $data['error']['message'] ?? json_encode($data['error']);
            throw new \RuntimeException('parlament.ch list OData error: ' . (is_string($msg) ? $msg : json_encode($msg)));
        }

        $results = $data['d']['results'] ?? null;

        return is_array($results) ? $results : [];
    }

    private function listItemsBaseUrl(string $url): string
    {
        $u = trim($url);
        if (($q = strpos($u, '?')) !== false) {
            $u = substr($u, 0, $q);
        }

        return rtrim($u, '/');
    }

    /**
     * SharePoint `Title` is the URL slug; headlines live in `Title_de` etc.
     */
    private function listODataSelect(string $lang): string
    {
        $fields = explode(',', self::LIST_ODATA_SELECT_BASE);
        foreach (array_merge([$lang], self::LANGUAGES) as $l) {
            $fields[] = 'Title_' . $l;
            $fields[] = 'Content_' . $l;
        }

        return implode(',', array_values(array_unique($fields)));
    }

    /**
     * @param array<string, mixed> $item SharePoint list row.
     */
    private function itemMatchesGuidPrefix(array $item, string $guidPrefix): bool
    {
        $ref = strtolower(trim((string)($item['FileRef'] ?? '')));
        if ($ref !== '' && str_contains($ref, '/services/news/')) {
            return $guidPrefix === 'parl_sda';
        }
        if ($ref !== '' && str_contains($ref, '/press-releases/')) {
            $slug = $this->resolveParlPressSlug($item);
            if ($slug === '') {
                return false;
            }
            $slugSda = self::slugLooksLikeSda($slug);

            return $guidPrefix === 'parl_sda' ? $slugSda : !$slugSda;
        }
        $slug = $this->resolveParlPressSlug($item);
        if ($slug === '') {
            return false;
        }
        $slugSda = self::slugLooksLikeSda($slug) || self::slugLooksLikeNewsSiteSda($slug, $ref);

        return $guidPrefix === 'parl_sda' ? $slugSda : !$slugSda;
    }

    public static function isParlNewsListUrl(string $url): bool
    {
        return str_contains(strtolower($url), '/services/news/');
    }

    private static function slugLooksLikeSda(string $slug): bool
    {
        $s = strtolower(trim($slug));

        return str_starts_with($s, 'sda-')
            || str_starts_with($s, 'mm-sda')
            || str_contains($s, '-sda-');
    }

    /** SDA wire items on the news site use timestamp ids ending in `_bsd…`, not `sda-` slugs. */
    private static function slugLooksLikeNewsSiteSda(string $slug, string $fileRefLower): bool
    {
        if ($fileRefLower !== '' && !str_contains($fileRefLower, '/services/news/')) {
            return false;
        }
        $s = strtolower(trim($slug));

        return (bool) preg_match('/_bsd\d+$/', $s);
    }

    /**
     * @return array<string, string>
     */
    private function sharePointJsonHeaders(): array
    {
        return [
            'Content-Type'    => 'application/json;odata=verbose',
            'Accept'          => 'application/json;odata=verbose',
            'Accept-Language' => 'de-CH,de;q=0.9,en;q=0.8',
            'Origin'          => 'https://www.parlament.ch',
            'Referer'         => 'https://www.parlament.ch/de/medien/medienmitteilungen',
        ];
    }

    private static function defaultBrowserUserAgent(): string
    {
        $seismo = function_exists('seismoHttpUserAgent')
            ? seismoHttpUserAgent()
            : 'Seismo/' . (defined('SEISMO_VERSION') ? SEISMO_VERSION : 'dev') . ' (+https://hektopascal.org)';

        return 'Mozilla/5.0 (compatible; ' . $seismo
            . ') AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
    }

    private function responseLooksLikeEdgeWaf(\Seismo\Service\Http\Response $response): bool
    {
        $body = $response->body;
        if ($body === '') {
            return false;
        }

        return str_contains($body, 'Request Rejected')
            || str_contains($body, 'Access Denied');
    }

    private function edgeWafException(string $via, int $status): \RuntimeException
    {
        return new \RuntimeException(
            'parlament.ch ' . $via . ' blocked by edge WAF (HTTP ' . $status
            . ' HTML “Request Rejected” / “Access Denied”).'
        );
    }

    /**
     * @param array<string, mixed> $item SharePoint list row.
     *
     * @return ?array<string, mixed>
     */
    private function tryBuildParlPressRow(array $item, string $lang, string $guidPrefix): ?array
    {
        $slug = $this->resolveParlPressSlug($item);
        if ($slug === '') {
            return null;
        }

        $title = $this->resolveParlPressTitle($item, $lang, $slug);

        $pageUrl = $this->resolveParlPressPageUrl($item, $slug);
        if ($pageUrl === null) {
            return null;
        }

        $rawDate = $item['ArticleStartDate'] ?? $item['Created'] ?? null;
        $pub     = null;
        if (is_string($rawDate) && $rawDate !== '') {
            $ts = strtotime($rawDate);
            if ($ts !== false) {
                $pub = (new DateTimeImmutable('@' . $ts, new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            }
        }

        $contentType = $item['ContentType']['Name'] ?? 'Press Release';
        $contentType = is_string($contentType) ? $contentType : 'Press Release';

        $contentField = 'Content_' . $lang;
        $rawContent = (string)($item[$contentField] ?? '');
        if ($rawContent === '') {
            $lead = trim(strip_tags((string)($item['Lead'] ?? '')));
            $pageContent = trim(strip_tags((string)($item['PublishingPageContent'] ?? '')));
            if ($lead !== '' && $pageContent !== '') {
                $plain = $lead . "\n\n" . $pageContent;
            } else {
                $plain = $lead !== '' ? $lead : $pageContent;
            }
        } else {
            $plain = trim(strip_tags($rawContent));
        }

        $guid = $guidPrefix . ':' . $slug;
        $guid = mb_substr($guid, 0, 500);

        $commission = self::commissionFromSlug($slug);

        if (self::isMeaninglessParlPressTitle($title)) {
            return null;
        }

        return [
            'guid'             => $guid,
            'title'            => mb_substr($title, 0, 500),
            'link'             => mb_substr($pageUrl, 0, 500),
            'description'      => $plain,
            'content'          => $plain !== '' ? $plain : $title,
            'author'           => $commission !== '' ? $commission : (string)$contentType,
            'published_date'   => $pub,
            'content_hash'     => '',
        ];
    }

    private function normaliseGuidPrefix(string $raw): string
    {
        $raw = strtolower(trim($raw));
        // Keep in sync with {@see \Seismo\Repository\FeedItemRepository::deleteAlienParlPressFeedItems()}.
        return in_array($raw, ['parl_mm', 'parl_sda'], true) ? $raw : 'parl_mm';
    }

    /**
     * @return array<string, mixed>
     */
    private function parseOptions(string $description): array
    {
        $description = trim($description);
        if ($description === '') {
            return [];
        }
        $decoded = json_decode($description, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normaliseLanguage(string $raw): string
    {
        $l = strtolower(trim($raw));

        return in_array($l, self::LANGUAGES, true) ? $l : 'de';
    }

    /**
     * Internal URL slug: list column {@see Title}, or basename of {@see FileRef} when Title is empty / "Untitled".
     *
     * @param array<string, mixed> $item SharePoint list row (verbose JSON).
     */
    private function resolveParlPressSlug(array $item): string
    {
        $ref = trim((string)($item['FileRef'] ?? ''));
        if ($ref !== '' && self::isParlNewsListUrl($ref)) {
            $base = basename($ref);
            if ($base !== '' && $base !== '.' && $base !== '..') {
                $slug = preg_replace('/\.aspx$/i', '', $base) ?? $base;
                $slug = trim((string)$slug);
                if ($slug !== '') {
                    return $slug;
                }
            }
        }
        $fromTitle = trim((string)($item['Title'] ?? ''));
        if ($fromTitle !== '' && !self::isMeaninglessParlPressTitle($fromTitle) && self::looksLikeParlPressSlug($fromTitle)) {
            return $fromTitle;
        }
        if ($ref === '') {
            return $fromTitle;
        }
        $base = basename($ref);
        if ($base === '' || $base === '.' || $base === '..') {
            return $fromTitle;
        }
        $slug = preg_replace('/\.aspx$/i', '', $base) ?? $base;
        $slug = trim((string)$slug);

        return $slug !== '' ? $slug : $fromTitle;
    }

    /**
     * @param array<string, mixed> $item SharePoint list row (verbose JSON).
     */
    private function resolveParlPressTitle(array $item, string $preferredLang, string $slug): string
    {
        $ref = trim((string)($item['FileRef'] ?? ''));
        $headline = trim((string)($item['Title'] ?? ''));
        if ($ref !== '' && self::isParlNewsListUrl($ref)
            && $headline !== '' && !self::isMeaninglessParlPressTitle($headline)
            && !self::looksLikeParlPressSlug($headline)) {
            return $headline;
        }

        $try = [];
        foreach (array_merge([$preferredLang], self::LANGUAGES) as $l) {
            $k = 'Title_' . $l;
            if (!in_array($k, $try, true)) {
                $try[] = $k;
            }
        }
        foreach ($try as $field) {
            $t = trim((string)($item[$field] ?? ''));
            if ($t === '' || self::isMeaninglessParlPressTitle($t)) {
                continue;
            }

            return $t;
        }
        $excerpt = $this->plainContentExcerpt($item, $preferredLang, 220);
        if ($excerpt !== '') {
            return $excerpt;
        }

        $slugTrim = trim($slug);

        return $slugTrim !== '' && !self::isMeaninglessParlPressTitle($slugTrim) && !self::looksLikeParlPressSlug($slugTrim)
            ? $slugTrim
            : $slug;
    }

    private static function isMeaninglessParlPressTitle(string $t): bool
    {
        $n = mb_strtolower(trim($t));

        return $n === 'untitled' || $n === '(untitled)' || $n === '(no title)';
    }

    /** SharePoint `Title` column holds the page slug (`mm-wak-n-2026-05-19`), not the headline. */
    private static function looksLikeParlPressSlug(string $t): bool
    {
        $t = strtolower(trim($t));

        return preg_match('/^(mm|sda|info)-[a-z0-9][a-z0-9-]*$/', $t) === 1;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function plainContentExcerpt(array $item, string $preferredLang, int $maxLen): string
    {
        foreach (array_merge([$preferredLang], self::LANGUAGES) as $l) {
            $raw = (string)($item['Content_' . $l] ?? '');
            $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($raw)) ?? '');
            if ($plain === '') {
                continue;
            }
            if (mb_strlen($plain) > $maxLen) {
                $plain = mb_substr($plain, 0, $maxLen - 1) . '…';
            }

            return $plain;
        }

        return '';
    }

    /**
     * Commission abbreviation from press slug (ported from 0.4
     * {@see parseParlMmCommission} in lex_jus.php).
     */
    public static function commissionFromSlug(string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }
        if (preg_match('/^mm-([a-z]+)-([nsr])-\d{4}/i', $slug, $m)) {
            return strtoupper($m[1]) . '-' . strtoupper($m[2]);
        }
        if (preg_match('/^mm-([a-z]+)-\d{4}/i', $slug, $m)) {
            return strtoupper($m[1]);
        }
        if (preg_match('/^sda-([a-z]+)-([nsr])-\d{4}/i', $slug, $m)) {
            return strtoupper($m[1]) . '-' . strtoupper($m[2]);
        }
        if (preg_match('/^sda-([a-z]+)-\d{4}/i', $slug, $m)) {
            return strtoupper($m[1]);
        }
        if (str_contains($slug, '-sda-') || str_starts_with(strtolower($slug), 'mm-sda')) {
            return 'SDA';
        }
        if (str_starts_with($slug, 'info-')) {
            return 'Info';
        }

        return 'Medienmitteilung';
    }

    private function isNavigableHttpUrl(string $url): bool
    {
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Public page URL from list row ({@see FileRef}, {@see EncodedAbsUrl}, or Pages path + slug).
     *
     * @param array<string, mixed> $item
     */
    private function resolveParlPressPageUrl(array $item, string $slug): ?string
    {
        $encoded = trim((string)($item['EncodedAbsUrl'] ?? ''));
        if ($encoded !== '' && $this->isNavigableHttpUrl($encoded)) {
            return $encoded;
        }

        $fileRef = trim((string)($item['FileRef'] ?? ''));
        if ($fileRef !== '') {
            $pageUrl = str_starts_with($fileRef, 'http') ? $fileRef : 'https://www.parlament.ch' . $fileRef;
            if ($this->isNavigableHttpUrl($pageUrl)) {
                return $pageUrl;
            }
        }

        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }
        $leaf = trim((string)($item['FileLeafRef'] ?? ''));
        if (!str_ends_with(strtolower($slug), '.aspx') && $leaf !== '') {
            $slug = preg_replace('/\.aspx$/i', '', $leaf) ?: $slug;
        }
        $path = '/press-releases/Pages/' . $slug . (str_contains($slug, '.') ? '' : '.aspx');
        $pageUrl = 'https://www.parlament.ch' . $path;

        return $this->isNavigableHttpUrl($pageUrl) ? $pageUrl : null;
    }
}
