<?php

declare(strict_types=1);

namespace Seismo\Service\Seismogramm\Pipeline;

use Seismo\Service\GeminiResearcherException;

final class ResilientGeminiClient
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const CACHE_API_BASE = 'https://generativelanguage.googleapis.com/v1beta/cachedContents';
    private const HTTP_TIMEOUT_TWO_PASS_SECONDS = 90;
    private const CONTEXT_CACHE_MIN_CHARS = 50_000;
    private const CONTEXT_CACHE_TTL = '600s';

    public int $usagePromptTokens = 0;
    public int $usageOutputTokens = 0;
    public int $usageApiCalls = 0;
    public bool $contextCacheUsed = false;
    public ?string $contextCacheName = null;

    /** @var array<string, array{prompt_tokens: int, output_tokens: int, api_calls: int}> */
    private array $usageByPhase = [];

    /**
     * Registers shared context (e.g. global fingerprint index) for parallel batch reuse.
     */
    public function createContextCache(string $model, string $sharedText, string $apiKey): ?string
    {
        $sharedText = trim($sharedText);
        if ($sharedText === '' || strlen($sharedText) < self::CONTEXT_CACHE_MIN_CHARS) {
            return null;
        }

        $normalizedModel = str_starts_with($model, 'models/') ? $model : 'models/' . $model;
        $url = self::CACHE_API_BASE . '?key=' . rawurlencode($apiKey);
        $payload = [
            'model'    => $normalizedModel,
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [['text' => $sharedText]],
                ],
            ],
            'ttl' => self::CONTEXT_CACHE_TTL,
        ];

        try {
            $response = $this->postWithRetries($url, $payload, $apiKey, 60);
            if ($response['status'] !== 200) {
                error_log('ResilientGeminiClient: context cache create HTTP ' . $response['status']);
                return null;
            }
            $json = json_decode($response['body'], true);
            if (!is_array($json) || !isset($json['name']) || !is_string($json['name'])) {
                return null;
            }
            $this->contextCacheUsed = true;
            $this->contextCacheName = $json['name'];
            $this->recordUsage($json, 'context_cache');
            error_log('ResilientGeminiClient: context cache created: ' . $json['name']);

            return $json['name'];
        } catch (\Throwable $e) {
            error_log('ResilientGeminiClient: context cache failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function attachContextCache(array $payload, ?string $cacheName): array
    {
        if ($cacheName !== null && $cacheName !== '') {
            $payload['cachedContent'] = $cacheName;
        }

        return $payload;
    }

    /**
     * Executes a single POST request to the Gemini API with schema fallback and rate-limit retries.
     *
     * @throws GeminiResearcherException
     */
    public function postPayloadWithSchemaFallback(
        string $model,
        array $payload,
        string $apiKey,
        string $phase,
        int $timeout = self::HTTP_TIMEOUT_TWO_PASS_SECONDS
    ): array {
        $url = self::API_BASE . rawurlencode($model) . ':generateContent';

        // Perform HTTP POST with retry capability
        $response = $this->postWithRetries($url, $payload, $apiKey, $timeout);

        if ($response['status'] === 400 && isset($payload['generationConfig']['responseSchema'])) {
            // Re-attempt without JSON Schema constraints if model rejected it
            error_log('ResilientGeminiClient: model rejected schema constraints. Retrying without schema.');
            unset($payload['generationConfig']['responseSchema']);
            if (isset($payload['generationConfig']['responseMimeType'])) {
                $payload['generationConfig']['responseMimeType'] = 'text/plain';
            }
            $response = $this->postWithRetries($url, $payload, $apiKey, $timeout);
        }

        if ($response['status'] !== 200) {
            throw new GeminiResearcherException(
                'Gemini API request failed during ' . $phase . ' phase: HTTP ' . $response['status'] . ' ' . $response['body'],
                $response['status']
            );
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            throw new GeminiResearcherException('Gemini API returned invalid JSON in ' . $phase . ' phase.');
        }

        $this->recordUsage($data, $phase);

        return $data;
    }

    /**
     * @return array{
     *     prompt_tokens: int,
     *     output_tokens: int,
     *     api_calls: int,
     *     by_phase: array<string, array{prompt_tokens: int, output_tokens: int, api_calls: int}>
     * }
     */
    public function usageReport(): array
    {
        return [
            'prompt_tokens' => $this->usagePromptTokens,
            'output_tokens' => $this->usageOutputTokens,
            'api_calls'     => $this->usageApiCalls,
            'by_phase'      => $this->usageByPhase,
        ];
    }

    private function recordUsage(array $json, string $phase = 'selection'): void
    {
        $phase = $this->normalizeUsagePhase($phase);
        $promptDelta = 0;
        $outputDelta = 0;

        $usage = $json['usageMetadata'] ?? null;
        if (is_array($usage)) {
            $promptDelta = max(0, (int)($usage['promptTokenCount'] ?? 0));
            $outputDelta = max(0, (int)($usage['candidatesTokenCount'] ?? 0))
                + max(0, (int)($usage['thoughtsTokenCount'] ?? 0));
            $this->usagePromptTokens += $promptDelta;
            $this->usageOutputTokens += $outputDelta;
        }

        $this->usageApiCalls++;

        if (!isset($this->usageByPhase[$phase])) {
            $this->usageByPhase[$phase] = [
                'prompt_tokens' => 0,
                'output_tokens' => 0,
                'api_calls'     => 0,
            ];
        }

        $this->usageByPhase[$phase]['prompt_tokens'] += $promptDelta;
        $this->usageByPhase[$phase]['output_tokens'] += $outputDelta;
        $this->usageByPhase[$phase]['api_calls']++;
    }

    private function normalizeUsagePhase(string $phase): string
    {
        return match ($phase) {
            'summary'       => 'summary',
            'context_cache' => 'context_cache',
            default         => 'selection',
        };
    }

    /**
     * Performs parallel POST requests using curl_multi.
     *
     * @param array<string, array> $jobs map of job_id => payload array
     * @return array<string, array{status: int, body: string}>
     */
    public function postParallel(string $model, array $jobs, string $apiKey, int $timeout = self::HTTP_TIMEOUT_TWO_PASS_SECONDS): array
    {
        if ($jobs === []) {
            return [];
        }

        if (!function_exists('curl_init') || !function_exists('curl_multi_init')) {
            // Sequential fallback if curl_multi is not compiled
            $results = [];
            foreach ($jobs as $id => $payload) {
                try {
                    $url = self::API_BASE . rawurlencode($model) . ':generateContent';
                    $res = $this->postWithRetries($url, $payload, $apiKey, $timeout);
                    $results[$id] = $res;
                    if ($res['status'] === 200) {
                        $data = json_decode($res['body'], true);
                        if (is_array($data)) {
                            $this->recordUsage($data, 'selection');
                        }
                    }
                } catch (\Throwable $e) {
                    $results[$id] = ['status' => 500, 'body' => $e->getMessage()];
                }
            }
            return $results;
        }

        $url = self::API_BASE . rawurlencode($model) . ':generateContent';
        $mh = curl_multi_init();
        if ($mh === false) {
            return [];
        }

        $handles = [];
        foreach ($jobs as $id => $payload) {
            $body = json_encode($payload);
            $ch = curl_init($url);
            if ($ch === false) {
                continue;
            }

            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'x-goog-api-key: ' . $apiKey,
                ],
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$id] = $ch;
        }

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $id => $ch) {
            $raw = curl_multi_getcontent($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $results[$id] = [
                'status' => $status,
                'body'   => $raw === false ? '' : $raw,
            ];
            
            if ($status === 200 && $raw !== false && $raw !== '') {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    $this->recordUsage($data, 'selection');
                }
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);
        return $results;
    }

    /**
     * Executes HTTP POST using standard curl_init, retrying on 429 rate limit errors.
     */
    private function postWithRetries(string $url, array $payload, string $apiKey, int $timeout): array
    {
        $body = json_encode($payload);
        $attempts = 0;
        $maxAttempts = 3;

        while (true) {
            $attempts++;
            $ch = curl_init($url);
            if ($ch === false) {
                return ['status' => 500, 'body' => 'Failed to initialize cURL handle.'];
            }

            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'x-goog-api-key: ' . $apiKey,
                ],
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            ]);

            $raw = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            // Retry on HTTP 429 (Too Many Requests) or HTTP 503 (Service Unavailable)
            if (($status === 429 || $status === 503) && $attempts < $maxAttempts) {
                $sleep = $status === 429 ? 12 : 3;
                error_log(sprintf('ResilientGeminiClient: received HTTP %d. Sleep %ds and retry (Attempt %d/%d)', $status, $sleep, $attempts, $maxAttempts));
                sleep($sleep);
                continue;
            }

            return [
                'status' => $status === 0 ? 500 : $status,
                'body'   => $raw === false ? 'cURL Transport Error: ' . $err : (string)$raw,
            ];
        }
    }
}
