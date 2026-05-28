<?php

declare(strict_types=1);

namespace Seismo\Service;

use Seismo\Controller\SettingsController;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Service\Http\BaseClient;
use Seismo\Service\Http\HttpClientException;
use Seismo\Service\Http\Response;

/**
 * Reformulates rough user intent into a Seismo AI Researcher prompt (single Gemini call).
 */
final class ResearcherPromptHelperService
{
    public const MIN_INTENT_LEN = 10;

    public const MAX_INTENT_LEN = 8000;

    private const MAX_OUTPUT_TOKENS = 16384;

    private const HTTP_TIMEOUT_SECONDS = 90;

    private const MAX_RETRIES = 2;

    private const RETRY_BACKOFF_SECONDS = 2.0;

    /** @var list<int> */
    private const TRANSIENT_HTTP_STATUSES = [500, 502, 503, 504];

    private const HELPER_SYSTEM_INSTRUCTION = <<<'PROMPT'
You are a prompt engineer for Seismo's AI Researcher.

The operator will provide:
1) BRIEFING_INTENT — rough notes on what the next executive researcher should focus on.
2) STYLE_REFERENCE — the current default researcher prompt for this desk (tone, structure, two-pass rules).

Your task: write ONE complete replacement researcher prompt the operator can paste into Seismo.

Requirements for the output prompt:
- Match STYLE_REFERENCE in language (usually German), Economist-style executive tone, and overall rigor unless BRIEFING_INTENT explicitly asks for another language.
- Preserve the two-pass workflow from STYLE_REFERENCE when present: PHASE 1 = JSON only (used_entry_keys, optional selection_reasoning); PHASE 2 = Markdown executive researcher only.
- Keep mandatory citation rules: each core item cites entry_type:entry_id in parentheses, e.g. (feed_item:123).
- Encode BRIEFING_INTENT as topic focus, jurisdictions, source-type bias (e.g. prefer lex_item), and exclusion rules.
- Refer to "Number of items" / item count from the UI where STYLE_REFERENCE does — do not hard-code a number unless the intent specifies one.
- Do NOT include ENTRIES_DATA, {markdownContext}, XML entry blocks, or instructions to paste Seismo rows — the app injects those.
- Do NOT wrap the answer in markdown code fences or add meta commentary ("Here is your prompt").

