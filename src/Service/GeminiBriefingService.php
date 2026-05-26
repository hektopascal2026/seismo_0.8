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
 * Default: skinny two-pass — one global selection call (compact JSON), then plain Markdown
 * prose on selected entries only. Legacy single-pass remains when two-pass is disabled in UI.
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

4. CITATIONS:
   - Each core item must map to one <entry> in ENTRIES_DATA.
   - Populate used_entry_keys with the exact entry_type:entry_id strings from the chosen <id> values, in the same order as the core items in briefing_markdown.

ENTRIES_DATA:
{markdownContext}
CONTRACT;

    private const SELECTION_OUTPUT_CONTRACT = <<<'CONTRACT'
SYSTEM DIRECTIVE — GLOBAL ENTRY SELECTION (PASS 1 OF 2):
You see the full ENTRIES_DATA pool at once. Pick the best stories globally. Prose style is pass 2 only.

RULES:
- ENTRIES_DATA: XML <entry> blocks sorted by Seismo relevance (highest first). Each has <id>entry_type:entry_id</id>.
- Return JSON with used_entry_keys only: up to {maxCoreItems} distinct <id> values, most important first.
- Selecting fewer is correct when strict criteria apply — never pad with high-relevance rows that fail the user prompt.
- When the prompt restricts jurisdictions or legal corpora, EXCLUDE non-matching <jurisdiction>. Prefer lex_item for legal/regulatory prompts.
- Never invent IDs.

ENTRIES_DATA:
{markdownContext}
CONTRACT;

    private const SUMMARY_OUTPUT_CONTRACT = <<<'CONTRACT'
SYSTEM DIRECTIVE — BRIEFING PROSE (PASS 2 OF 2):
Entries are already chosen. Output plain Markdown only (no JSON wrapper).

RULES:
- Cover every SELECTED_ENTRY_KEYS entry once, in that order — one core item per entry.
- Cite each item with its entry_type:entry_id in parentheses (e.g. feed_item:123).
- SELECTED_ENTRIES_DATA has full text for those entries only.
- Follow the user persona for tone, structure, intro/outro, and headings.

SELECTED_ENTRY_KEYS (ordered):
{selectedEntryKeys}

