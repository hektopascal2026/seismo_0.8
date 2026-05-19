<?php

declare(strict_types=1);

final class NewsApiClient
{
    public function __construct(
        private string $apiKey,
        private string $baseUrl,
        private int $timeoutSeconds,
    ) {
    }

    /**
     * @param array<string, string|int> $query
     * @return array{ok: true, data: array}|array{ok: false, error: string, http_code: int}
     */
    public function get(string $path, array $query): array
    {
        $query['apiKey'] = $this->apiKey;
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/') . '?' . http_build_query($query);

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'curl_init failed', 'http_code' => 0];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: seismo-newsbridge/1.0 (+https://github.com/hektopascal2026/seismo_0.5)'],
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'error' => $cerr !== '' ? $cerr : 'empty response', 'http_code' => $code];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'invalid JSON', 'http_code' => $code];
        }

        if ($code !== 200) {
            $msg = $data['message'] ?? $data['code'] ?? 'HTTP error';

            return ['ok' => false, 'error' => (string) $msg, 'http_code' => $code];
        }

        if (($data['status'] ?? '') === 'error') {
            $msg = $data['message'] ?? $data['code'] ?? 'unknown error';

            return ['ok' => false, 'error' => (string) $msg, 'http_code' => $code];
        }

        return ['ok' => true, 'data' => $data];
    }
}
