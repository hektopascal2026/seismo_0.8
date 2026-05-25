<?php

declare(strict_types=1);

namespace Seismo\Service;

use Seismo\Controller\SettingsController;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Service\Http\BaseClient;
use Seismo\Util\LenientJsonParser;
use Seismo\Service\Http\HttpClientException;
use Seismo\Service\Http\Response;

/**
 * Calls Google Gemini `generateContent` for the AI Briefing Builder.
 *
 * Single-pass only: entry markdown includes up to {@see MarkdownBriefingFormatter::ENTRY_BODY_MAX_CHARS} chars of body per item.
 */
final class GeminiBriefingService
{
    /**
     * App-owned output contract (not user-editable). `{itemCount}` and
     * `{markdownContext}` are substituted before the Gemini call.
     */
    private const BRIEFING_OUTPUT_CONTRACT = <<<'CONTRACT'
OUTPUT CONTRACT (platform — binding for item count and citations):
- Respond with a single JSON object only (no Markdown code fence): "briefing_markdown" (string) and "used_entry_keys" (array).
- Your prompt above defines tone, role, headings, and layout (optional intro, sections before/after, any titles). The platform fixes only the number of core items.
- CORE ITEMS: Include exactly {itemCount} core items sourced from ENTRIES_DATA — the main cited signals/developments, in relevance order. Each core item is one distinct entry (bullet, numbered point, or clearly separated block). Surrounding prose is free-form.
- used_entry_keys: JSON array of exactly {itemCount} strings — IDs of those core items only, in the same order as they appear in briefing_markdown (format entry_type:entry_id as in [ID: entry_type:entry_id] tags). The app shows validation cards for these IDs.
- Use only facts and sources from ENTRIES_DATA; do not invent entries or citations.

ENTRIES_DATA:
{markdownContext}
CONTRACT;

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
    public function generateSummary(
        string $userSystemPrompt,
        string $markdownContext,
        int $itemCount = 5,
    ): GeminiBriefingResult {
        $apiKey = trim((string)($this->config->get(SettingsController::KEY_GEMINI_API_KEY) ?? ''));
        if ($apiKey === '') {
            throw GeminiBriefingException::missingApiKey();
        }

        $userSystemPrompt = trim($userSystemPrompt);
        if ($userSystemPrompt === '') {
            throw GeminiBriefingException::invalidInput('System prompt is required.');
        }

        if ($itemCount < 1) {
            $itemCount = 5;
        }

        $url     = self::API_BASE . rawurlencode($this->model) . ':generateContent';
        $payload = $this->buildPayload($userSystemPrompt, $markdownContext, $itemCount);

        $response = $this->postWithRetries($url, $payload, $apiKey);

        if (!$response->isOk()) {
            throw $this->exceptionFromFailedResponse($response);
        }

        return $this->parseBriefingResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(string $userSystemPrompt, string $markdownContext, int $itemCount): array
    {
        $markdownContext = trim($markdownContext);
        $systemText      = $this->composeSystemInstruction($userSystemPrompt, $markdownContext, $itemCount);
        $userText        = 'Erstelle das Briefing gemäss den System Instructions und dem Output Contract.';

        return [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $systemText],
                ],
            ],
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [
                        ['text' => $userText],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature'      => self::DEFAULT_TEMPERATURE,
                'maxOutputTokens'  => $this->maxOutputTokens,
                'responseMimeType' => 'application/json',
            ],
        ];
    }

    private function composeSystemInstruction(
        string $userSystemPrompt,
        string $markdownContext,
        int $itemCount,
    ): string {
        $envelope = str_replace('{itemCount}', (string)$itemCount, self::BRIEFING_OUTPUT_CONTRACT);
        $combined = trim($userSystemPrompt) . "\n\n" . $envelope;

        if (str_contains($combined, '{markdownContext}')) {
            return str_replace('{markdownContext}', $markdownContext, $combined);
        }

        return $combined . "\n\nENTRIES_DATA:\n\n" . $markdownContext;
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
    private function parseBriefingResponse(Response $response): GeminiBriefingResult
    {
        $raw = $this->extractCandidateText($response);

        return $this->parseBriefingFromModelOutput($raw);
    }

    /**
     * @throws GeminiBriefingException
     */
    private function extractCandidateText(Response $response): string
    {
        try {
            $json = $response->json();
        } catch (\JsonException $e) {
            error_log('GeminiBriefingService response JSON: ' . $e->getMessage());
            throw GeminiBriefingException::badResponse('Could not parse API JSON: ' . $e->getMessage());
        }

        if (isset($json['error']) && is_array($json['error'])) {
            $msg = trim((string)($json['error']['message'] ?? ''));
            error_log('GeminiBriefingService API error: ' . ($msg !== '' ? $msg : 'unknown'));
            if ($msg !== '') {
                throw GeminiBriefingException::fromApiMessage(400, $msg);
            }

            throw GeminiBriefingException::badResponse('API returned an error object without a message.');
        }

        $candidates = $json['candidates'] ?? null;
        if (!is_array($candidates) || $candidates === []) {
            $block = $json['promptFeedback']['blockReason'] ?? null;
            if (is_string($block) && $block !== '') {
                throw GeminiBriefingException::blocked($block);
            }

            throw GeminiBriefingException::emptyResponse('API response had no candidates.');
        }

        $first = $candidates[0];
        if (!is_array($first)) {
            throw GeminiBriefingException::badResponse('First candidate is not a valid object.');
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
            throw GeminiBriefingException::badResponse(
                'Candidate missing content'
                . ($finish !== '' ? ' (finish: ' . $finish . ').' : '.')
            );
        }

        $parts = $content['parts'] ?? null;
        if (!is_array($parts)) {
            throw GeminiBriefingException::badResponse('Candidate content has no text parts.');
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

    /**
     * @throws GeminiBriefingException
     */
    private function parseBriefingFromModelOutput(string $raw): GeminiBriefingResult
    {
        $decoded = $this->decodeBriefingJsonObject($raw);
        if ($decoded !== null) {
            return $this->briefingResultFromDecoded($decoded, true);
        }

        $jsonText = LenientJsonParser::extractMarkdownJson($raw);
        $salvaged = $this->salvageBriefingFromBrokenJson($jsonText);
        if ($salvaged !== null) {
            error_log('GeminiBriefingService briefing: recovered markdown without valid JSON attribution.');

            return $salvaged;
        }

        if (!str_contains($raw, '"briefing_markdown"')) {
            return new GeminiBriefingResult(trim($raw), [], false);
        }

        throw GeminiBriefingException::badResponse(
            'Briefing JSON could not be parsed or repaired.'
        );
    }

    /**
     * Strict json_decode first, then tourdesuisse-style lenient repair pipeline.
     *
     * @return array<string, mixed>|null
     */
    private function decodeBriefingJsonObject(string $raw): ?array
    {
        $jsonText = LenientJsonParser::extractMarkdownJson($raw);

        try {
            $decoded = json_decode($jsonText, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException $e) {
            error_log('GeminiBriefingService briefing JSON strict parse: ' . $e->getMessage());
        }

        $repaired = LenientJsonParser::parseObject($raw);
        if ($repaired !== null) {
            error_log('GeminiBriefingService briefing JSON repaired via lenient parser.');
        }

        return $repaired;
    }

    /**
     * @param array<string, mixed> $decoded
     * @throws GeminiBriefingException
     */
    private function briefingResultFromDecoded(array $decoded, bool $attributionParsed): GeminiBriefingResult
    {
        $markdown = trim((string)($decoded['briefing_markdown'] ?? ''));
        if ($markdown === '') {
            throw GeminiBriefingException::emptyResponse(
                'JSON response is missing a non-empty briefing_markdown field.'
            );
        }

        return new GeminiBriefingResult(
            $markdown,
            $this->normalizeUsedEntryKeys($decoded['used_entry_keys'] ?? null),
            $attributionParsed,
        );
    }

    /**
     * Recover briefing text when JSON mode returns malformed JSON; skip attribution cards.
     */
    private function salvageBriefingFromBrokenJson(string $jsonText): ?GeminiBriefingResult
    {
        $needle = '"briefing_markdown"';
        $pos    = strpos($jsonText, $needle);
        if ($pos === false) {
            return null;
        }

        $after = substr($jsonText, $pos + strlen($needle));
        if (!preg_match('/^\s*:\s*"/', $after)) {
            return null;
        }

        $after = (string)preg_replace('/^\s*:\s*"/', '', $after, 1);
        $markdown = $this->readJsonStringLiteral($after);
        if ($markdown === null || trim($markdown) === '') {
            return null;
        }

        return new GeminiBriefingResult($markdown, [], false);
    }

    /**
     * Read a JSON double-quoted string from the start of $input (handles escapes; tolerates truncation).
     */
    private function readJsonStringLiteral(string $input): ?string
    {
        $len = strlen($input);
        if ($len === 0) {
            return null;
        }

        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $c = $input[$i];
            if ($c === '"') {
                $backslashes = 0;
                for ($j = $i - 1; $j >= 0 && $input[$j] === '\\'; $j--) {
                    $backslashes++;
                }
                if ($backslashes % 2 === 0) {
                    break;
                }
            }
            if ($c === '\\' && $i + 1 < $len) {
                $next = $input[$i + 1];
                $out .= match ($next) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    '"' => '"',
                    '\\' => '\\',
                    default => $next,
                };
                $i++;

                continue;
            }
            $out .= $c;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function normalizeUsedEntryKeys(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $keys = [];
        foreach ($raw as $item) {
            if (!is_string($item)) {
                continue;
            }
            $key = trim($item);
            if ($key === '' || !preg_match('/^[a-z][a-z0-9_]*:\d+$/', $key)) {
                continue;
            }
            $keys[] = $key;
        }

        return $keys;
    }

}
