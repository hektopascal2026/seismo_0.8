<?php

declare(strict_types=1);

namespace Seismo\Service;

use Seismo\Controller\SettingsController;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Service\Http\BaseClient;
use Seismo\Service\Http\HttpClientException;
use Seismo\Service\Http\Response;

/**
 * Calls Google Gemini `generateContent` for the AI Briefing Builder.
 *
 * Single-pass only: full entry markdown is sent so the model can use granular source detail.
 */
final class GeminiBriefingService
{
    /** Override via `system_config` key `gemini:model`. */
    public const CONFIG_KEY_MODEL = 'gemini:model';

    /** Optional `system_config` override for {@see DEFAULT_MAX_OUTPUT_TOKENS}. */
    public const CONFIG_KEY_MAX_OUTPUT_TOKENS = 'gemini:max_output_tokens';

    public const DEFAULT_MODEL = 'gemini-2.5-flash';

    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private const HTTP_TIMEOUT_SECONDS = 120;

    private const DEFAULT_TEMPERATURE = 0.2;

    private const DEFAULT_MAX_OUTPUT_TOKENS = 8192;

    private const DEFAULT_MAX_RETRIES = 4;

    private const RETRY_BACKOFF_SECONDS = 2.0;

    /** @var list<int> */
    private const TRANSIENT_HTTP_STATUSES = [429, 500, 502, 503, 504];

    private readonly string $model;

    private readonly int $maxOutputTokens;

    public function __construct(
        private readonly SystemConfigRepository $config,
        private readonly BaseClient $http = new BaseClient(self::HTTP_TIMEOUT_SECONDS),
    ) {
        $configured = trim((string)($config->get(self::CONFIG_KEY_MODEL) ?? ''));
        $this->model  = $configured !== '' ? $configured : self::DEFAULT_MODEL;

        $rawTokens = trim((string)($config->get(self::CONFIG_KEY_MAX_OUTPUT_TOKENS) ?? ''));
        if ($rawTokens !== '' && ctype_digit($rawTokens)) {
            $this->maxOutputTokens = max(256, min(8192, (int)$rawTokens));
        } else {
            $this->maxOutputTokens = self::DEFAULT_MAX_OUTPUT_TOKENS;
        }
    }

