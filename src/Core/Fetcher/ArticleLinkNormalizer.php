<?php

declare(strict_types=1);

namespace Seismo\Core\Fetcher;

/**
 * Canonical article URLs for cross-feed deduplication (e.g. Watson topic RSS feeds
 * publishing the same story under different guids).
 */
final class ArticleLinkNormalizer
{
    /** @var list<string> */
    private const STRIP_QUERY_PARAMS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'fbclid',
        'mc_cid',
        'mc_eid',
        'ref',
        'cmpid',
    ];

    public static function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $hashPos = strpos($url, '#');
        if ($hashPos !== false) {
            $url = substr($url, 0, $hashPos);
        }

        $parts = parse_url($url);
        if ($parts === false || ($parts['host'] ?? '') === '') {
            return strtolower(rtrim($url, '/'));
        }

        $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
        $host   = strtolower((string)$parts['host']);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = (string)($parts['path'] ?? '');
        $path = rtrim($path, '/') ?: '/';

        $query = '';
        if (($parts['query'] ?? '') !== '') {
            parse_str((string)$parts['query'], $params);
            foreach (self::STRIP_QUERY_PARAMS as $key) {
                unset($params[$key]);
            }
            if ($params !== []) {
                ksort($params);
                $query = '?' . http_build_query($params);
            }
        }

        return $scheme . '://' . $host . $port . $path . $query;
    }
}
