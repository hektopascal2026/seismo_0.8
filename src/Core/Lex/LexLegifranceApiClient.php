<?php

declare(strict_types=1);

namespace Seismo\Core\Lex;

/**
 * OAuth + JSON client for the Légifrance PISTE API (lf-engine-app).
 */
final class LexLegifranceApiClient
{
    private ?string $accessToken = null;
    private int $tokenExpiresAt = 0;

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $tokenUrl = 'https://oauth.piste.gouv.fr/api/oauth/token',
        private string $apiBase = 'https://api.piste.gouv.fr/dila/legifrance/lf-engine-app',
    ) {
        $this->apiBase = rtrim($this->apiBase, '/');
    }

    /**
     * @param array<string, mixed> $config Légifrance block from {@see LexConfigStore}.
     */
    public static function fromConfig(array $config): self
    {
        $clientId = trim((string)($config['client_id'] ?? ''));
        $clientSecret = (string)($config['client_secret'] ?? '');
        if ($clientId === '' || trim($clientSecret) === '') {
            throw new \RuntimeException('Légifrance is not configured: set client_id and client_secret on the Lex page.');
        }

        return new self(
            $clientId,
            $clientSecret,
            trim((string)($config['oauth_token_url'] ?? 'https://oauth.piste.gouv.fr/api/oauth/token')),
            rtrim(trim((string)($config['api_base_url'] ?? 'https://api.piste.gouv.fr/dila/legifrance/lf-engine-app')), '/'),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    public function postJson(string $path, array $body): mixed
    {
        $result = $this->postJsonResponse($path, $body);
        if ($result['status'] >= 400) {
            throw new \RuntimeException($result['error'] ?? ('HTTP ' . $result['status']));
        }

        return $result['decoded'];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, decoded: mixed, error: ?string}
     */
    public function postJsonResponse(string $path, array $body): array
    {
        $path = '/' . ltrim($path, '/');
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode Légifrance request JSON.');
        }

        return $this->httpRequestResponse('POST', $this->apiBase . $path, [
            'Authorization: Bearer ' . $this->getAccessToken(),
            'Content-Type: application/json',
            'Accept: application/json',
        ], $json);
    }

    private function getAccessToken(): string
    {
        if ($this->accessToken !== null && time() < $this->tokenExpiresAt - 60) {
            return $this->accessToken;
        }

        $payload = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => 'openid',
        ], '', '&', PHP_QUERY_RFC3986);

        $result = $this->httpRequestResponse('POST', $this->tokenUrl, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], $payload);

        $decoded = $result['decoded'];
        if ($result['status'] >= 400 || !is_array($decoded) || empty($decoded['access_token']) || !is_string($decoded['access_token'])) {
            throw new \RuntimeException('Légifrance OAuth token response missing access_token.');
        }

        $this->accessToken = $decoded['access_token'];
        $this->tokenExpiresAt = time() + max(60, (int)($decoded['expires_in'] ?? 3600));

        return $this->accessToken;
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, decoded: mixed, error: ?string}
     */
    private function httpRequestResponse(string $method, string $url, array $headers, string $body): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP curl extension is required for Légifrance API calls.');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed for ' . $url);
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        if ($raw === false) {
            return [
                'status' => 0,
                'decoded' => null,
                'error' => 'HTTP request failed for ' . $url . ($err !== '' ? ': ' . $err : ''),
            ];
        }

        $decoded = json_decode((string)$raw, true);
        $error   = null;
        if ($status >= 400) {
            $error = 'HTTP ' . $status . ' from ' . $url . ': ' . mb_substr((string)$raw, 0, 500);
        }

        return [
            'status' => $status,
            'decoded' => $decoded,
            'error' => $error,
        ];
    }
}
