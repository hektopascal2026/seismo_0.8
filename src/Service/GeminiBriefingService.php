<?php

declare(strict_types=1);

namespace Seismo\Service;

use Seismo\Controller\SettingsController;
use Seismo\Formatter\MarkdownBriefingFormatter;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Service\Http\BaseClient;
use Seismo\Util\LenientJsonParser;
use Seismo\Service\Http\HttpClientException;
use Seismo\Service\Http\Response;

/**
 * Calls Google Gemini `generateContent` for the AI Briefing Builder.
 *
 * Default: single-pass (selection + prose in one call, thinking off, scaled output tokens).
 * Optional two-pass: capped thinking for entry selection, then prose on selected entries only.
 */
final class GeminiBriefingService
{
    private const SINGLE_PASS_OUTPUT_CONTRACT = <<<'CONTRACT'
SYSTEM DIRECTIVE — STRICT COMPLIANCE REQUIRED:
You are the backend engine for the Seismo AI Briefing Builder.

1. SEPARATION OF CONCERNS:
   - The prompt above defines your PERSONA, TONE, and OPTIONAL WRAPPERS (intro, radar, outro, headings). Follow it creatively.
   - The rules below are absolute platform constraints. They override the creative prompt when they conflict.

2. ENTRIES_DATA SHAPE:
   - Source rows are XML <entry> blocks. Each has <id>entry_type:entry_id</id> (e.g. feed_item:123).
   - Only cite IDs that appear in ENTRIES_DATA <id> elements. Never invent IDs.

3. CORE ITEMS:
   - Extract and detail UP TO {itemCount} separate core developments from ENTRIES_DATA (relevance order).
   - Include at most {maxCoreItems} core items in briefing_markdown and used_entry_keys.
   - When the user prompt imposes strict inclusion criteria (e.g. only named companies or spokesperson quotes), return FEWER items rather than padding with loosely related news. Never cite an entry that does not satisfy the user prompt.
   - If ENTRIES_DATA contains fewer than {maxCoreItems} entries, use only qualifying entries — do not invent rows.
   - Do not merge multiple distinct qualifying entries into one bullet if that would omit a separate core development you are citing.

4. DRAFTING (JSON field drafting_thoughts — not shown to users):
   - Before briefing_markdown, list the entry_type:entry_id keys you will cite (one per line, up to {maxCoreItems}), taken only from <id> values in ENTRIES_DATA that satisfy the user prompt.
   - used_entry_keys must match that list in the same order.

5. CITATIONS:
   - Each core item must map to one <entry> in ENTRIES_DATA.
   - Populate used_entry_keys with the exact entry_type:entry_id strings from the chosen <id> values, in the same order as the core items in briefing_markdown.

ENTRIES_DATA:
{markdownContext}
CONTRACT;

    private const SELECTION_OUTPUT_CONTRACT = <<<'CONTRACT'
SYSTEM DIRECTIVE — ENTRY SELECTION (PASS 1 OF 2):
You choose which entries merit inclusion in an executive briefing. The user persona and prose style apply in pass 2; here you judge strategic fit only.

RULES:
- ENTRIES_DATA contains XML <entry> blocks sorted by Seismo relevance (highest first). Each has <id>entry_type:entry_id</id>.
- Select UP TO {maxCoreItems} distinct entries that satisfy the user system prompt (audience, jurisdictions, topics, named entities). Selecting fewer is correct when strict criteria apply — do not pad with high-relevance entries that fail the prompt.
- Prefer entries that are timely, non-redundant, and fit the user system prompt. Use <jurisdiction> and <source_type> when the prompt targets specific countries or legal corpora.
- used_entry_keys must list the chosen <id> values in briefing order (most important first).
- selection_notes: one short sentence per chosen entry (why it made the cut). Not shown to end users.
- Never invent IDs. Only keys from ENTRIES_DATA <id> elements.

ENTRIES_DATA:
{markdownContext}
CONTRACT;

