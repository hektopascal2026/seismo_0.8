<?php

declare(strict_types=1);

namespace Seismo\Core\Fetcher;

use DateTimeImmutable;
use DateTimeZone;
use Seismo\Service\Http\BaseClient;

/**
 * Swiss Parliament press releases (parlament.ch SharePoint REST).
 *
 * Primary path: **POST** `/_api/search/postquery` with taxonomy **RefinementFilters**.
 * When Akamai returns an HTML “Request Rejected” / “Access Denied” page (common for
 * datacenter IPs or `Seismo/*` User-Agents), falls back to **GET** the configured list
 * `…/items` OData endpoint and filters rows client-side by slug (`parl_mm` vs `parl_sda`).
 *
 * Configuration comes from the parent `feeds` row:
 * - `url` — SharePoint **list items** URL (`…/items`) for OData fallback; also used to derive `postquery`.
 * - `description` — optional JSON:
 *   - `lookback_days`, `limit`, `language` — same as always.
 *   - `guid_prefix` — `parl_mm` (default) vs `parl_sda`; selects the built-in **PdNewsTypeDE** refinement token.
 *     When `odata_title_substring` is set and `guid_prefix` is omitted, defaults to **`parl_sda`** (legacy hint).
 *   - `refinement_filters` — optional **array of FQL strings**; when set, replaces the built-in PdNewsTypeDE filter(s).
 *   - `search_post_url` — optional full URL to `postquery` if derivation from `url` fails.
 */
final class ParlPressFetchService
{
    private const DEFAULT_LOOKBACK = 90;

    private const DEFAULT_LIMIT = 50;

    private readonly BaseClient $http;

    /** @var list<string> */
    private const LANGUAGES = ['de', 'fr', 'it', 'en', 'rm'];

    /** UTF-8 encoding of U+01C2 twice — SharePoint taxonomy refinement prefix before ASCII-hex label. */
    private const TAX_MARKER = "\xC7\x82\xC7\x82";

    /** Hex of ASCII `Medienmitteilung` — official press releases. */
    private const HEX_NEWS_TYPE_MM = '4d656469656e6d69747465696c756e67';

    /** Hex of ASCII `SDA-Meldung` — agency wire. */
    private const HEX_NEWS_TYPE_SDA = '5344412d4d656c64756e67';

    /** Base list columns; {@see listODataSelect()} adds per-language Title_* / Content_*. */
    private const LIST_ODATA_SELECT_BASE = 'Title,FileRef,EncodedAbsUrl,FileLeafRef,Created,ArticleStartDate';

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

        $searchPostUrl = trim((string)($opts['search_post_url'] ?? ''));
        if ($searchPostUrl === '') {
            $searchPostUrl = $this->deriveSearchPostUrlFromListItemsUrl($apiBase);
        }

        $refinements = null;
        if (isset($opts['refinement_filters']) && is_array($opts['refinement_filters'])) {
            $refinements = array_values(array_filter(array_map(static fn ($v) => trim((string)$v), $opts['refinement_filters'])));
        }
        if ($refinements === null || $refinements === []) {
            $refinements = $this->defaultRefinementFilters($guidPrefix);
        }

