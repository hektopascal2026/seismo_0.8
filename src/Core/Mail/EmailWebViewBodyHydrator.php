<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

use Seismo\Core\PlainTextNormalizer;
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
            $plain = null;
            if (preg_match('#ec\.europa\.eu/commission/presscorner/detail/([a-z]{2})/([a-z0-9_-]+)#i', $url, $m)) {
                $lang = $m[1];
                $refRaw = $m[2];
                $parts = explode('_', $refRaw);
                $refFormatted = strtoupper($parts[0]) . '/' . implode('/', array_slice($parts, 1));
                $apiUrl = "https://ec.europa.eu/commission/presscorner/api/documents?reference=" . urlencode($refFormatted) . "&language=" . urlencode($lang);
                
                $res = $this->http->getWebPage($apiUrl, true);
                if ($res->isOk() && trim($res->body) !== '') {
                    $data = json_decode($res->body, true);
                    $htmlContent = $data['docuLanguageResource']['htmlContent'] ?? '';
                    if ($htmlContent !== '') {
                        $plain = trim(html_entity_decode(strip_tags($htmlContent), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    }
                }
            }

            if ($plain === null) {
                $html  = $this->fetchPageHtml($url);
                $plain = PlainTextNormalizer::forIngest($this->extractPlainText($html));
            } else {
                $plain = PlainTextNormalizer::forIngest($plain);
            }

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
        // Brevo / Tamedia-style redirects often need a cookie jar (entitlementToken) before rm.coe.int serves HTML.
        $res = $this->http->getWebPage($url, true);
        if (!$res->isOk() || trim($res->body) === '') {
            throw new HttpClientException('HTTP ' . $res->status . ' fetching ' . $url);
        }

        $redirect = self::parseHtmlRedirectTarget($res->body);
        if ($redirect !== null && $redirect !== $url && $redirect !== $res->finalUrl) {
            $res = $this->http->getWebPage($redirect, true);
            if (!$res->isOk() || trim($res->body) === '') {
                throw new HttpClientException('HTTP ' . $res->status . ' fetching redirect ' . $redirect);
            }
        }

        if (self::looksLikeBlockedPage($res->body)) {
            throw new HttpClientException('Blocked or challenge page for ' . $url);
        }

        return $res->body;
    }

    private function extractPlainText(string $html): string
    {
        if (self::looksLikeBlockedPage($html)) {
            return '';
        }

        $read = ScraperContentExtractor::extractReadableContent($html);
        $plain = trim((string)($read['content'] ?? ''));
        if (mb_strlen($plain, 'UTF-8') >= self::MIN_PLAIN_CHARS && !self::looksLikeBlockedPlainText($plain)) {
            return $plain;
        }

        $plain = NewsletterBodyExtractor::fromHtml($html);

        return self::looksLikeBlockedPlainText($plain) ? '' : $plain;
    }

    private static function looksLikeBlockedPage(string $html): bool
    {
        $lower = mb_strtolower($html, 'UTF-8');

        return str_contains($lower, 'you have been blocked')
            || str_contains($lower, 'attention required! | cloudflare')
            || (str_contains($lower, 'cloudflare') && str_contains($lower, 'cf-error-details'));
    }

    private static function looksLikeBlockedPlainText(string $plain): bool
    {
        $lower = mb_strtolower(trim($plain), 'UTF-8');

        return str_contains($lower, 'you have been blocked')
            || str_contains($lower, 'unable to access');
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
