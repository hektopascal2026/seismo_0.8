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
 */
final class BaseClient
{
    /** Total request timeout in seconds. */
    public const DEFAULT_TIMEOUT = 30;

    /** Milliseconds to wait before retrying a 429/503. */
    private const RETRY_SLEEP_MS = 1000;

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
        return $this->request('GET', $url, $headers, null, false);
    }

    /**
     * HTML document fetch: modern desktop Accept* headers, optional Accept-Encoding
     * negotiation on cURL (automatic decompression). Use for scraping only.
     */
    public function getWebPage(string $url): Response
    {
        $headers = [
            'Accept'            => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language'   => 'en-US,en;q=0.9,de-CH;q=0.8,de;q=0.7,fr;q=0.6',
            'Cache-Control'     => 'no-cache',
            'DNT'               => '1',
            'Upgrade-Insecure-Requests' => '1',
        ];

        return $this->request('GET', $url, $headers, null, true);
    }

    /**
     * @param array<string, scalar> $fields
     * @param array<string, string> $headers
     */
    public function postForm(string $url, array $fields, array $headers = []): Response
    {
        $headers = array_merge(['Content-Type' => 'application/x-www-form-urlencoded'], $headers);

        return $this->request('POST', $url, $headers, http_build_query($fields), false);
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

        return $this->request('POST', $url, $headers, (string)json_encode($payload, JSON_THROW_ON_ERROR), false);
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $method, string $url, array $headers, ?string $body, bool $curlWebEncoding): Response
    {
        $response = $this->execute($method, $url, $headers, $body, $curlWebEncoding);

        if ($response->status === 429 || $response->status === 503) {
            usleep(self::RETRY_SLEEP_MS * 1000);
            $response = $this->execute($method, $url, $headers, $body, $curlWebEncoding);
        }

        return $response;
    }

    /**
     * @param array<string, string> $headers
     */
    private function execute(string $method, string $url, array $headers, ?string $body, bool $curlWebEncoding): Response
    {
        $ua = $this->effectiveUserAgent();
        $headers = array_merge(['User-Agent' => $ua], $headers);

        if (function_exists('curl_init')) {
            return $this->executeCurl($method, $url, $headers, $body, $curlWebEncoding);
        }

        return $this->executeStream($method, $url, $headers, $body);
    }

    /**
     * @param array<string, string> $headers
     */
    private function executeCurl(string $method, string $url, array $headers, ?string $body, bool $webEncoding): Response
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new HttpClientException('curl_init failed.');
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
        if ($webEncoding) {
            $opts[CURLOPT_ENCODING] = '';
        }
        curl_setopt_array($ch, $opts);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $err = curl_error($ch) ?: 'unknown curl error';
            curl_close($ch);
            throw new HttpClientException('HTTP transport failure: ' . $err);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        return new Response($status, (string)$responseBody, $responseHeaders, $finalUrl !== '' ? $finalUrl : $url);
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

        return new Response($status, (string)$responseBody, $parsed, $url);
    }

    private function effectiveUserAgent(): string
    {
        if ($this->userAgent !== '') {
            return $this->userAgent;
        }
        $version = defined('SEISMO_VERSION') ? SEISMO_VERSION : 'dev';
        $contact = defined('SEISMO_MOTHERSHIP_URL') && SEISMO_MOTHERSHIP_URL !== ''
            ? ' (+' . SEISMO_MOTHERSHIP_URL . ')'
            : '';

        return 'Seismo/' . $version . $contact;
    }
}
