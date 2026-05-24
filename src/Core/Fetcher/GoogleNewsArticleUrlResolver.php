<?php

declare(strict_types=1);

namespace Seismo\Core\Fetcher;

use Seismo\Service\Http\BaseClient;

/**
 * Resolve {@code news.google.com/rss/articles/…} links to the publisher URL.
 *
 * Google News RSS items use encoded article tokens (2024+). The wrapper page is not
 * the publisher article — hydration must decode to e.g. {@code nzz.ch} first.
 *
 * @see https://github.com/SSujitX/google-news-url-decoder (new_decoderv2 flow)
 */
final class GoogleNewsArticleUrlResolver
{
    private const BATCHEXECUTE_URL = 'https://news.google.com/_/DotsSplashUi/data/batchexecute';

    public function __construct(
        private BaseClient $http = new BaseClient(
            BaseClient::DEFAULT_TIMEOUT,
            ScraperFetchService::BROWSER_UA
        ),
    ) {
    }

    /**
     * Returns a publisher URL when resolvable, otherwise the input unchanged.
     */
    public function resolve(string $url): string
    {
        $url = trim($url);
        if ($url === '' || !$this->isGoogleNewsArticleUrl($url)) {
            return $url;
        }

        $token = $this->extractArticleToken($url);
        if ($token === null) {
            return $url;
        }

        $offline = $this->decodeOffline($token);
        if ($offline !== null && $this->isPublisherUrl($offline)) {
            return $offline;
        }

        $remote = $this->decodeViaBatchExecute($token);
        if ($remote !== null && $this->isPublisherUrl($remote)) {
            return $remote;
        }

        return $url;
    }

    private function isGoogleNewsArticleUrl(string $url): bool
    {
        return str_contains($url, 'news.google.com') && str_contains($url, '/articles/');
    }

    private function isPublisherUrl(string $url): bool
    {
        if (!$this->isNavigableHttpUrl($url)) {
            return false;
        }

        return !str_contains($url, 'news.google.com');
    }

    private function extractArticleToken(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || !preg_match('#/articles/([^/]+)#', $path, $m)) {
            return null;
        }

        return $m[1];
    }

    private function decodeOffline(string $token): ?string
    {
        $pad = strlen($token) % 4;
        if ($pad > 0) {
            $token .= str_repeat('=', 4 - $pad);
        }
        $str = base64_decode(strtr($token, '-_', '+/'), true);
        if ($str === false || $str === '' || str_contains($str, 'AU_yqL')) {
            return null;
        }

        $prefix = "\x08\x13\x22";
        if (str_starts_with($str, $prefix)) {
            $str = substr($str, strlen($prefix));
        }
        $suffix = "\xd2\x01\x00";
        if (str_ends_with($str, $suffix)) {
            $str = substr($str, 0, -strlen($suffix));
        }
        if ($str === '') {
            return null;
        }

        $len = ord($str[0]);
        if ($len >= 0x80 && strlen($str) > 2) {
            $str = substr($str, 2, $len + 2);
        } else {
            $str = substr($str, 1, $len + 1);
        }

        if (preg_match('#^https?://#', $str)) {
            return $str;
        }

        return null;
    }

    /**
     * Current Google News tokens: read signature + timestamp from the RSS article shell page.
     */
    private function decodeViaBatchExecute(string $token): ?string
    {
        $params = $this->fetchDecodingParams($token);
        if ($params === null) {
            return null;
        }

        $inner = '["garturlreq",[["X","X",["X","X"],null,null,1,1,"US:en",null,1,'
            . 'null,null,null,null,null,0,1],"X","X",1,[1,1,1],1,1,null,0,0,null,0],'
            . '"' . $params['token'] . '",' . $params['timestamp'] . ',"' . $params['signature'] . '"]';

        // Matches googlenewsdecoder: json.dumps([[["Fbv4je", inner, null, "generic"]]]).
        $fReq = json_encode([[['Fbv4je', $inner, null, 'generic']]], JSON_UNESCAPED_SLASHES);
        if ($fReq === false) {
            return null;
        }

        try {
            $res = $this->http->postForm(
                self::BATCHEXECUTE_URL,
                ['f.req' => $fReq],
                [
                    'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8',
                    'Referer'      => 'https://news.google.com/',
                ]
            );
        } catch (\Throwable $e) {
            error_log('Seismo GoogleNewsArticleUrlResolver batchexecute: ' . $e->getMessage());

            return null;
        }

        if (!$res->isOk() || $res->body === '') {
            return null;
        }

        return $this->parseBatchExecuteBody($res->body);
    }

    /**
     * @return ?array{token: string, signature: string, timestamp: string}
     */
    private function fetchDecodingParams(string $token): ?array
    {
        foreach (
            [
                'https://news.google.com/rss/articles/' . $token,
                'https://news.google.com/articles/' . $token,
            ] as $pageUrl
        ) {
            try {
                $res = $this->http->getWebPage($pageUrl);
            } catch (\Throwable) {
                continue;
            }
            if (!$res->isOk() || $res->body === '') {
                continue;
            }

            $signature = null;
            $timestamp = null;
            if (preg_match('/\bdata-n-a-sg="([^"]+)"/', $res->body, $m)) {
                $signature = $m[1];
            }
            if (preg_match('/\bdata-n-a-ts="([^"]+)"/', $res->body, $m)) {
                $timestamp = $m[1];
            }
            if ($signature !== null && $timestamp !== null) {
                return [
                    'token'     => $token,
                    'signature' => $signature,
                    'timestamp' => $timestamp,
                ];
            }
        }

        return null;
    }

    private function parseBatchExecuteBody(string $body): ?string
    {
        $parts = preg_split('/\r?\n\r?\n/', $body);
        if ($parts === false) {
            return null;
        }
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || str_starts_with($part, ")]}'")) {
                $part = preg_replace('/^\)\]\}\'\s*/', '', $part) ?? $part;
            }
            if ($part === '' || $part[0] !== '[') {
                continue;
            }
            try {
                $decoded = json_decode($part, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (!is_array($decoded) || !isset($decoded[0][2])) {
                continue;
            }
            try {
                $inner = json_decode((string)$decoded[0][2], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (is_array($inner) && isset($inner[1]) && is_string($inner[1]) && $this->isPublisherUrl($inner[1])) {
                return $inner[1];
            }
        }

        return null;
    }

    private function isNavigableHttpUrl(string $url): bool
    {
        return (bool)preg_match('#^https?://#i', trim($url));
    }
}
