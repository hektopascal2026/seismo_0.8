<?php

declare(strict_types=1);

namespace Seismo\Core\Fetcher;

/**
 * Drop unwanted rows from {@see RssFetchService::fetchFeedItems()} before upsert.
 */
final class RssFeedItemFilter
{
    public static function shouldSkip(string $feedUrl, string $title): bool
    {
        if (!self::isGolemFeedUrl($feedUrl)) {
            return false;
        }

        return self::isGolemAdvertisementTitle($title);
    }

    public static function isGolemAdvertisementTitle(string $title): bool
    {
        return (bool)preg_match('/^Anzeige\s*:/ui', trim($title));
    }

    private static function isGolemFeedUrl(string $feedUrl): bool
    {
        $host = parse_url(trim($feedUrl), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower($host);

        return $host === 'golem.de' || $host === 'rss.golem.de' || str_ends_with($host, '.golem.de');
    }
}