    private const SUMMARY_OUTPUT_CONTRACT = <<<'CONTRACT'
SYSTEM DIRECTIVE — BRIEFING PROSE (PASS 2 OF 2):
The entries for this briefing are already chosen. Write the full executive briefing in briefing_markdown only.

RULES:
- SELECTED_ENTRIES_DATA lists exactly the entries you must cover — one core item per entry, in the same order as SELECTED_ENTRY_KEYS.
- Each core item must cite its entry_type:entry_id (e.g. in parentheses: feed_item:123) matching SELECTED_ENTRY_KEYS.
- Do not add entries that are not in SELECTED_ENTRY_KEYS. Do not skip any listed entry.
- Follow the user persona above for tone, structure, intro/outro, and headings.

SELECTED_ENTRY_KEYS (ordered):
{selectedEntryKeys}

SELECTED_ENTRIES_DATA:
{markdownContext}
CONTRACT;

    /** Override via `system_config` key `gemini:model`. */
    public const CONFIG_KEY_MODEL = 'gemini:model';

    /** Optional `system_config` override for {@see DEFAULT_MAX_OUTPUT_TOKENS}. */
    public const CONFIG_KEY_MAX_OUTPUT_TOKENS = 'gemini:max_output_tokens';

    public const DEFAULT_MODEL = 'gemini-2.5-flash';

    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private const HTTP_TIMEOUT_SECONDS = 120;

    private const HTTP_TIMEOUT_TWO_PASS_SECONDS = 180;

    private const DEFAULT_TEMPERATURE = 0.2;

    private const OUTPUT_TOKEN_CAP = 32768;

    private const DEFAULT_MAX_OUTPUT_TOKENS = 8192;

    private const OUTPUT_TOKEN_FLOOR = 4096;

    private const OUTPUT_TOKENS_PER_ITEM = 1000;

    private const THINKING_BUDGET_BASE = 512;

    private const THINKING_BUDGET_PER_ITEM = 256;

    private const THINKING_BUDGET_CAP = 4096;

    /** Candidate pool size for pass 1 (× requested items). */
    private const SELECTION_POOL_MULTIPLIER = 3;

    private const SELECTION_VISIBLE_TOKENS_BASE = 768;

    private const SELECTION_VISIBLE_TOKENS_PER_ITEM = 96;

    private const DEFAULT_MAX_RETRIES = 4;

    private const RETRY_BACKOFF_SECONDS = 2.0;

    /** @var list<int> */
    private const TRANSIENT_HTTP_STATUSES = [429, 500, 502, 503, 504];

    private readonly string $model;

    private readonly int $maxOutputTokens;

    private int $lastEffectiveCitationCount = 1;

    public function __construct(
        private readonly SystemConfigRepository $config,
        private readonly BaseClient $http = new BaseClient(self::HTTP_TIMEOUT_SECONDS),
    ) {
        $configured = trim((string)($config->get(self::CONFIG_KEY_MODEL) ?? ''));
        $this->model  = $configured !== '' ? $configured : self::DEFAULT_MODEL;

        $rawTokens = trim((string)($config->get(self::CONFIG_KEY_MAX_OUTPUT_TOKENS) ?? ''));
        if ($rawTokens !== '' && ctype_digit($rawTokens)) {
            $this->maxOutputTokens = max(256, min(self::OUTPUT_TOKEN_CAP, (int)$rawTokens));
        } else {
            $this->maxOutputTokens = self::DEFAULT_MAX_OUTPUT_TOKENS;
        }
    }

    public static function resolveThinkingBudget(int $itemCount): int
    {
        return min(
            self::THINKING_BUDGET_CAP,
            self::THINKING_BUDGET_BASE + max(1, $itemCount) * self::THINKING_BUDGET_PER_ITEM,
        );
    }

    /** Pass 2 visible output budget (thinking disabled). */
    public static function resolveOutputTokenBudget(int $itemCount, int $configuredMax): int
    {
        $configuredMax = max(256, min(self::OUTPUT_TOKEN_CAP, $configuredMax));
        $scaled        = 2048 + max(1, $itemCount) * self::OUTPUT_TOKENS_PER_ITEM;

        return min(self::OUTPUT_TOKEN_CAP, max($configuredMax, self::OUTPUT_TOKEN_FLOOR, $scaled));
    }

