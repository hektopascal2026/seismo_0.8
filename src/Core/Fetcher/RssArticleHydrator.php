<?php

declare(strict_types=1);

namespace Seismo\Core\Fetcher;

use Seismo\Service\Http\BaseClient;

/**
 * Optional second step after {@see RssFetchService}: fetch publisher pages for
 * thin items and fill {@see \Seismo\Repository\FeedItemRepository} `content`
 * via {@see ArticlePageBodyExtractor} (JSON-LD, Readability, meta tags).
 */
final class RssArticleHydrator
{
    public const MIN_PLAIN_CHARS = 400;

    public const MAX_PER_FEED_RUN = 10;

    /** Microseconds between consecutive fetches to the same host. */
    public const SAME_HOST_DELAY_USEC = 250_000;

    public const MAX_CONTENT_CHARS = 50_000;

    /** Retry UA when publishers serve a cookie wall to desktop browsers (e.g. golem.de). */
    public const CRAWLER_FALLBACK_UA
        = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    public function __construct(
        private BaseClient $http = new BaseClient(
            BaseClient::DEFAULT_TIMEOUT,
            ScraperFetchService::BROWSER_UA
        ),
        private ?BaseClient $crawlerHttp = null,
        private GoogleNewsArticleUrlResolver $googleNewsUrls = new GoogleNewsArticleUrlResolver(),
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items Normalised rows from {@see RssFetchService::fetchFeedItems()}
     * @return list<array<string, mixed>>
     */
    public function hydrateThinItems(array $items, bool $enabled, int $maxPerRun = self::MAX_PER_FEED_RUN): array
    {
        if (!$enabled || $items === []) {
            return $items;
        }

        $maxPerRun = max(0, min($maxPerRun, 50));
        $done      = 0;
        $lastHost  = null;

        foreach ($items as &$row) {
            if ($done >= $maxPerRun) {
                break;
            }
            if (!$this->needsHydration($row)) {
                continue;
            }
            $url = trim((string)($row['link'] ?? ''));
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                continue;
            }
            $url = $this->googleNewsUrls->resolve($url);
            $row['link'] = mb_substr($url, 0, 500);

            $host = parse_url($url, PHP_URL_HOST);
            $hostKey = is_string($host) ? strtolower($host) : '';
            if ($lastHost !== null && $hostKey !== '' && $hostKey === $lastHost) {
                usleep(self::SAME_HOST_DELAY_USEC);
            }

            $plain = $this->fetchArticlePlainText($url);
            if ($plain !== null && $plain !== '') {
                $row['content']       = $plain;
                $row['content_hash']  = '';
            }

            $lastHost = $hostKey !== '' ? $hostKey : $lastHost;
            $done++;
        }
        unset($row);

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function needsHydration(array $row): bool
    {
        return $this->plainTextLength($row) < self::MIN_PLAIN_CHARS;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function plainTextLength(array $row): int
    {
        $content = trim((string)($row['content'] ?? ''));
        $desc    = trim((string)($row['description'] ?? ''));
        $body    = $content !== '' ? $content : $desc;

        return mb_strlen($this->stripToPlain($body), 'UTF-8');
    }

    private function stripToPlain(string $html): string
    {
        if ($html === '') {
            return '';
        }

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function fetchArticlePlainText(string $url): ?string
    {
        try {
            $host    = parse_url($url, PHP_URL_HOST);
            $exclude = ArticlePageBodyExtractor::excludeSelectorsForHost(is_string($host) ? $host : '');

            $content = $this->fetchAndExtract($url, $this->http, $exclude);
            if ($content !== null) {
                return $content;
            }

            return $this->fetchAndExtract($url, $this->crawlerHttpClient(), $exclude);
        } catch (\Throwable $e) {
            error_log('Seismo RssArticleHydrator: ' . $url . ': ' . $e->getMessage());

            return null;
        }
    }

    private function crawlerHttpClient(): BaseClient
    {
        return $this->crawlerHttp ??= new BaseClient(BaseClient::DEFAULT_TIMEOUT, self::CRAWLER_FALLBACK_UA);
    }

    private function fetchAndExtract(string $url, BaseClient $http, string $excludeSelectors): ?string
    {
        $res = $http->getWebPage($url);
        if (!$res->isOk()) {
            return null;
        }

        $html = trim($res->body);
        if ($html === '') {
            return null;
        }

        if (ArticlePageBodyExtractor::looksLikeConsentWall($html, $res->finalUrl)) {
            return null;
        }

        $content = ArticlePageBodyExtractor::extractBestArticleBody($html, $excludeSelectors);
        if ($content === '') {
            return null;
        }

        if (ArticlePageBodyExtractor::looksLikeConsentBody(ArticlePageBodyExtractor::toPlainText($content))) {
            return null;
        }

        if (mb_strlen($content, 'UTF-8') > self::MAX_CONTENT_CHARS) {
            $content = mb_substr($content, 0, self::MAX_CONTENT_CHARS);
        }

        return $content;
    }
}
