<?php

declare(strict_types=1);

namespace Seismo\Core\Fetcher;

use DateTimeImmutable;
use DateTimeZone;
use Seismo\Service\Http\BaseClient;

/**
 * Swiss Parliament press releases (parlament.ch SharePoint search REST).
 * Uses **POST** `/_api/search/postquery` with taxonomy **RefinementFilters** (not list `$filter`).
 *
 * Configuration comes from the parent `feeds` row:
 * - `url` — SharePoint **list items** URL (GET) used only to **derive** the site `…/press-releases` base and thus
 *   `…/press-releases/_api/search/postquery`. May be overridden with JSON `search_post_url`.
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

    /** @var list<string> */
    private const LANGUAGES = ['de', 'fr', 'it', 'en', 'rm'];

    /** UTF-8 encoding of U+01C2 twice — SharePoint taxonomy refinement prefix before ASCII-hex label. */
    private const TAX_MARKER = "\xC7\x82\xC7\x82";

    /** Hex of ASCII `Medienmitteilung` — official press releases. */
    private const HEX_NEWS_TYPE_MM = '4d656469656e6d69747465696c756e67';

    /** Hex of ASCII `SDA-Meldung` — agency wire. */
    private const HEX_NEWS_TYPE_SDA = '5344412d4d656c64756e67';

    public function __construct(
        private readonly BaseClient $http = new BaseClient(),
    ) {
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

        $cellRows = $this->executeSharePointSearch($searchPostUrl, $querytext, $limit, $refinements);
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

        $response = $this->http->postJson($postUrl, $payload, [
            'Content-Type' => 'application/json;odata=verbose',
            'Accept'       => 'application/json;odata=verbose',
        ]);

        if ($response->status < 200 || $response->status >= 300) {
            throw new \RuntimeException(
                'parlament.ch search POST HTTP ' . $response->status . ': ' . mb_substr($response->body, 0, 300)
            );
        }
        if ($response->body !== '' && str_contains($response->body, 'Request Rejected')) {
            throw new \RuntimeException(
                'parlament.ch search POST returned a WAF/HTML “Request Rejected” page — try again from the mothership host or set `search_post_url` in the feed description JSON.'
            );
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

        $fileRef = trim((string)($item['FileRef'] ?? ''));
        $pageUrl = 'https://www.parlament.ch' . $fileRef;
        if ($fileRef === '' || !$this->isNavigableHttpUrl($pageUrl)) {
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
        $slugTrim = trim($slug);

        return $slugTrim !== '' && !self::isMeaninglessParlPressTitle($slugTrim) ? $slugTrim : $slug;
    }

    private static function isMeaninglessParlPressTitle(string $t): bool
    {
        $n = mb_strtolower(trim($t));

        return $n === 'untitled' || $n === '(untitled)' || $n === '(no title)';
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
}
