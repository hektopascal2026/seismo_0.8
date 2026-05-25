<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

use Seismo\Core\Fetcher\ScraperContentExtractor;
use Seismo\Core\Fetcher\ScraperFetchService;
use Seismo\Service\Http\BaseClient;
use Seismo\Service\Http\HttpClientException;

/**
 * Replace inbox plain text with readable text from a hosted DE/EN newsletter web view.
 */
final class EmailWebViewBodyHydrator
{
    private const MIN_PLAIN_CHARS = 180;

    /** Slightly below ingest cap so normalization stays safe. */
    private const MAX_PLAIN_CHARS = 500_000;

    public function __construct(
        private readonly BaseClient $http = new BaseClient(25, ScraperFetchService::BROWSER_UA),
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function hydrateRow(array $row, string $url, int $localeRank): array
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return $row;
        }

        try {
            $html  = $this->fetchPageHtml($url);
            $plain = $this->extractPlainText($html);
            if (mb_strlen($plain, 'UTF-8') < self::MIN_PLAIN_CHARS) {
                return $row;
            }
            if (mb_strlen($plain, 'UTF-8') > self::MAX_PLAIN_CHARS) {
                $plain = mb_substr($plain, 0, self::MAX_PLAIN_CHARS, 'UTF-8');
            }

            $row['text_body'] = $plain;
            $row['body_text'] = $plain;

            return EmailMetadata::mergeWebViewHydration($row, $url, $localeRank);
        } catch (\Throwable $e) {
            error_log('Seismo email web-view hydration failed for ' . $url . ': ' . $e->getMessage());

            return $row;
        }
    }

    private function fetchPageHtml(string $url): string
    {
        $res = $this->http->getWebPage($url);
        if (!$res->isOk() || trim($res->body) === '') {
            throw new HttpClientException('HTTP ' . $res->status . ' fetching ' . $url);
        }

        $redirect = self::parseHtmlRedirectTarget($res->body);
        if ($redirect !== null && $redirect !== $url && $redirect !== $res->finalUrl) {
            $res = $this->http->getWebPage($redirect);
            if (!$res->isOk() || trim($res->body) === '') {
                throw new HttpClientException('HTTP ' . $res->status . ' fetching redirect ' . $redirect);
            }
        }

        return $res->body;
    }

    private function extractPlainText(string $html): string
    {
        $read = ScraperContentExtractor::extractReadableContent($html);
        $plain = trim((string)($read['content'] ?? ''));
        if (mb_strlen($plain, 'UTF-8') >= self::MIN_PLAIN_CHARS) {
            return $plain;
        }

        return NewsletterBodyExtractor::fromHtml($html);
    }

    public static function parseHtmlRedirectTarget(string $html): ?string
    {
        if (preg_match(
            '#<meta\s+http-equiv=["\']?refresh["\']?\s+content=["\']?\s*[\d.]+\s*;\s*url=([^"\'>\s]+)#i',
            $html,
            $m
        ) === 1) {
            return self::normalizeRedirectUrl(html_entity_decode((string)$m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (preg_match("#top\.location\s*=\s*['\"]([^'\"]+)['\"]#i", $html, $m) === 1) {
            return self::normalizeRedirectUrl((string)$m[1]);
        }

        return null;
    }

    private static function normalizeRedirectUrl(string $url): ?string
    {
        $url = str_replace('\/', '/', trim($url));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }

        return $url;
    }
}
