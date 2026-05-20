<?php

declare(strict_types=1);

namespace Seismo\Core\Fetcher;

use Seismo\Service\Http\BaseClient;

/**
 * ASFINAG Pressemeldungen listing is Vue-driven; article URLs come from the Umbraco
 * press API referenced in {@code data-presssearchurl} on the listing page.
 */
final class AsfinagPressListingDiscovery
{
    private const DEFAULT_API_PATH = '/umbraco/api/pressapi/SearchPressItems';

    private const ITEMS_PER_PAGE = 20;

    /**
     * @return list<string> absolute article URLs (may be empty)
     */
    public static function discoverArticleUrls(
        BaseClient $http,
        string $listingUrl,
        string $listingHtml,
        string $linkPattern,
        int $maxUrls
    ): array {
        if (!self::isListingPage($listingUrl, $listingHtml)) {
            return [];
        }

        $meta = self::parseListingMeta($listingHtml);
        if ($meta === null) {
            return [];
        }

        $origin = self::originFromUrl($listingUrl);
        if ($origin === '') {
            return [];
        }

        $maxUrls = max(1, min($maxUrls, 200));
        $urls    = [];
        $seen    = [];
        $page    = 0;

        while (count($urls) < $maxUrls) {
            $query = http_build_query([
                'culture'      => $meta['culture'],
                'currentPage'  => $page,
                'itemsPerPage' => self::ITEMS_PER_PAGE,
                'contentKey'   => $meta['contentKey'],
            ]);
            $apiUrl = $origin . $meta['apiPath'] . '?' . $query;
            $res    = $http->get($apiUrl, ['Accept' => 'application/json']);
            if ($res->status < 200 || $res->status >= 400 || $res->body === '') {
                break;
            }

            try {
                $decoded = json_decode($res->body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                break;
            }

            if (!is_array($decoded)) {
                break;
            }

            $items = $decoded['items'] ?? [];
            if (!is_array($items) || $items === []) {
                break;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $path = trim((string)($item['url'] ?? ''));
                if ($path === '') {
                    continue;
                }
                $absolute = self::resolvePressPath($origin, $path);
                if ($absolute === '') {
                    continue;
                }
                if ($linkPattern !== '' && !str_contains($absolute, $linkPattern)) {
                    continue;
                }
                $key = strtolower(rtrim($absolute, '/'));
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $urls[] = $absolute;
                if (count($urls) >= $maxUrls) {
                    break 2;
                }
            }

            if (!($decoded['hasMoreItems'] ?? false)) {
                break;
            }
            ++$page;
        }

        return $urls;
    }

    public static function isListingPage(string $listingUrl, string $html): bool
    {
        if (!str_contains(strtolower($listingUrl), 'asfinag.at')) {
            return false;
        }
        $path = strtolower((string)(parse_url($listingUrl, PHP_URL_PATH) ?? ''));
        if (str_contains($path, 'pressemeldungen')) {
            return true;
        }

        return str_contains($html, 'data-presssearchurl');
    }

    /**
     * @return array{contentKey: string, culture: string, apiPath: string}|null
     */
    public static function parseListingMeta(string $html): ?array
    {
        $contentKey = self::matchAttribute($html, 'data-currentcontentkey');
        if ($contentKey === '') {
            return null;
        }

        $culture = self::matchAttribute($html, 'data-culture');
        if ($culture === '') {
            $culture = 'de-DE';
        }

        $apiPath = self::matchAttribute($html, 'data-presssearchurl');
        if ($apiPath === '') {
            $apiPath = self::DEFAULT_API_PATH;
        }
        if (!str_starts_with($apiPath, '/')) {
            $apiPath = '/' . $apiPath;
        }

        return [
            'contentKey' => $contentKey,
            'culture'    => $culture,
            'apiPath'    => $apiPath,
        ];
    }

    private static function matchAttribute(string $html, string $name): string
    {
        $pattern = '/' . preg_quote($name, '/') . '="([^"]+)"/i';
        if (preg_match($pattern, $html, $m) !== 1) {
            return '';
        }

        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function originFromUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['host'] ?? '') === '') {
            return '';
        }
        $scheme = $parts['scheme'] ?? 'https';
        $host   = $parts['host'];
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }

    private static function resolvePressPath(string $origin, string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return $origin . $path;
    }
}