Output ONLY the researcher prompt text.
PROMPT;

    private readonly string $model;

    public function __construct(
        private readonly SystemConfigRepository $config,
        private readonly BaseClient $http = new BaseClient(self::HTTP_TIMEOUT_SECONDS),
    ) {
        $configured = trim((string)($config->get(GeminiResearcherService::CONFIG_KEY_MODEL) ?? ''));
        $model      = $configured !== '' ? $configured : GeminiResearcherService::DEFAULT_MODEL;
        if (!GeminiResearcherService::usesGemini35Family($model)) {
            error_log(
                'ResearcherPromptHelperService: unsupported gemini:model "' . $model . '"; using '
                . GeminiResearcherService::DEFAULT_MODEL
            );
            $model = GeminiResearcherService::DEFAULT_MODEL;
        }
        $this->model = $model;
    }

    /**
     * @throws GeminiResearcherException
     */
    public function reformulate(string $intent, string $styleReference): string
    {
        $intent = trim($intent);
        if (mb_strlen($intent) < self::MIN_INTENT_LEN) {
            throw GeminiResearcherException::invalidInput(
                'Describe what you want in at least ' . self::MIN_INTENT_LEN . ' characters.'
            );
        }
        if (mb_strlen($intent) > self::MAX_INTENT_LEN) {
            throw GeminiResearcherException::invalidInput(
                'Intent is too long (maximum ' . self::MAX_INTENT_LEN . ' characters).'
            );
        }

        $styleReference = trim($styleReference);
        if ($styleReference === '') {
            throw GeminiResearcherException::invalidInput('Style reference prompt is empty.');
        }

        $apiKey = trim((string)($this->config->get(SettingsController::KEY_GEMINI_API_KEY) ?? ''));
        if ($apiKey === '') {
            throw GeminiResearcherException::missingApiKey();
        }

        $userMessage = "BRIEFING_INTENT:\n" . $intent . "\n\nSTYLE_REFERENCE:\n" . $styleReference;

        $payload = [
            'systemInstruction' => ['parts' => [['text' => self::HELPER_SYSTEM_INSTRUCTION]]],
            'contents'          => [['role' => 'user', 'parts' => [['text' => $userMessage]]]],
            'generationConfig'  => [
                'maxOutputTokens'  => min(
                    self::MAX_OUTPUT_TOKENS,
                    GeminiResearcherService::modelHardOutputCapFor($this->model),
                ),
                'responseMimeType' => 'text/plain',
                'thinkingConfig'   => ['thinkingLevel' => 'MINIMAL'],
            ],
        ];

        $url      = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode($this->model) . ':generateContent';
        $response = $this->postWithRetries($url, $payload, $apiKey);
        if (!$response->isOk()) {
            throw $this->exceptionFromFailedResponse($response);
        }

        $text = self::stripWrappingFences($this->extractCandidateText($response));
        if ($text === '') {
            throw GeminiResearcherException::emptyResponse('Gemini returned no prompt text.');
        }

        return $text;
    }

    public static function stripWrappingFences(string $text): string
    {
        $text = trim($text);
        if (preg_match('/^```(?:\w+)?\s*\n(.*)\n```\s*$/s', $text, $m) === 1) {
            return trim($m[1]);
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $payload
     * @throws GeminiResearcherException
     */
    private function postWithRetries(string $url, array $payload, string $apiKey): Response
    {
        $headers  = ['x-goog-api-key' => $apiKey];
        $attempts = self::MAX_RETRIES + 1;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                $response = $this->http->postJson($url, $payload, $headers);
            } catch (HttpClientException $e) {
                if ($attempt >= $attempts - 1) {
                    error_log('ResearcherPromptHelperService transport: ' . $e->getMessage());
                    throw GeminiResearcherException::transportFailed();
                }
                usleep((int)(self::RETRY_BACKOFF_SECONDS * ($attempt + 1) * 1_000_000));

                continue;
            } catch (\JsonException $e) {
                error_log('ResearcherPromptHelperService request JSON: ' . $e->getMessage());
                throw GeminiResearcherException::transportFailed();
            }

            if ($response->status === 429) {
                return $response;
            }

            if (in_array($response->status, self::TRANSIENT_HTTP_STATUSES, true) && $attempt < $attempts - 1) {
                usleep((int)(self::RETRY_BACKOFF_SECONDS * ($attempt + 1) * 1_000_000));

                continue;
            }

            return $response;
        }

        throw GeminiResearcherException::transportFailed();
    }

    /**
     * @throws GeminiResearcherException
     */
    private function extractCandidateText(Response $response): string
    {
        try {
            $json = $response->json();
        } catch (\JsonException $e) {
            throw GeminiResearcherException::badResponse('Could not parse API JSON.');
        }

        if (isset($json['error']) && is_array($json['error'])) {
            $msg = trim((string)($json['error']['message'] ?? ''));
            if ($msg !== '') {
                throw GeminiResearcherException::fromApiMessage(400, $msg);
            }

            throw GeminiResearcherException::badResponse('API returned an error.');
        }

        $candidates = $json['candidates'] ?? null;
        if (!is_array($candidates) || $candidates === []) {
            $block = $json['promptFeedback']['blockReason'] ?? null;
            if (is_string($block) && $block !== '') {
                throw GeminiResearcherException::blocked($block);
            }

            throw GeminiResearcherException::emptyResponse();
        }

        $first = $candidates[0];
        if (!is_array($first)) {
            throw GeminiResearcherException::badResponse('Invalid candidate.');
        }

        $finish = (string)($first['finishReason'] ?? '');
        if ($finish === 'SAFETY' || $finish === 'RECITATION') {
            throw GeminiResearcherException::blocked($finish);
        }

        $content = $first['content'] ?? null;
        if (!is_array($content)) {
            throw GeminiResearcherException::badResponse('Candidate missing content.');
        }

        $parts = $content['parts'] ?? null;
        if (!is_array($parts)) {
            throw GeminiResearcherException::badResponse('Candidate has no text parts.');
        }

        $text = '';
        foreach ($parts as $part) {
            if (!is_array($part) || !isset($part['text']) || !is_string($part['text'])) {
                continue;
            }
            if (($part['thought'] ?? false) === true) {
                continue;
            }
            $text .= $part['text'];
        }

        return trim($text);
    }

    /**
     * @throws GeminiResearcherException
     */
    private function exceptionFromFailedResponse(Response $response): GeminiResearcherException
    {
        $apiMessage = $this->parseApiErrorMessage($response);
        error_log(
            'ResearcherPromptHelperService HTTP ' . $response->status
            . ' model=' . $this->model
            . ($apiMessage !== '' ? ': ' . $apiMessage : '')
        );

        if ($response->status === 400 && $this->isInvalidApiKeyBody($response->body)) {
            return GeminiResearcherException::invalidApiKey();
        }

        if ($response->status === 404) {
            return GeminiResearcherException::modelNotFound($this->model, $apiMessage);
        }

        if ($apiMessage !== '') {
            return GeminiResearcherException::fromApiMessage($response->status, $apiMessage);
        }

        return GeminiResearcherException::fromHttpStatus($response->status);
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
}
