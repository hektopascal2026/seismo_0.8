<?php

declare(strict_types=1);

namespace Seismo\Core\Fetcher;

/**
 * Canonical listing URL for scraper_configs ↔ feeds matching.
 *
 * Trailing slashes on the path are ignored so `…/News-and-Events` and
 * `…/News-and-Events/` do not create duplicate feeds rows.
 */
final class ScraperListingUrl
{
    /**
     * SQL boolean: two URL columns differ only by a trailing slash on the path.
     */
    public static function sqlColumnsEqual(string $leftColumn, string $rightColumn): string
    {
        return 'TRIM(TRAILING \'/\' FROM ' . $leftColumn . ') = TRIM(TRAILING \'/\' FROM ' . $rightColumn . ')';
    }

    /**
     * SQL boolean: column equals the bound normalized URL parameter.
     *
     * Only the column is TRIMmed — the bound value must already be
     * {@see normalize()}d (MariaDB rejects TRIM(… FROM ?) on placeholders).
     */
    public static function sqlColumnEqualsParam(string $column): string
    {
        return 'TRIM(TRAILING \'/\' FROM ' . $column . ') = ?';
    }

    public static function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $parts = parse_url($url);
        if ($parts === false || ($parts['host'] ?? '') === '') {
            return $url;
        }

        $path = (string)($parts['path'] ?? '');
        if ($path !== '' && $path !== '/' && str_ends_with($path, '/')) {
            $parts['path'] = rtrim($path, '/') ?: '/';
        }

        return self::buildUrl($parts);
    }

    public static function equivalent(string $a, string $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }

    /**
     * @param array<string, mixed> $parts
     */
    private static function buildUrl(array $parts): string
    {
        $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
        $user = (string)($parts['user'] ?? '');
        $pass = (string)($parts['pass'] ?? '');
        $auth = $user !== '' ? $user . ($pass !== '' ? ':' . $pass : '') . '@' : '';
        $host = (string)$parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = (string)($parts['path'] ?? '');
        if ($path === '') {
            $path = '';
        }
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';

        return $scheme . '://' . $auth . $host . $port . $path . $query . $fragment;
    }
}