    /**
     * @throws GeminiBriefingException
     */
    public function generateSummary(string $systemPrompt, string $markdownContext): string
    {
        $apiKey = trim((string)($this->config->get(SettingsController::KEY_GEMINI_API_KEY) ?? ''));
        if ($apiKey === '') {
            throw GeminiBriefingException::missingApiKey();
        }

        $systemPrompt = trim($systemPrompt);
        if ($systemPrompt === '') {
            throw GeminiBriefingException::invalidInput('System prompt is required.');
        }

        $url     = self::API_BASE . rawurlencode($this->model) . ':generateContent';
        $payload = $this->buildPayload($systemPrompt, $markdownContext);

        $response = $this->postWithRetries($url, $payload, $apiKey);

        if (!$response->isOk()) {
            throw $this->exceptionFromFailedResponse($response);
        }

        return $this->extractText($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(string $systemPrompt, string $markdownContext): array
    {
        return [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $systemPrompt],
                ],
            ],
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [
                        [
                            'text' => "Below is a Seismo briefing export (Markdown). "
                                . "Write the briefing according to your system instructions. "
                                . "Preserve nuance from individual entries; do not invent facts.\n\n"
                                . trim($markdownContext),
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature'     => self::DEFAULT_TEMPERATURE,
                'maxOutputTokens' => $this->maxOutputTokens,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @throws GeminiBriefingException
     */
    private function postWithRetries(string $url, array $payload, string $apiKey): Response
    {
        $headers = ['x-goog-api-key' => $apiKey];
        $attempts = self::DEFAULT_MAX_RETRIES + 1;
        $lastTransport = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                $response = $this->http->postJson($url, $payload, $headers);
            } catch (HttpClientException $e) {
                $lastTransport = $e;
                if ($attempt >= $attempts - 1) {
                    error_log('GeminiBriefingService transport: ' . $e->getMessage());
                    throw GeminiBriefingException::transportFailed();
                }
                $this->sleepBeforeRetry($attempt);

                continue;
            } catch (\JsonException $e) {
                error_log('GeminiBriefingService request JSON: ' . $e->getMessage());
                throw GeminiBriefingException::transportFailed();
            }

            if ($this->isTransientHttp($response->status) && $attempt < $attempts - 1) {
                error_log(
                    'GeminiBriefingService transient HTTP ' . $response->status
                    . '; retry ' . ($attempt + 1) . '/' . ($attempts - 1)
                );
                $this->sleepBeforeRetry($attempt);

                continue;
            }

            return $response;
        }

        if ($lastTransport !== null) {
            error_log('GeminiBriefingService transport: ' . $lastTransport->getMessage());
        }

        throw GeminiBriefingException::transportFailed();
    }

    private function isTransientHttp(int $status): bool
    {
        return in_array($status, self::TRANSIENT_HTTP_STATUSES, true);
    }

    private function sleepBeforeRetry(int $attempt): void
    {
        $seconds = self::RETRY_BACKOFF_SECONDS * ($attempt + 1);
        usleep((int)($seconds * 1_000_000));
    }

    /**
     * @throws GeminiBriefingException
     */
    private function exceptionFromFailedResponse(Response $response): GeminiBriefingException
    {
        $apiMessage = $this->parseApiErrorMessage($response);
        $bodySample = substr($response->body, 0, 500);
        error_log(
            'GeminiBriefingService HTTP ' . $response->status
            . ' model=' . $this->model
            . ($apiMessage !== '' ? ': ' . $apiMessage : ': ' . $bodySample)
        );

        if ($response->status === 400 && $this->isInvalidApiKeyBody($response->body)) {
            return GeminiBriefingException::invalidApiKey();
        }

        if ($response->status === 404) {
            return GeminiBriefingException::modelNotFound($this->model, $apiMessage);
        }

        if ($apiMessage !== '') {
            return GeminiBriefingException::fromApiMessage($response->status, $apiMessage);
        }

        return GeminiBriefingException::fromHttpStatus($response->status);
    }

    private function isInvalidApiKeyBody(string $body): bool
    {
        return str_contains($body, 'API_KEY_INVALID')
            || str_contains($body, 'API key expired')
            || (str_contains($body, 'API_KEY') && str_contains($body, 'INVALID'));
    }

    private function parseApiErrorMessage(Response $response): string
    {
        try {
            $json = $response->json();
        } catch (\JsonException) {
            return '';
        }
        if (!isset($json['error']) || !is_array($json['error'])) {
            return '';
        }

        return trim((string)($json['error']['message'] ?? ''));
    }

    /**
     * @throws GeminiBriefingException
     */
    private function extractText(Response $response): string
    {
        try {
            $json = $response->json();
        } catch (\JsonException $e) {
            error_log('GeminiBriefingService response JSON: ' . $e->getMessage());
            throw GeminiBriefingException::badResponse();
        }

        if (isset($json['error']) && is_array($json['error'])) {
            $msg = (string)($json['error']['message'] ?? 'unknown');
            error_log('GeminiBriefingService API error: ' . $msg);
            throw GeminiBriefingException::badResponse();
        }

        $candidates = $json['candidates'] ?? null;
        if (!is_array($candidates) || $candidates === []) {
            throw GeminiBriefingException::emptyResponse();
        }

        $first = $candidates[0];
        if (!is_array($first)) {
            throw GeminiBriefingException::badResponse();
        }

        $finish = (string)($first['finishReason'] ?? '');
        if ($finish === 'SAFETY' || $finish === 'RECITATION') {
            throw GeminiBriefingException::blocked($finish);
        }
        if ($finish === 'MAX_TOKENS') {
            throw GeminiBriefingException::outputTruncated();
        }

        $content = $first['content'] ?? null;
        if (!is_array($content)) {
            throw GeminiBriefingException::badResponse();
        }

        $parts = $content['parts'] ?? null;
        if (!is_array($parts)) {
            throw GeminiBriefingException::badResponse();
        }

        $text = '';
        foreach ($parts as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];
            }
        }

        $text = trim($text);
        if ($text === '') {
            throw GeminiBriefingException::emptyResponse();
        }

        return $text;
    }
}