        $sinceUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . $lookback . ' days')
            ->format('Y-m-d');
        // KQL: scope to press-releases tree + freshness (SharePoint managed property LastModifiedTime).
        $querytext = 'Path:https://www.parlament.ch/press-releases/* LastModifiedTime>=' . $sinceUtc;

        try {
            $cellRows = $this->executeSharePointSearch($searchPostUrl, $querytext, $limit, $refinements);

            return $this->buildRowsFromSearchCells($cellRows, $lang, $guidPrefix, $limit);
        } catch (\RuntimeException $e) {
            if (!$this->exceptionIndicatesEdgeWaf($e)) {
                throw $e;
            }
            error_log(
                'Seismo parl_press: SharePoint search POST blocked by edge WAF (' . $e->getMessage()
                . '); falling back to list OData GET.'
            );

            $listItems = $this->fetchListODataItems($apiBase, $lookback, $limit, $titleNeedle, $lang);

            return $this->buildRowsFromListItems($listItems, $lang, $guidPrefix, $limit);
        }
    }

    /**
     * @param list<array<string, mixed>> $cellRows
     *
     * @return list<array<string, mixed>>
     */
    private function buildRowsFromSearchCells(array $cellRows, string $lang, string $guidPrefix, int $limit): array
    {
        $seenPath = [];
        $out = [];
        foreach ($cellRows as $cells) {
            $item = $this->syntheticListItemFromSearchCells($cells, $lang);
            $pathKey = trim((string)($item['FileRef'] ?? ''));
            if ($pathKey === '' || isset($seenPath[$pathKey])) {
                continue;
            }
            $seenPath[$pathKey] = true;
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
            $slug = $this->resolveParlPressSlug($item);
            if (!$this->slugMatchesGuidPrefix($slug, $guidPrefix)) {
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
     * @param list<string> $refinementFilterStrings FQL refinement strings (OData verbose `results` array).
     *
     * @return list<array<string, mixed>> One associative map per hit (managed property name → value).
     */
    private function executeSharePointSearch(
        string $postUrl,
        string $querytext,
        int $limit,
        array $refinementFilterStrings,
    ): array {
        $payload = [
            'request' => [
                'Querytext'        => $querytext,
                'RowLimit'         => $limit,
                'TrimDuplicates'   => false,
                'RefinementFilters' => ['results' => array_values($refinementFilterStrings)],
                'SelectProperties' => [
                    'results' => [
                        'Title',
                        'Path',
                        'Created',
                        'LastModifiedTime',
                        'Description',
                        'HitHighlightedSummary',
                        'Author',
                    ],
                ],
            ],
        ];

        $response = $this->http->postJson($postUrl, $payload, $this->sharePointJsonHeaders());

        if ($response->status < 200 || $response->status >= 300) {
            if ($this->responseLooksLikeEdgeWaf($response)) {
                throw $this->edgeWafException('search POST', $response->status);
            }
            throw new \RuntimeException(
                'parlament.ch search POST HTTP ' . $response->status . ': ' . mb_substr($response->body, 0, 300)
            );
        }
        if ($this->responseLooksLikeEdgeWaf($response)) {
            throw $this->edgeWafException('search POST', $response->status);
        }

        $data = json_decode($response->body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('parlament.ch search returned invalid JSON.');
        }
        if (isset($data['error'])) {
            $msg = $data['error']['message']['value'] ?? $data['error']['message'] ?? json_encode($data['error']);
            throw new \RuntimeException('parlament.ch search error: ' . (is_string($msg) ? $msg : json_encode($msg)));
        }

        $rows = $this->extractSearchTableRows($data);
        $out = [];
        foreach ($rows as $row) {
            $cells = $this->searchRowCellsToMap($row);
            if ($cells !== []) {
                $out[] = $cells;
            }
        }

        return $out;
    }

    /**
     * Legacy SharePoint list OData (`feeds.url` …/items) when search POST is WAF-blocked.
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

    private function slugMatchesGuidPrefix(string $slug, string $guidPrefix): bool
    {
        $slug = trim($slug);
        if ($slug === '') {
            return false;
        }
        $isSda = self::slugLooksLikeSda($slug);

        return $guidPrefix === 'parl_sda' ? $isSda : !$isSda;
    }

    private static function slugLooksLikeSda(string $slug): bool
    {
        $s = strtolower(trim($slug));

        return str_starts_with($s, 'sda-')
            || str_starts_with($s, 'mm-sda')
            || str_contains($s, '-sda-');
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
        $version = defined('SEISMO_VERSION') ? SEISMO_VERSION : 'dev';
        $contact = defined('SEISMO_MOTHERSHIP_URL') && SEISMO_MOTHERSHIP_URL !== ''
            ? ' (+' . SEISMO_MOTHERSHIP_URL . ')'
            : '';

        return 'Mozilla/5.0 (compatible; Seismo/' . $version . $contact
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

    private function exceptionIndicatesEdgeWaf(\RuntimeException $e): bool
    {
        return str_contains($e->getMessage(), 'edge WAF')
            || str_contains($e->getMessage(), 'Request Rejected')
            || str_contains($e->getMessage(), 'Access Denied');
    }

    private function edgeWafException(string $via, int $status): \RuntimeException
    {
        return new \RuntimeException(
            'parlament.ch ' . $via . ' blocked by edge WAF (HTTP ' . $status
            . ' HTML “Request Rejected” / “Access Denied”) — automatic list OData fallback will be attempted.'
        );
    }

    /**
     * @return list<string>
     */
    private function defaultRefinementFilters(string $guidPrefix): array
    {
        $hex = $guidPrefix === 'parl_sda' ? self::HEX_NEWS_TYPE_SDA : self::HEX_NEWS_TYPE_MM;

        return [self::refinementPdNewsTypeDe($hex)];
    }

    private static function refinementPdNewsTypeDe(string $hexLabel): string
    {
        return 'PdNewsTypeDE:"' . self::TAX_MARKER . $hexLabel . '"';
    }

    private function deriveSearchPostUrlFromListItemsUrl(string $listItemsUrl): string
    {
        $u = trim($listItemsUrl);
        if (($q = strpos($u, '?')) !== false) {
            $u = substr($u, 0, $q);
        }
        $u = rtrim($u, '/');
        if (preg_match('#^(https?://[^/]+/press-releases)(?:/.*)?$#i', $u, $m)) {
            return $m[1] . '/_api/search/postquery';
        }
        if (preg_match('#^(https?://[^/]+)(/.+)/_api/web/#i', $u, $m)) {
            return $m[1] . $m[2] . '/_api/search/postquery';
        }

        throw new \RuntimeException(
            'Cannot derive SharePoint `/_api/search/postquery` URL from feeds.url — use the standard …/press-releases/…/items list URL, or set `search_post_url` in description JSON.'
        );
    }

    /**
     * @param array<string, mixed> $data Decoded postquery JSON (odata=verbose).
     *
     * @return list<array<string, mixed>> Raw `Rows.results` row objects.
     */
    private function extractSearchTableRows(array $data): array
    {
        $roots = [
            $data['d']['postquery'] ?? null,
            $data['d']['PostQuery'] ?? null,
            $data['postquery'] ?? null,
            $data['d'] ?? null,
        ];
        foreach ($roots as $root) {
            if (!is_array($root)) {
                continue;
            }
            $rows = $root['PrimaryQueryResult']['RelevantResults']['Table']['Rows']['results'] ?? null;
            if (is_array($rows)) {
                return $rows;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $row One search `Rows.results[]` element.
     *
     * @return array<string, mixed> Managed property name → display value
     */
    private function searchRowCellsToMap(array $row): array
    {
        $cells = $row['Cells']['results'] ?? $row['Cells'] ?? null;
        if (!is_array($cells)) {
            return [];
        }
        $map = [];
        foreach ($cells as $c) {
            if (!is_array($c)) {
                continue;
            }
            $k = trim((string)($c['Key'] ?? ''));
            if ($k === '') {
                continue;
            }
            $map[$k] = $c['Value'] ?? '';
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $cells Search hit cells (managed properties).
     *
     * @return array<string, mixed> Shape compatible with list-item parsing helpers.
     */
    private function syntheticListItemFromSearchCells(array $cells, string $lang): array
    {
        $path = trim((string)($cells['Path'] ?? ''));
        $fileRef = '';
        if (str_starts_with($path, 'https://www.parlament.ch')) {
            $p = parse_url($path, PHP_URL_PATH);
            $fileRef = is_string($p) ? $p : '';
        } elseif (str_starts_with($path, '/')) {
            $fileRef = $path;
        }

        $title = trim((string)($cells['Title'] ?? ''));
        $desc = trim(strip_tags((string)($cells['Description'] ?? '')));
        if ($desc === '') {
            $desc = trim(strip_tags((string)($cells['HitHighlightedSummary'] ?? '')));
        }

        $rawDate = $cells['LastModifiedTime'] ?? $cells['Created'] ?? null;
        $rawDateStr = is_string($rawDate) ? $rawDate : (is_scalar($rawDate) ? (string)$rawDate : '');

        $item = [
            'Title'            => $title,
            'FileRef'          => $fileRef,
            'Created'          => $rawDateStr,
            'ArticleStartDate' => null,
            'ContentType'      => ['Name' => 'Press Release'],
        ];
        foreach (self::LANGUAGES as $l) {
            $item['Title_' . $l] = $title;
        }
        $item['Content_' . $lang] = $desc;

        return $item;
    }

    /**
     * @param array<string, mixed> $item List-shaped row (from search synthesis or legacy).
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
        $plain      = trim(strip_tags($rawContent));

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
        $fromTitle = trim((string)($item['Title'] ?? ''));
        if ($fromTitle !== '' && !self::isMeaninglessParlPressTitle($fromTitle)) {
            return $fromTitle;
        }
        $ref = trim((string)($item['FileRef'] ?? ''));
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
     * Public page URL from list/search row ({@see FileRef}, {@see EncodedAbsUrl}, or Pages path + slug).
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
