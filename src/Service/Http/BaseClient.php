<?php

declare(strict_types=1);

namespace Seismo\Service\Http;

/**
 * Shared HTTP client for plugins. Curl when available, streams fallback otherwise
 * (shared hosts with curl disabled still work — same posture as 0.4).
 *
 * Policy baked in once so every plugin inherits it:
 *   - 30 s total timeout.
 *   - User-Agent: `Seismo/<version> (+url)`.
 *   - One retry on 429 and 503 with ~1 s backoff. No exponential back-off —
 *     for the upstreams we hit (OData, REST, SPARQL) one polite retry is enough;
 *     more aggressive back-off would delay cron without helping.
 *
 * Non-2xx responses do NOT throw. Callers inspect Response::$status and
 * decide what counts as an error for their domain (e.g. ParlCh treats 4xx
 * as bad config, not as a transient blip).
 *
 * cURL easy handles share DNS + connection state via one process-wide share
 * object ({@see curlShareHandle()}). PHP 8.5+ uses {@see curl_share_init_persistent()}
 * so warm FPM workers and long CLI cron ticks reuse TCP/TLS to repeat hosts
 * (scraper article bursts, SharePoint OData, etc.).
 */
final class BaseClient
{
    /** Total request timeout in seconds. */
    public const DEFAULT_TIMEOUT = 30;

    /** Milliseconds to wait before retrying a 429/503. */
    private const RETRY_SLEEP_MS = 1000;

    /** @var \CurlShareHandle|null Lazily created; never closed (persistent on PHP 8.5+). */
    private static ?\CurlShareHandle $curlShare = null;