    /** Pass 1 total maxOutputTokens (thinking + small JSON envelope share one budget on 2.5 Flash). */
    public static function resolveSelectionPassTokenBudget(int $itemCount, int $configuredMax): int
    {
        $thinking = self::resolveThinkingBudget($itemCount);
        $visible  = self::SELECTION_VISIBLE_TOKENS_BASE + max(1, $itemCount) * self::SELECTION_VISIBLE_TOKENS_PER_ITEM;

        return min(self::OUTPUT_TOKEN_CAP, max($configuredMax, $thinking + $visible));
    }

    /**
     * @param list<array<string, mixed>> $contextEntries  Shaped Magnitu rows (relevance-sorted).
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $briefingMeta         Passed to {@see MarkdownBriefingFormatter::format}.
     * @throws GeminiBriefingException
     */
    public function generateSummary(
        string $userSystemPrompt,
        string $markdownContext,
        int $itemCount = 5,
        int $contextEntryCount = 0,
        array $contextEntries = [],
        array $scoresByKey = [],
        array $briefingMeta = [],
        bool $twoPass = false,
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

        $effectiveCount = $this->effectiveCitationCount($itemCount, $contextEntryCount);
        $this->lastEffectiveCitationCount = $effectiveCount;

        if (!$twoPass) {
            return $this->generateSummarySinglePass(
                $userSystemPrompt,
                $markdownContext,
                $itemCount,
                $effectiveCount,
                $apiKey,
            );
        }

        return $this->generateSummaryTwoPass(
            $userSystemPrompt,
            $markdownContext,
            $itemCount,
            $effectiveCount,
            $contextEntries,
            $scoresByKey,
            $briefingMeta,
            $apiKey,
        );
    }

