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
 * Single-pass only: builder context uses XML entries (up to {@see MarkdownBriefingFormatter::ENTRY_BODY_MAX_CHARS} chars of body per item).
 */
final class GeminiBriefingService
{
    /**
     * App-owned output contract (not user-editable). `{itemCount}` and
     * `{markdownContext}` are substituted before the Gemini call.
     */
    private const BRIEFING_OUTPUT_CONTRACT = <<<'CONTRACT'
SYSTEM DIRECTIVE — STRICT COMPLIANCE REQUIRED:
You are the backend engine for the Seismo AI Briefing Builder.

1. SEPARATION OF CONCERNS:
   - The prompt above defines your PERSONA, TONE, and OPTIONAL WRAPPERS (intro, radar, outro, headings). Follow it creatively.
   - The rules below are absolute platform constraints. They override the creative prompt when they conflict.

2. ENTRIES_DATA SHAPE:
   - Source rows are XML <entry> blocks. Each has <id>entry_type:entry_id</id> (e.g. feed_item:123).
   - Only cite IDs that appear in ENTRIES_DATA <id> elements. Never invent IDs.

3. CORE ITEMS:
   - Extract and detail up to {itemCount} separate core developments from ENTRIES_DATA (relevance order).
   - Target exactly {effectiveItemCount} core items in briefing_markdown and in used_entry_keys when enough distinct entries exist.
   - If ENTRIES_DATA contains fewer than {effectiveItemCount} entries, use every available entry — do not invent rows.
   - Do not merge multiple distinct entries into one bullet if that would drop below the number of entries you can cite.

4. DRAFTING (JSON field drafting_thoughts — not shown to users):
   - Before briefing_markdown, list exactly the {effectiveItemCount} entry_type:entry_id keys you will cite (one per line), taken only from <id> values in ENTRIES_DATA.
   - used_entry_keys must match that list in the same order.

5. CITATIONS:
   - Each core item must map to one <entry> in ENTRIES_DATA.
   - Populate used_entry_keys with the exact entry_type:entry_id strings from the chosen <id> values, in the same order as the core items in briefing_markdown.

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
        int $contextEntryCount = 0,
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
        $payload = $this->buildPayload($userSystemPrompt, $markdownContext, $itemCount, $contextEntryCount, true);

        $response = $this->postWithRetries($url, $payload, $apiKey);

        if (!$response->isOk() && $this->shouldRetryWithoutResponseSchema($response)) {
            error_log(
                'GeminiBriefingService: responseSchema rejected for model ' . $this->model . '; retrying without schema'
            );
            $payload  = $this->buildPayload($userSystemPrompt, $markdownContext, $itemCount, $contextEntryCount, false);
            $response = $this->postWithRetries($url, $payload, $apiKey);
        }

        if (!$response->isOk()) {
            throw $this->exceptionFromFailedResponse($response);
        }

        return $this->parseBriefingResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(
        string $userSystemPrompt,
        string $markdownContext,
        int $itemCount,
        int $contextEntryCount,
        bool $useStructuredSchema,
    ): array {
        $markdownContext  = trim($markdownContext);
        $effectiveCount   = $this->effectiveCitationCount($itemCount, $contextEntryCount);
        $systemText       = $this->composeSystemInstruction(
            $userSystemPrompt,
            $markdownContext,
            $itemCount,
            $effectiveCount,
        );
        $userText = 'Erstelle das Briefing gemäss den System Instructions und dem Output Contract. '
            . 'Fülle zuerst drafting_thoughts mit den exakten entry_type:entry_id-Werten aus ENTRIES_DATA <id>, '
            . 'dann briefing_markdown, dann used_entry_keys (gleiche Reihenfolge). '
            . 'Du MUSST ' . $effectiveCount . ' Kern-Items mit passenden used_entry_keys liefern '
            . '(sofern genügend <entry>-Blöcke in ENTRIES_DATA vorhanden sind; sonst alle verfügbaren).';

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
            'generationConfig' => $this->generationConfig($itemCount, $effectiveCount, $useStructuredSchema),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function generationConfig(int $itemCount, int $effectiveCount, bool $useStructuredSchema): array
    {
        $config = [
            'temperature'      => self::DEFAULT_TEMPERATURE,
            'maxOutputTokens'  => $this->maxOutputTokens,
            'responseMimeType' => 'application/json',
        ];
        if ($useStructuredSchema) {
            $config['responseSchema'] = $this->briefingResponseSchema($itemCount, $effectiveCount);
        }

        return $config;
    }

    private function shouldRetryWithoutResponseSchema(Response $response): bool
    {
        if ($response->status !== 400) {
            return false;
        }

        $body = strtolower($response->body);

        return str_contains($body, 'response_schema')
            || str_contains($body, 'responseschema')
            || str_contains($body, 'response schema');
    }

    private function effectiveCitationCount(int $itemCount, int $contextEntryCount): int
    {
        if ($contextEntryCount < 1) {
            return max(1, $itemCount);
        }

        return max(1, min($itemCount, $contextEntryCount));
    }

    /**
     * @return array<string, mixed>
     */
    private function briefingResponseSchema(int $itemCount, int $effectiveCount): array
    {
        return [
            'type'       => 'OBJECT',
            'properties' => [
                'drafting_thoughts' => [
                    'type'        => 'STRING',
                    'description' => 'Before briefing_markdown: list exactly ' . $effectiveCount
                        . ' entry_type:entry_id keys (one per line) copied from ENTRIES_DATA <id> elements only. '
                        . 'No prose, no invented IDs.',
                ],
                'briefing_markdown' => [
                    'type'        => 'STRING',
                    'description' => 'Complete briefing Markdown: follow the user persona above; include optional intro/outro; '
                        . 'must contain ' . $effectiveCount . ' distinct core items (one cited entry each) when data allows, '
                        . 'up to ' . $itemCount . ' requested.',
                ],
                'used_entry_keys' => [
                    'type'        => 'ARRAY',
                    'description' => 'Exact entry_type:entry_id strings from ENTRIES_DATA <id> for each core item, '
                        . 'same keys and order as drafting_thoughts and briefing_markdown core items.',
                    'items'       => ['type' => 'STRING'],
                    'minItems'    => $effectiveCount,
                    'maxItems'    => $effectiveCount,
                ],
            ],
            'required' => ['drafting_thoughts', 'briefing_markdown', 'used_entry_keys'],
        ];
    }

    private function composeSystemInstruction(
        string $userSystemPrompt,
        string $markdownContext,
        int $itemCount,
        int $effectiveItemCount,
    ): string {
        $envelope = str_replace(
            ['{itemCount}', '{effectiveItemCount}'],
            [(string)$itemCount, (string)$effectiveItemCount],
            self::BRIEFING_OUTPUT_CONTRACT,
        );
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

        $rawKeys = $decoded['used_entry_keys'] ?? null;
        $keys    = $this->normalizeUsedEntryKeys($rawKeys);
        if ($keys === [] && is_array($rawKeys) && $rawKeys !== []) {
            error_log('GeminiBriefingService: used_entry_keys present but none passed validation');
        }

        return new GeminiBriefingResult(
            $markdown,
            $keys,
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
