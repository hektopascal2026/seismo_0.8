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
 */
final class GeminiBriefingService
{
    /** Override via `system_config` key `gemini:model`. */
    public const CONFIG_KEY_MODEL = 'gemini:model';

    /** Stable alias; 1.5 models are shut down on the Gemini API (see Google changelog). */
    public const DEFAULT_MODEL = 'gemini-2.5-flash';

    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private const HTTP_TIMEOUT_SECONDS = 120;

    private readonly string $model;

    public function __construct(
        private readonly SystemConfigRepository $config,
        private readonly BaseClient $http = new BaseClient(self::HTTP_TIMEOUT_SECONDS),
    ) {
        $configured = trim((string)($config->get(self::CONFIG_KEY_MODEL) ?? ''));
        $this->model  = $configured !== '' ? $configured : self::DEFAULT_MODEL;
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

        $url = self::API_BASE . rawurlencode($this->model) . ':generateContent';

        $payload = [
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [
                        ['text' => $this->buildUserMessage($systemPrompt, $markdownContext)],
                    ],
                ],
            ],
        ];

        try {
            $response = $this->http->postJson($url, $payload, [
                'x-goog-api-key' => $apiKey,
            ]);
        } catch (HttpClientException $e) {
            error_log('GeminiBriefingService transport: ' . $e->getMessage());
            throw GeminiBriefingException::transportFailed();
        } catch (\JsonException $e) {
            error_log('GeminiBriefingService request JSON: ' . $e->getMessage());
            throw GeminiBriefingException::transportFailed();
        }

        if (!$response->isOk()) {
            throw $this->exceptionFromFailedResponse($response);
        }

        return $this->extractText($response);
    }

    /**
     * @throws GeminiBriefingException
     */
    private function exceptionFromFailedResponse(Response $response): GeminiBriefingException
    {
        $apiMessage = $this->parseApiErrorMessage($response);
        error_log(
            'GeminiBriefingService HTTP ' . $response->status
            . ' model=' . $this->model
            . ($apiMessage !== '' ? ': ' . $apiMessage : ': ' . substr($response->body, 0, 500))
        );

        if ($response->status === 404) {
            return GeminiBriefingException::modelNotFound($this->model, $apiMessage);
        }

        if ($apiMessage !== '') {
            return GeminiBriefingException::fromApiMessage($response->status, $apiMessage);
        }

        return GeminiBriefingException::fromHttpStatus($response->status);
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

    private function buildUserMessage(string $systemPrompt, string $markdownContext): string
    {
        return $systemPrompt
            . "\n\n---\n\n"
            . "Below is a Seismo briefing export (Markdown). Summarize according to the instructions above.\n\n"
            . trim($markdownContext);
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