    /**
     * @throws GeminiBriefingException
     */
    private function generateSummarySinglePass(
        string $userSystemPrompt,
        string $markdownContext,
        int $itemCount,
        int $effectiveCount,
        string $apiKey,
    ): GeminiBriefingResult {
        $systemText = $this->composeSinglePassSystemInstruction(
            $userSystemPrompt,
            trim($markdownContext),
            $itemCount,
            $effectiveCount,
        );
        $userText = 'Erstelle das Briefing gemäss den System Instructions und dem Output Contract. '
            . 'Fülle zuerst drafting_thoughts mit den entry_type:entry_id-Werten aus ENTRIES_DATA <id>, '
            . 'dann briefing_markdown, dann used_entry_keys (gleiche Reihenfolge). '
            . 'Liefere bis zu ' . $effectiveCount . ' Kern-Items mit passenden used_entry_keys, '
            . 'nur aus Einträgen, die den User-Prompt erfüllen; wenn weniger passen, liefere weniger — kein Auffüllen.';

        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemText]]],
            'contents'          => [['role' => 'user', 'parts' => [['text' => $userText]]]],
            'generationConfig'  => $this->singlePassGenerationConfig($itemCount, $effectiveCount, true),
        ];

        $response = $this->postPayloadWithSchemaFallback($payload, $apiKey, 'single');

        return $this->parseSinglePassResponse($response);
    }

    /**
     * @param list<array<string, mixed>> $contextEntries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $briefingMeta
     * @throws GeminiBriefingException
     */
    private function generateSummaryTwoPass(
        string $userSystemPrompt,
        string $markdownContext,
        int $itemCount,
        int $effectiveCount,
        array $contextEntries,
        array $scoresByKey,
        array $briefingMeta,
        string $apiKey,
    ): GeminiBriefingResult {
        $poolEntries = $this->selectionPoolEntries($contextEntries, $effectiveCount);
        $poolContext = $this->buildEntryXmlContext($poolEntries, $scoresByKey, $briefingMeta, $markdownContext);
        $poolCount    = $poolEntries !== [] ? count($poolEntries) : $this->countXmlEntries($poolContext);
        $selectionTarget = min($effectiveCount, max(1, $poolCount));

        $selectedKeys = $this->runSelectionPass($poolContext, $itemCount, $selectionTarget, $apiKey);
        $selectedKeys = $this->finalizeSelectedKeys($selectedKeys, $selectionTarget);

        $summaryEntries = $this->entriesForKeys($contextEntries, $selectedKeys);
        if ($summaryEntries === []) {
            $summaryContext = $this->filterXmlContextByKeys($markdownContext, $selectedKeys);
        } else {
            $summaryMeta              = $briefingMeta;
            $summaryMeta['total']     = count($summaryEntries);
            $summaryMeta['selected']  = count($selectedKeys);
            $summaryContext           = $this->buildEntryXmlContext(
                $summaryEntries,
                $scoresByKey,
                $summaryMeta,
                $markdownContext,
            );
        }

        $finalCount = count($selectedKeys);
        $this->lastEffectiveCitationCount = max(1, $finalCount);

        return $this->runSummaryPass(
            $userSystemPrompt,
            $summaryContext,
            $selectedKeys,
            $itemCount,
            $finalCount,
            $apiKey,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function singlePassGenerationConfig(int $itemCount, int $effectiveCount, bool $useStructuredSchema): array
    {
        $config = [
            'temperature'     => self::DEFAULT_TEMPERATURE,
            'maxOutputTokens' => self::resolveOutputTokenBudget($itemCount, $this->maxOutputTokens),
            'thinkingConfig'  => [
                'thinkingBudget' => 0,
            ],
            'responseMimeType' => 'application/json',
        ];
        if ($useStructuredSchema) {
            $config['responseSchema'] = $this->singlePassResponseSchema($itemCount, $effectiveCount);
        }

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function singlePassResponseSchema(int $itemCount, int $effectiveCount): array
    {
        return [
            'type'       => 'OBJECT',
            'properties' => [
                'drafting_thoughts' => [
                    'type'        => 'STRING',
                    'description' => 'Before briefing_markdown: list up to ' . $effectiveCount
                        . ' entry_type:entry_id keys (one per line) copied from ENTRIES_DATA <id> elements that satisfy the user prompt.',
                ],
                'briefing_markdown' => [
                    'type'        => 'STRING',
                    'description' => 'Complete briefing Markdown with up to ' . $effectiveCount
                        . ' distinct core items (one cited entry each), at most ' . $itemCount . ' requested.',
                ],
                'used_entry_keys' => [
                    'type'        => 'ARRAY',
                    'description' => 'Exact entry_type:entry_id strings for each core item, same order as briefing_markdown.',
                    'items'       => ['type' => 'STRING'],
                    'minItems'    => 0,
                    'maxItems'    => $effectiveCount,
                ],
            ],
            'required' => ['drafting_thoughts', 'briefing_markdown', 'used_entry_keys'],
        ];
    }

    private function composeSinglePassSystemInstruction(
        string $userSystemPrompt,
        string $markdownContext,
        int $itemCount,
        int $effectiveItemCount,
    ): string {
        $envelope = str_replace(
            ['{itemCount}', '{maxCoreItems}', '{markdownContext}'],
            [(string)$itemCount, (string)$effectiveItemCount, $markdownContext],
            self::SINGLE_PASS_OUTPUT_CONTRACT,
        );
        $combined = trim($userSystemPrompt) . "\n\n" . $envelope;

        if (str_contains($combined, '{markdownContext}')) {
            return str_replace('{markdownContext}', $markdownContext, $combined);
        }

        return $combined;
    }

    /**
     * @throws GeminiBriefingException
     */
    private function parseSinglePassResponse(Response $response): GeminiBriefingResult
    {
        $raw     = $this->extractCandidateText($response);
        $decoded = $this->decodeBriefingJsonObject($raw);
        if ($decoded !== null) {
            $markdown = trim((string)($decoded['briefing_markdown'] ?? ''));
            if ($markdown === '') {
                throw GeminiBriefingException::emptyResponse(
                    'JSON response is missing a non-empty briefing_markdown field.'
                );
            }

            $keys = $this->normalizeUsedEntryKeys($decoded['used_entry_keys'] ?? null);
            $result = new GeminiBriefingResult($markdown, $keys, true);
            $this->assertBriefingNotTruncated($result, $this->lastEffectiveCitationCount);

            return $result;
        }

        $jsonText = LenientJsonParser::extractMarkdownJson($raw);
        $salvaged = $this->salvageBriefingFromBrokenJson($jsonText);
        if ($salvaged !== null) {
            error_log('GeminiBriefingService single-pass: recovered markdown without valid JSON attribution.');
            $this->assertBriefingNotTruncated($salvaged, $this->lastEffectiveCitationCount);

            return $salvaged;
        }

        if (!str_contains($raw, '"briefing_markdown"')) {
            $fallback = new GeminiBriefingResult(trim($raw), [], false);
            $this->assertBriefingNotTruncated($fallback, $this->lastEffectiveCitationCount);

            return $fallback;
        }

        throw GeminiBriefingException::badResponse('Briefing JSON could not be parsed or repaired.');
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private function selectionPoolEntries(array $entries, int $effectiveCount): array
    {
        if ($entries === []) {
            return [];
        }

        $cap = max($effectiveCount + 2, $effectiveCount * self::SELECTION_POOL_MULTIPLIER);
        $cap = min(count($entries), $cap);

        return array_slice($entries, 0, $cap);
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $meta
     */
    private function buildEntryXmlContext(
        array $entries,
        array $scoresByKey,
        array $meta,
        string $fallbackXml,
    ): string {
        if ($entries === []) {
            return trim($fallbackXml);
        }

        return MarkdownBriefingFormatter::format(
            $entries,
            $scoresByKey,
            $meta,
            true,
            MarkdownBriefingFormatter::FORMAT_XML,
        );
    }

    /**
     * @return list<string>
     * @throws GeminiBriefingException
     */
    private function runSelectionPass(
        string $poolContext,
        int $itemCount,
        int $selectionTarget,
        string $apiKey,
    ): array {
        $systemText = str_replace(
            ['{itemCount}', '{maxCoreItems}', '{markdownContext}'],
            [(string)$itemCount, (string)$selectionTarget, trim($poolContext)],
            self::SELECTION_OUTPUT_CONTRACT,
        );

        $userText = 'Wähle bis zu ' . $selectionTarget . ' Einträge für das Executive Briefing, '
            . 'nur wenn sie den User-Prompt erfüllen (weniger ist korrekt). '
            . 'Fülle selection_notes und used_entry_keys (gleiche Reihenfolge).';

        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemText]]],
            'contents'          => [['role' => 'user', 'parts' => [['text' => $userText]]]],
            'generationConfig'  => $this->selectionGenerationConfig($itemCount, $selectionTarget, true),
        ];

        $response = $this->postPayloadWithSchemaFallback($payload, $apiKey, 'selection');

        return $this->parseSelectionResponse($response);
    }

    /**
     * @param list<string> $selectedKeys
     * @throws GeminiBriefingException
     */
    private function runSummaryPass(
        string $userSystemPrompt,
        string $summaryContext,
        array $selectedKeys,
        int $itemCount,
        int $effectiveCount,
        string $apiKey,
    ): GeminiBriefingResult {
        $keysBlock = implode("\n", $selectedKeys);
        $envelope  = str_replace(
            ['{selectedEntryKeys}', '{markdownContext}'],
            [$keysBlock, trim($summaryContext)],
            self::SUMMARY_OUTPUT_CONTRACT,
        );
        $systemText = trim($userSystemPrompt) . "\n\n" . $envelope;

        $userText = 'Schreibe das vollständige Executive Briefing in briefing_markdown. '
            . 'Decke alle ' . $effectiveCount . ' SELECTED_ENTRY_KEYS in dieser Reihenfolge ab.';

        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemText]]],
            'contents'          => [['role' => 'user', 'parts' => [['text' => $userText]]]],
            'generationConfig'  => $this->summaryGenerationConfig($itemCount, $effectiveCount, true),
        ];

        $response = $this->postPayloadWithSchemaFallback($payload, $apiKey, 'summary');

        return $this->parseSummaryResponse($response, $selectedKeys);
    }

    /**
     * @param array<string, mixed> $payload
     * @throws GeminiBriefingException
     */
    private function postPayloadWithSchemaFallback(array $payload, string $apiKey, string $phase): Response
    {
        $url      = self::API_BASE . rawurlencode($this->model) . ':generateContent';
        $response = $this->postWithRetries($url, $payload, $apiKey, $phase === 'selection' || $phase === 'summary');

        if (!$response->isOk() && $this->shouldRetryWithoutResponseSchema($response)) {
            error_log(
                'GeminiBriefingService: responseSchema rejected for ' . $phase
                . ' on model ' . $this->model . '; retrying without schema'
            );
            $config = $payload['generationConfig'] ?? [];
            if (is_array($config)) {
                unset($config['responseSchema']);
                $payload['generationConfig'] = $config;
            }
            $response = $this->postWithRetries($url, $payload, $apiKey, $phase === 'selection' || $phase === 'summary');
        }

        if (!$response->isOk()) {
            throw $this->exceptionFromFailedResponse($response);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function selectionGenerationConfig(int $itemCount, int $effectiveCount, bool $useStructuredSchema): array
    {
        $config = [
            'temperature'     => self::DEFAULT_TEMPERATURE,
            'maxOutputTokens' => self::resolveSelectionPassTokenBudget($itemCount, $this->maxOutputTokens),
            'thinkingConfig'  => [
                'thinkingBudget' => self::resolveThinkingBudget($itemCount),
            ],
            'responseMimeType' => 'application/json',
        ];
        if ($useStructuredSchema) {
            $config['responseSchema'] = $this->selectionResponseSchema($effectiveCount);
        }

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryGenerationConfig(int $itemCount, int $effectiveCount, bool $useStructuredSchema): array
    {
        $config = [
            'temperature'     => self::DEFAULT_TEMPERATURE,
            'maxOutputTokens' => self::resolveOutputTokenBudget($itemCount, $this->maxOutputTokens),
            'thinkingConfig'  => [
                'thinkingBudget' => 0,
            ],
            'responseMimeType' => 'application/json',
        ];
        if ($useStructuredSchema) {
            $config['responseSchema'] = $this->summaryResponseSchema($effectiveCount);
        }

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function selectionResponseSchema(int $selectionTarget): array
    {
        return [
            'type'       => 'OBJECT',
            'properties' => [
                'selection_notes' => [
                    'type'        => 'STRING',
                    'description' => 'Brief rationale: one short line per chosen entry, same order as used_entry_keys.',
                ],
                'used_entry_keys' => [
                    'type'        => 'ARRAY',
                    'description' => 'Up to ' . $selectionTarget . ' entry_type:entry_id values from ENTRIES_DATA <id> that satisfy the user prompt, briefing order.',
                    'items'       => ['type' => 'STRING'],
                    'minItems'    => 1,
                    'maxItems'    => $selectionTarget,
                ],
            ],
            'required' => ['selection_notes', 'used_entry_keys'],
        ];
    }

    private function countXmlEntries(string $xml): int
    {
        if ($xml === '') {
            return 0;
        }

        $count = preg_match_all('/<entry>/', $xml);

        return $count === false ? 0 : $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryResponseSchema(int $effectiveCount): array
    {
        return [
            'type'       => 'OBJECT',
            'properties' => [
                'briefing_markdown' => [
                    'type'        => 'STRING',
                    'description' => 'Complete executive briefing Markdown covering all ' . $effectiveCount
                        . ' selected entries in SELECTED_ENTRY_KEYS order.',
                ],
            ],
            'required' => ['briefing_markdown'],
        ];
    }

    /**
     * @return list<string>
     * @throws GeminiBriefingException
     */
    private function parseSelectionResponse(Response $response): array
    {
        $raw     = $this->extractCandidateText($response);
        $decoded = $this->decodeBriefingJsonObject($raw);
        if ($decoded === null) {
            throw GeminiBriefingException::badResponse('Selection pass JSON could not be parsed.');
        }

        $keys = $this->normalizeUsedEntryKeys($decoded['used_entry_keys'] ?? null);
        if ($keys === []) {
            throw GeminiBriefingException::emptyResponse('Selection pass returned no used_entry_keys.');
        }

        return $keys;
    }

    /**
     * @param list<string> $selectedKeys
     * @throws GeminiBriefingException
     */
    private function parseSummaryResponse(Response $response, array $selectedKeys): GeminiBriefingResult
    {
        $raw     = $this->extractCandidateText($response);
        $decoded = $this->decodeBriefingJsonObject($raw);
        if ($decoded !== null) {
            $markdown = trim((string)($decoded['briefing_markdown'] ?? ''));
            if ($markdown === '') {
                throw GeminiBriefingException::emptyResponse('Summary pass missing briefing_markdown.');
            }

            $result = new GeminiBriefingResult($markdown, $selectedKeys, true);
            $this->assertBriefingNotTruncated($result, $this->lastEffectiveCitationCount);

            return $result;
        }

        $jsonText = LenientJsonParser::extractMarkdownJson($raw);
        $salvaged = $this->salvageBriefingFromBrokenJson($jsonText);
        if ($salvaged !== null) {
            error_log('GeminiBriefingService summary pass: recovered markdown from broken JSON.');
            $result = new GeminiBriefingResult($salvaged->markdown, $selectedKeys, false);
            $this->assertBriefingNotTruncated($result, $this->lastEffectiveCitationCount);

            return $result;
        }

        throw GeminiBriefingException::badResponse('Summary pass JSON could not be parsed.');
    }

    /**
     * @param list<string> $selectedKeys
     * @param list<array<string, mixed>> $poolEntries
     * @return list<string>
     */
    private function finalizeSelectedKeys(array $selectedKeys, int $effectiveCount): array
    {
        if (count($selectedKeys) > $effectiveCount) {
            return array_slice($selectedKeys, 0, $effectiveCount);
        }

        if ($selectedKeys !== [] && count($selectedKeys) < $effectiveCount) {
            error_log(
                'GeminiBriefingService: selection returned ' . count($selectedKeys)
                . ' of up to ' . $effectiveCount . ' requested keys (no relevance padding)'
            );
        }

        return $selectedKeys;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param list<string> $keys
     * @return list<array<string, mixed>>
     */
    private function entriesForKeys(array $entries, array $keys): array
    {
        if ($entries === [] || $keys === []) {
            return [];
        }

        $byKey = [];
        foreach ($entries as $entry) {
            $key = $this->entryKey($entry);
            if ($key !== null) {
                $byKey[$key] = $entry;
            }
        }

        $ordered = [];
        foreach ($keys as $key) {
            $normalized = strtolower(trim($key));
            if (isset($byKey[$normalized])) {
                $ordered[] = $byKey[$normalized];
            }
        }

        return $ordered;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function entryKey(array $entry): ?string
    {
        $type = (string)($entry['entry_type'] ?? '');
        $id   = (string)($entry['entry_id'] ?? '');
        if ($type === '' || $id === '' || !ctype_digit($id)) {
            return null;
        }

        return strtolower($type . ':' . $id);
    }

    /**
     * @param list<string> $keys
     */
    private function filterXmlContextByKeys(string $xml, array $keys): string
    {
        $wanted = [];
        foreach ($keys as $key) {
            $wanted[strtolower(trim($key))] = true;
        }
        if ($wanted === []) {
            return trim($xml);
        }

        if (!preg_match_all('/<entry>(.*?)<\/entry>/s', $xml, $matches)) {
            return trim($xml);
        }

        $blocks = [];
        foreach ($matches[1] as $inner) {
            if (!preg_match('/<id>([^<]+)<\/id>/', $inner, $idMatch)) {
                continue;
            }
            $id = strtolower(trim($idMatch[1]));
            if (isset($wanted[$id])) {
                $blocks[] = '<entry>' . $inner . '</entry>';
            }
        }

        if ($blocks === []) {
            return trim($xml);
        }

        return '<seismo_briefing selected_only="true"><entries>' . implode('', $blocks) . '</entries></seismo_briefing>';
    }

    private function effectiveCitationCount(int $itemCount, int $contextEntryCount): int
    {
        if ($contextEntryCount < 1) {
            return max(1, $itemCount);
        }

        return max(1, min($itemCount, $contextEntryCount));
    }

    /**
     * @param array<string, mixed> $payload
     * @throws GeminiBriefingException
     */
    private function postWithRetries(string $url, array $payload, string $apiKey, bool $extendedTimeout = false): Response
    {
        $headers = ['x-goog-api-key' => $apiKey];
        $attempts = self::DEFAULT_MAX_RETRIES + 1;
        $lastTransport = null;
        $client = $extendedTimeout
            ? new BaseClient(self::HTTP_TIMEOUT_TWO_PASS_SECONDS)
            : $this->http;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                $response = $client->postJson($url, $payload, $headers);
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
        if ($this->isOutputTruncatedFinishReason($finish)) {
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
     * @return array<string, mixed>|null
     */
    private function decodeBriefingJsonObject(string $raw): ?array
    {
        $jsonText = LenientJsonParser::extractMarkdownJson($raw);

        try {
            $decoded = json_decode($jsonText, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException $e) {
            error_log('GeminiBriefingService JSON strict parse: ' . $e->getMessage());
        }

        $repaired = LenientJsonParser::parseObject($raw);
        if ($repaired !== null) {
            error_log('GeminiBriefingService JSON repaired via lenient parser.');
        }

        return $repaired;
    }

    private function isOutputTruncatedFinishReason(string $finishReason): bool
    {
        return in_array($finishReason, ['MAX_TOKENS', 'LENGTH'], true);
    }

    /**
     * @throws GeminiBriefingException
     */
    private function assertBriefingNotTruncated(GeminiBriefingResult $result, int $maxItemCount): void
    {
        $keysInMarkdown = $this->countDistinctEntryKeysInMarkdown($result->markdown);
        $citedKeys      = count($result->usedEntryKeys);

        if ($citedKeys > 0 && $keysInMarkdown > 0 && $keysInMarkdown < $citedKeys) {
            error_log(
                'GeminiBriefingService: briefing_markdown cites ' . $keysInMarkdown
                . ' entries but used_entry_keys has ' . $citedKeys
            );
            throw GeminiBriefingException::outputTruncated();
        }

        $itemsToCheck = $citedKeys > 0 ? $citedKeys : ($keysInMarkdown > 0 ? $keysInMarkdown : 0);
        if ($itemsToCheck < 2) {
            return;
        }

        if ($keysInMarkdown >= $itemsToCheck) {
            return;
        }

        if ($citedKeys >= $itemsToCheck && $keysInMarkdown < $itemsToCheck) {
            error_log(
                'GeminiBriefingService: expected ' . $itemsToCheck
                . ' cited items in markdown, found ' . $keysInMarkdown
            );
            throw GeminiBriefingException::outputTruncated();
        }

        $minChars = $itemsToCheck * 220;
        if (strlen($result->markdown) < $minChars && $keysInMarkdown < (int)ceil($itemsToCheck * 0.75)) {
            error_log(
                'GeminiBriefingService: briefing_markdown length ' . strlen($result->markdown)
                . ' below heuristic for ' . $itemsToCheck . ' items (max requested ' . $maxItemCount . ')'
            );
            throw GeminiBriefingException::outputTruncated();
        }
    }

    private function countDistinctEntryKeysInMarkdown(string $markdown): int
    {
        if ($markdown === '') {
            return 0;
        }

        if (!preg_match_all('/\b([a-z][a-z0-9_]*:\d+)\b/', $markdown, $matches)) {
            return 0;
        }

        return count(array_unique($matches[1]));
    }

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

        $after    = (string)preg_replace('/^\s*:\s*"/', '', $after, 1);
        $markdown = $this->readJsonStringLiteral($after);
        if ($markdown === null || trim($markdown) === '') {
            return null;
        }

        return new GeminiBriefingResult($markdown, [], false);
    }

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
            $key = strtolower(trim($item));
            if ($key === '' || !preg_match('/^[a-z][a-z0-9_]*:\d+$/', $key)) {
                continue;
            }
            $keys[] = $key;
        }

        return $keys;
    }
}