    public function __construct(
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT,
        private readonly string $userAgent = '',
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public function get(string $url, array $headers = []): Response
    {
        return $this->request('GET', $url, $headers, null);
    }

    /**
     * HTML document fetch: modern desktop Accept* headers. cURL negotiates gzip/br
     * automatically ({@see CURLOPT_ENCODING}) on all requests, same as {@see get()}.
     */
    /**
     * @param bool $sessionCookies When true, use a per-request cookie jar so publishers
     *                             that 302 once to set {@code entitlementToken} (Tamedia) return HTML.
     */
    public function getWebPage(string $url, bool $sessionCookies = false): Response
    {
        $headers = [
            'Accept'            => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language'   => 'en-US,en;q=0.9,de-CH;q=0.8,de;q=0.7,fr;q=0.6',
            'Cache-Control'     => 'no-cache',
            'DNT'               => '1',
            'Upgrade-Insecure-Requests' => '1',
        ];

        return $this->request('GET', $url, $headers, null, $sessionCookies);
    }

    /**
     * @param array<string, scalar> $fields
     * @param array<string, string> $headers
     */
    public function postForm(string $url, array $fields, array $headers = []): Response
    {
        $headers = array_merge(['Content-Type' => 'application/x-www-form-urlencoded'], $headers);

        return $this->request('POST', $url, $headers, http_build_query($fields));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers Merged after defaults; same keys override (e.g. odata=verbose for SharePoint).
     */
    public function postJson(string $url, array $payload, array $headers = []): Response
    {
        $headers = array_merge(
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            $headers
        );

        return $this->request('POST', $url, $headers, (string)json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        bool $sessionCookies = false,
    ): Response {
        $response = $this->execute($method, $url, $headers, $body, $sessionCookies);

        if ($response->status === 429 || $response->status === 503) {
            usleep(self::RETRY_SLEEP_MS * 1000);
            $response = $this->execute($method, $url, $headers, $body, $sessionCookies);
        }

        return $response;
    }

    /**
     * @param array<string, string> $headers
     */
    private function execute(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        bool $sessionCookies = false,
    ): Response {
        $ua = $this->effectiveUserAgent();
        $headers = array_merge(['User-Agent' => $ua], $headers);

        if (function_exists('curl_init')) {
            return $this->executeCurl($method, $url, $headers, $body, $sessionCookies);
        }

        return $this->executeStream($method, $url, $headers, $body);
    }

    /**
     * @param array<string, string> $headers
     */
    private function executeCurl(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        bool $sessionCookies = false,
    ): Response {
        $ch = curl_init();
        if ($ch === false) {
            throw new HttpClientException('curl_init failed.');
        }

        $cookieFile = null;
        if ($sessionCookies) {
            $cookieFile = tempnam(sys_get_temp_dir(), 'seismo_ck_');
            if ($cookieFile === false) {
                throw new HttpClientException('Could not create temporary cookie jar.');
            }
        }

        $flatHeaders = [];
        foreach ($headers as $name => $value) {
            $flatHeaders[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        $headerCallback = static function ($_ch, string $line) use (&$responseHeaders): int {
            $len = strlen($line);
            $trimmed = trim($line);
            if ($trimmed === '' || stripos($trimmed, 'HTTP/') === 0) {
                return $len;
            }
            [$name, $value] = array_pad(explode(':', $trimmed, 2), 2, '');
            $responseHeaders[strtolower(trim($name))] = trim($value);

            return $len;
        };

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $flatHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_HEADERFUNCTION => $headerCallback,
        ];
        // Negotiate gzip/br and transparently decompress (required for feeds such as news.un.org).
        $opts[CURLOPT_ENCODING] = '';
        if ($cookieFile !== null) {
            $opts[CURLOPT_COOKIEJAR]  = $cookieFile;
            $opts[CURLOPT_COOKIEFILE] = $cookieFile;
        }
        $share = self::curlShareHandle();
        if ($share !== null) {
            $opts[CURLOPT_SHARE] = $share;
        }
        curl_setopt_array($ch, $opts);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        try {
            $responseBody = curl_exec($ch);
            if ($responseBody === false) {
                $err = curl_error($ch) ?: 'unknown curl error';
                throw new HttpClientException('HTTP transport failure: ' . $err);
            }

            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

            return new Response($status, (string)$responseBody, $responseHeaders, $finalUrl !== '' ? $finalUrl : $url);
        } finally {
            curl_close($ch);
            if ($cookieFile !== null && is_file($cookieFile)) {
                unlink($cookieFile);
            }
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function executeStream(string $method, string $url, array $headers, ?string $body): Response
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $options = [
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headerLines) . "\r\n",
                'timeout'       => $this->timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 1,
                'max_redirects' => 5,
            ],
        ];
        if ($body !== null) {
            $options['http']['content'] = $body;
        }

        $ctx = stream_context_create($options);
        $responseBody = @file_get_contents($url, false, $ctx);
        if ($responseBody === false) {
            $err = error_get_last();
            throw new HttpClientException('HTTP transport failure: ' . ($err['message'] ?? 'unknown'));
        }

        $status = 0;
        $parsed = [];
        // PHP sets $http_response_header in the calling scope — captured by reference here.
        /** @var list<string> $http_response_header */
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $status = (int)$m[1];
                    continue;
                }
                [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
                if ($value !== '') {
                    $parsed[strtolower(trim($name))] = trim($value);
                }
            }
        }

        $decodedBody = CompressedBodyDecoder::decode((string)$responseBody, $parsed);

        return new Response($status, $decodedBody, $parsed, $url);
    }

    private function effectiveUserAgent(): string
    {
        if ($this->userAgent !== '') {
            return $this->userAgent;
        }

        return function_exists('seismoHttpUserAgent')
            ? seismoHttpUserAgent()
            : 'Seismo/' . (defined('SEISMO_VERSION') ? SEISMO_VERSION : 'dev') . ' (+https://hektopascal.org)';
    }

    /**
     * Process-wide cURL share for DNS + TCP/TLS connection reuse across requests.
     */
    private static function curlShareHandle(): ?\CurlShareHandle
    {
        if (self::$curlShare !== null) {
            return self::$curlShare;
        }

        if (!function_exists('curl_init')) {
            return null;
        }

        if (function_exists('curl_share_init_persistent')) {
            $share = curl_share_init_persistent([
                CURL_LOCK_DATA_DNS,
                CURL_LOCK_DATA_CONNECT,
            ]);
            if ($share instanceof \CurlShareHandle) {
                self::$curlShare = $share;

                return self::$curlShare;
            }

            return null;
        }

        if (!function_exists('curl_share_init')) {
            return null;
        }

        $share = curl_share_init();
        if (!$share instanceof \CurlShareHandle) {
            return null;
        }

        curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
        curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT);
        self::$curlShare = $share;

        return self::$curlShare;
    }
}