SELECTED_ENTRIES_DATA:
{markdownContext}
CONTRACT;

    /** Override via `system_config` key `gemini:model`. */
    public const CONFIG_KEY_MODEL = 'gemini:model';

    /** Optional `system_config` override for {@see DEFAULT_MAX_OUTPUT_TOKENS}. */
    public const CONFIG_KEY_MAX_OUTPUT_TOKENS = 'gemini:max_output_tokens';

    public const DEFAULT_MODEL = 'gemini-3.5-flash';

    /** Hard API output cap for Gemini 2.5 Flash family. */
    public const MODEL_OUTPUT_CAP_GEMINI_25_FLASH = 8192;

    /** Hard API output cap for Gemini 3.5 Flash (GA). */
    public const MODEL_OUTPUT_CAP_GEMINI_35_FLASH = 65536;

    /** Practical prose cap per briefing (cost/latency), below model hard cap. */
    public const BRIEFING_SUMMARY_OUTPUT_CAP = 8192;

    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private const HTTP_TIMEOUT_SECONDS = 120;

    private const HTTP_TIMEOUT_TWO_PASS_SECONDS = 180;

    private const DEFAULT_TEMPERATURE = 0.2;

    private const DEFAULT_MAX_OUTPUT_TOKENS = 8192;

    private const OUTPUT_TOKEN_FLOOR = 2048;

    private const OUTPUT_TOKENS_PER_ITEM = 400;

    private const SELECTION_OUTPUT_TOKENS_BASE = 128;

    private const SELECTION_OUTPUT_TOKENS_PER_ITEM = 24;

    private const DEFAULT_MAX_RETRIES = 4;

    private const RETRY_BACKOFF_SECONDS = 2.0;

    /** @var list<int> */
    private const TRANSIENT_HTTP_STATUSES = [500, 502, 503, 504];

    private readonly string $model;

    private readonly int $maxOutputTokens;

    private readonly BriefingGeminiContext $briefingContext;

    private int $lastEffectiveCitationCount = 1;

    private bool $rateLimitFallbackMode = false;

    private bool $rateLimitFallbackUsed = false;

    /** @var array<string, mixed> */
    private array $lastGenerationMeta = [];

    public function __construct(
        private readonly SystemConfigRepository $config,
        private readonly BaseClient $http = new BaseClient(self::HTTP_TIMEOUT_SECONDS),
        ?BriefingGeminiContext $briefingContext = null,
    ) {
        $this->briefingContext = $briefingContext ?? new BriefingGeminiContext($config);
        $configured = trim((string)($config->get(self::CONFIG_KEY_MODEL) ?? ''));
        $this->model  = $configured !== '' ? $configured : self::DEFAULT_MODEL;

        $rawTokens = trim((string)($config->get(self::CONFIG_KEY_MAX_OUTPUT_TOKENS) ?? ''));
        $hardCap   = self::modelHardOutputCapFor($this->model);
        if ($rawTokens !== '' && ctype_digit($rawTokens)) {
            $this->maxOutputTokens = max(256, min($hardCap, (int)$rawTokens));
        } else {
            $this->maxOutputTokens = min(self::DEFAULT_MAX_OUTPUT_TOKENS, $hardCap);
        }
    }

    public static function usesGemini35Family(string $model): bool
    {
        return preg_match('/gemini-3[.-]5/i', $model) === 1;
    }

    public static function modelHardOutputCapFor(string $model): int
    {
        return self::usesGemini35Family($model)
            ? self::MODEL_OUTPUT_CAP_GEMINI_35_FLASH
            : self::MODEL_OUTPUT_CAP_GEMINI_25_FLASH;
    }

    private function modelHardOutputCap(): int
    {
        return self::modelHardOutputCapFor($this->model);
    }

    /**
     * @return array<string, mixed>
     */
    public function lastGenerationMeta(): array
    {
        return $this->lastGenerationMeta;
    }

    /** Pass 2 prose output budget (thinking off). */
    public static function resolveOutputTokenBudget(
        int $itemCount,
        int $configuredMax,
        string $model = self::DEFAULT_MODEL,
    ): int {
        $hardCap       = self::modelHardOutputCapFor($model);
        $practical     = min($hardCap, self::BRIEFING_SUMMARY_OUTPUT_CAP);
        $configuredMax = max(256, min($hardCap, $configuredMax));
        $scaled        = 512 + max(1, $itemCount) * self::OUTPUT_TOKENS_PER_ITEM;

        return min($practical, $configuredMax, max(self::OUTPUT_TOKEN_FLOOR, $scaled));
    }

    /** Pass 1 skinny JSON (IDs only, no thinking). */
    public static function resolveSelectionPassTokenBudget(
        int $itemCount,
        int $configuredMax,
        string $model = self::DEFAULT_MODEL,
    ): int {
        $hardCap = self::modelHardOutputCapFor($model);
        $visible = self::SELECTION_OUTPUT_TOKENS_BASE + max(1, $itemCount) * self::SELECTION_OUTPUT_TOKENS_PER_ITEM;

        return min($hardCap, max(256, $visible));
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
        ?BriefingSourceSelection $moduleSelection = null,
    ): GeminiBriefingResult {
        $apiKey = trim((string)($this->config->get(SettingsController::KEY_GEMINI_API_KEY) ?? ''));
        if ($apiKey === '') {
            throw GeminiBriefingException::missingApiKey();
        }

        $this->rateLimitFallbackMode = false;
        $this->rateLimitFallbackUsed = false;

        try {
            return $this->executeGenerateSummary(
                $userSystemPrompt,
                $markdownContext,
                $itemCount,
                $contextEntryCount,
                $contextEntries,
                $scoresByKey,
                $briefingMeta,
                $apiKey,
                $twoPass,
                $moduleSelection,
            );
        } catch (GeminiBriefingException $e) {
            if (!$e->isRateLimitExceeded() || $this->rateLimitFallbackUsed) {
                throw $e;
            }

            $this->rateLimitFallbackUsed = true;
            $this->rateLimitFallbackMode  = true;
            error_log('GeminiBriefingService: HTTP 429 — automatic retry with reduced batched context');

            sleep(BriefingGeminiContext::RATE_LIMIT_RETRY_PAUSE_SECONDS);

            [$contextEntries, $markdownContext, $contextEntryCount] = $this->shrinkContextForRateLimit(
                $contextEntries,
                $scoresByKey,
                $briefingMeta,
                $moduleSelection,
            );

            $this->lastGenerationMeta = [
                'rate_limit_fallback'              => true,
                'rate_limit_fallback_max_entries'  => $this->briefingContext->rateLimitFallbackMaxEntries(),
                'rate_limit_fallback_batch_size'   => BriefingGeminiContext::RATE_LIMIT_FALLBACK_BATCH_SIZE,
            ];

            return $this->executeGenerateSummary(
                $userSystemPrompt,
                $markdownContext,
                $itemCount,
                $contextEntryCount,
                $contextEntries,
                $scoresByKey,
                $briefingMeta,
                $apiKey,
                true,
                $moduleSelection,
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $contextEntries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $briefingMeta
     * @throws GeminiBriefingException
     */
    private function executeGenerateSummary(
        string $userSystemPrompt,
        string $markdownContext,
        int $itemCount,
        int $contextEntryCount,
        array $contextEntries,
        array $scoresByKey,
        array $briefingMeta,
        string $apiKey,
        bool $twoPass,
        ?BriefingSourceSelection $moduleSelection = null,
    ): GeminiBriefingResult {
        [$contextEntries, $markdownContext, $contextEntryCount] = $this->sealContextForGemini(
            $contextEntries,
            $scoresByKey,
            $briefingMeta,
            $markdownContext,
            $moduleSelection,
        );

        $userSystemPrompt = trim($userSystemPrompt);
        if ($userSystemPrompt === '') {
            throw GeminiBriefingException::invalidInput('System prompt is required.');
        }

        if ($itemCount < 1) {
            $itemCount = 5;
        }

        $effectiveCount = $this->effectiveCitationCount($itemCount, $contextEntryCount);
        $this->lastEffectiveCitationCount = $effectiveCount;

        $autoTwoPass = false;
        if (
            !$twoPass
            && $contextEntryCount >= BriefingGeminiContext::AUTO_TWO_PASS_MIN_ENTRIES
        ) {
            $twoPass     = true;
            $autoTwoPass = true;
        }

        $this->lastGenerationMeta = array_merge($this->lastGenerationMeta, [
            'two_pass'      => $twoPass,
            'auto_two_pass' => $autoTwoPass,
        ]);

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
     * @param list<array<string, mixed>> $contextEntries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $briefingMeta
     * @return array{0: list<array<string, mixed>>, 1: string, 2: int}
     */
    private function shrinkContextForRateLimit(
        array $contextEntries,
        array $scoresByKey,
        array $briefingMeta,
        ?BriefingSourceSelection $moduleSelection,
    ): array {
        $gatherer = new BriefingEntryGatherer();
        if ($moduleSelection !== null) {
            $capped = BriefingGeminiContext::capEntryListStratified(
                $contextEntries,
                $this->briefingContext->rateLimitFallbackMaxEntries(),
                $scoresByKey,
                $gatherer,
                $moduleSelection,
            );
            $entries = $capped['entries'];
            $guard   = new BriefingModuleGuard($gatherer);
            $sealed  = $guard->sealGeminiContext($entries, $scoresByKey, $briefingMeta, $moduleSelection);

            return [$sealed['entries'], $sealed['markdown'], count($sealed['entries'])];
        }

        $capped  = BriefingGeminiContext::capEntryList(
            $contextEntries,
            $this->briefingContext->rateLimitFallbackMaxEntries(),
        );
        $entries = $capped['entries'];
        $meta    = $briefingMeta;
        $meta['total'] = count($entries);
        $markdown = MarkdownBriefingFormatter::format(
            $entries,
            $scoresByKey,
            $meta,
            true,
            MarkdownBriefingFormatter::FORMAT_XML,
        );

        return [$entries, $markdown, count($entries)];
    }

    /**
     * @param list<array<string, mixed>> $contextEntries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $briefingMeta
     * @return array{0: list<array<string, mixed>>, 1: string, 2: int}
     */
    private function sealContextForGemini(
        array $contextEntries,
        array $scoresByKey,
        array $briefingMeta,
        string $markdownContext,
        ?BriefingSourceSelection $moduleSelection,
    ): array {
        if ($moduleSelection === null) {
            return [$contextEntries, $markdownContext, count($contextEntries)];
        }

        $guard  = new BriefingModuleGuard(new BriefingEntryGatherer());
        $sealed = $guard->sealGeminiContext(
            $contextEntries,
            $scoresByKey,
            $briefingMeta,
            $moduleSelection,
        );

        return [$sealed['entries'], $sealed['markdown'], count($sealed['entries'])];
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
            . 'Liefere briefing_markdown und used_entry_keys (gleiche Reihenfolge). '
            . 'Bis zu ' . $effectiveCount . ' Kern-Items; nur Einträge die den User-Prompt erfüllen.';

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
        $poolEntries     = $contextEntries;
        $poolCount       = count($poolEntries);
        $selectionTarget = min($effectiveCount, max(1, $poolCount));

        $poolContext = $this->buildEntryXmlContext($poolEntries, $scoresByKey, $briefingMeta, $markdownContext);

        if ($poolCount >= $this->batchedSelectionMinEntries()) {
            $selectedKeys = $this->runBatchedSelectionPasses(
                $poolEntries,
                $scoresByKey,
                $selectionMeta,
                $poolContext,
                $itemCount,
                $selectionTarget,
                $apiKey,
            );
            $this->lastGenerationMeta = array_merge($this->lastGenerationMeta, [
                'batched_selection' => true,
            ]);
        } else {
            $selectedKeys = $this->runSelectionPass($poolContext, $itemCount, $selectionTarget, $apiKey);
            $this->lastGenerationMeta = array_merge($this->lastGenerationMeta, [
                'skinny_global_selection' => true,
            ]);
        }

        $selectedKeys = $this->finalizeSelectedKeys($selectedKeys, $selectionTarget);

        $summaryEntries = $this->entriesForKeys($contextEntries, $selectedKeys);
        if ($summaryEntries === []) {
            $summaryContext = $this->filterXmlContextByKeys($markdownContext, $selectedKeys);
        } else {
            $summaryMeta             = $briefingMeta;
            $summaryMeta['total']    = count($summaryEntries);
            $summaryMeta['selected'] = count($selectedKeys);
            $summaryContext          = $this->buildEntryXmlContext(
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
        $config = $this->applyModelGenerationDefaults([
            'maxOutputTokens' => self::resolveOutputTokenBudget($itemCount, $this->maxOutputTokens, $this->model),
            'responseMimeType' => 'application/json',
        ]);
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
            'required' => ['briefing_markdown', 'used_entry_keys'],
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

        $userText = 'Wähle bis zu ' . $selectionTarget . ' Einträge global aus ENTRIES_DATA. '
            . 'Nur wenn sie den User-Prompt erfüllen (weniger ist korrekt). '
            . 'JSON: used_entry_keys in Briefing-Reihenfolge.';

        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemText]]],
            'contents'          => [['role' => 'user', 'parts' => [['text' => $userText]]]],
            'generationConfig'  => $this->selectionGenerationConfig($itemCount, $selectionTarget, true),
        ];

        $response = $this->postPayloadWithSchemaFallback($payload, $apiKey, 'selection');

        return $this->parseSelectionResponse($response);
    }

    /**
     * Selection over entry chunks to stay under Gemini TPM/RPM per request.
     *
     * @param list<array<string, mixed>> $poolEntries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $briefingMeta
     * @return list<string>
     * @throws GeminiBriefingException
     */
    private function runBatchedSelectionPasses(
        array $poolEntries,
        array $scoresByKey,
        array $briefingMeta,
        string $fallbackXml,
        int $itemCount,
        int $selectionTarget,
        string $apiKey,
    ): array {
        $batchSize  = $this->effectiveSelectionBatchSize();
        $batches    = BriefingGeminiContext::chunkEntryList($poolEntries, $batchSize);
        $batchCount = count($batches);
        if ($batchCount === 0) {
            return [];
        }

        $perBatch = max(1, (int)ceil($selectionTarget / $batchCount));
        $merged   = [];
        $seen     = [];

        foreach ($batches as $index => $batch) {
            if ($index > 0) {
                sleep($this->batchPauseSeconds());
            }

            $batchContext = $this->buildEntryXmlContext($batch, $scoresByKey, $briefingMeta, $fallbackXml);
            $batchTarget  = min($perBatch, count($batch), $selectionTarget);
            $keys         = $this->runSelectionPass($batchContext, $itemCount, $batchTarget, $apiKey);

            foreach ($keys as $key) {
                $normalized = strtolower(trim($key));
                if ($normalized === '' || isset($seen[$normalized])) {
                    continue;
                }
                $seen[$normalized] = true;
                $merged[]          = $key;
            }
        }

        $this->lastGenerationMeta = array_merge($this->lastGenerationMeta, [
            'batched_selection'    => true,
            'selection_batches'    => $batchCount,
            'selection_batch_size' => $batchSize,
        ]);

        if (count($merged) <= $selectionTarget) {
            return $merged;
        }

        return array_slice($merged, 0, $selectionTarget);
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

        $userText = 'Schreibe das vollständige Executive Briefing als plain Markdown. '
            . 'Decke alle ' . $effectiveCount . ' SELECTED_ENTRY_KEYS in dieser Reihenfolge ab. '
            . 'Zitiere jedes Item mit entry_type:entry_id in Klammern.';

        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemText]]],
            'contents'          => [['role' => 'user', 'parts' => [['text' => $userText]]]],
            'generationConfig'  => $this->summaryGenerationConfig($itemCount, $effectiveCount),
        ];

        $response = $this->postWithRetries(
            self::API_BASE . rawurlencode($this->model) . ':generateContent',
            $payload,
            $apiKey,
            true,
        );
        if (!$response->isOk()) {
            throw $this->exceptionFromFailedResponse($response);
        }

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
        $config = $this->applyModelGenerationDefaults([
            'maxOutputTokens' => self::resolveSelectionPassTokenBudget($itemCount, $this->maxOutputTokens, $this->model),
            'responseMimeType' => 'application/json',
        ]);
        if ($useStructuredSchema) {
            $config['responseSchema'] = $this->selectionResponseSchema($effectiveCount);
        }

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryGenerationConfig(int $itemCount, int $effectiveCount): array
    {
        return $this->applyModelGenerationDefaults([
            'maxOutputTokens' => self::resolveOutputTokenBudget($itemCount, $this->maxOutputTokens, $this->model),
            'responseMimeType' => 'text/plain',
        ]);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function applyModelGenerationDefaults(array $config): array
    {
        $config['maxOutputTokens'] = min(
            (int)($config['maxOutputTokens'] ?? $this->maxOutputTokens),
            $this->modelHardOutputCap(),
        );
        if (!self::usesGemini35Family($this->model)) {
            $config['temperature'] = self::DEFAULT_TEMPERATURE;
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
                'used_entry_keys' => [
                    'type'        => 'ARRAY',
                    'description' => 'Up to ' . $selectionTarget . ' entry_type:entry_id values from ENTRIES_DATA <id> that satisfy the user prompt, briefing order.',
                    'items'       => ['type' => 'STRING'],
                    'minItems'    => 0,
                    'maxItems'    => $selectionTarget,
                ],
            ],
            'required' => ['used_entry_keys'],
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
        $raw = $this->extractCandidateText($response);
        if (!str_starts_with(trim($raw), '{')) {
            $markdown = trim($raw);
            if ($markdown === '') {
                throw GeminiBriefingException::emptyResponse('Summary pass returned empty Markdown.');
            }
            $result = new GeminiBriefingResult($markdown, $selectedKeys, true);
            $this->assertBriefingNotTruncated($result, $this->lastEffectiveCitationCount);

            return $result;
        }

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

    private function batchedSelectionMinEntries(): int
    {
        if ($this->rateLimitFallbackMode) {
            return BriefingGeminiContext::RATE_LIMIT_BATCHED_SELECTION_MIN_ENTRIES;
        }

        return BriefingGeminiContext::BATCHED_SELECTION_MIN_ENTRIES;
    }

    private function effectiveSelectionBatchSize(): int
    {
        if ($this->rateLimitFallbackMode) {
            return BriefingGeminiContext::RATE_LIMIT_FALLBACK_BATCH_SIZE;
        }

        return $this->briefingContext->selectionBatchSize();
    }

    private function batchPauseSeconds(): int
    {
        if ($this->rateLimitFallbackMode) {
            return BriefingGeminiContext::RATE_LIMIT_BATCH_PAUSE_SECONDS;
        }

        return BriefingGeminiContext::BATCH_PAUSE_SECONDS;
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

            if ($response->status === 429) {
                return $response;
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
