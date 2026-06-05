<?php

declare(strict_types=1);

namespace Seismo\Service;

use Seismo\Controller\SettingsController;
use Seismo\Formatter\MarkdownResearcherFormatter;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Service\Http\BaseClient;
use Seismo\Util\LenientJsonParser;
use Seismo\Service\Http\HttpClientException;
use Seismo\Service\Http\Response;

/**
 * Calls Google Gemini `generateContent` for the AI Researcher.
 *
 * Always uses skinny two-pass: one global selection call (compact JSON), then plain Markdown
 * prose on selected entries only.
 */
final class GeminiResearcherService
{
    private const SELECTION_OUTPUT_CONTRACT = <<<'CONTRACT'
SYSTEM DIRECTIVE — GLOBAL ENTRY SELECTION (PASS 1 OF 2):
The USER PROMPT above defines inclusion criteria, jurisdictions, and topic focus. Apply it strictly when choosing IDs.
You see the full ENTRIES_DATA pool at once. Pick the best matching entries globally. Prose style is pass 2 only.

{temporalContext}

RULES:
- ENTRIES_DATA: XML <entry> blocks sorted by Seismo relevance (highest first). Each has <id>entry_type:entry_id</id>.
- Return JSON with used_entry_keys (required) and selection_reasoning (optional): brief step-by-step rationale, then up to {maxCoreItems} distinct <id> values, most important first.
- Selecting fewer is correct when strict USER PROMPT criteria apply — never pad with high-relevance rows that fail the USER PROMPT.
- When the USER PROMPT restricts jurisdictions or legal corpora, EXCLUDE non-matching <jurisdiction>. Prefer lex_item for legal/regulatory USER PROMPTs.
- Never invent IDs.

ENTRIES_DATA:
{markdownContext}
CONTRACT;

    private const SELECTION_BATCH_OUTPUT_CONTRACT = <<<'CONTRACT'
SYSTEM DIRECTIVE — TOURNAMENT BATCH SELECTION (PASS 1 OF 2):
The USER PROMPT above defines inclusion criteria, jurisdictions, and topic focus. Apply it strictly when choosing IDs.
You see ONE batch of ENTRIES_DATA only (not the full pool). Compare every entry in this batch; pick the strongest matches for the USER PROMPT.

{temporalContext}

RULES:
- ENTRIES_DATA: XML <entry> blocks for this batch only. Each has <id>entry_type:entry_id</id>.
- Return JSON with used_entry_keys (required) and selection_reasoning (optional): brief rationale, then up to {maxCoreItems} distinct <id> values, most important first.
- Selecting fewer is correct when strict USER PROMPT criteria apply.
- Never invent IDs.

ENTRIES_DATA:
{markdownContext}
CONTRACT;

    private const RELATIONAL_NEGATIVE_SPACE_PROTOCOL = <<<'PROTOCOL'
CRITICAL TRIAGE — BLIND SPOT / CROSS-MODULE (use GLOBAL POOL INDEX above):
1. Build a "media footprint" from all items where module is media, feeds, or scraper (titles only in the fingerprint).
2. Review primary regulatory sources (module lex or leg).
3. Exclude legal/calendar rows whose topics clearly appear in the media footprint titles.
4. Prioritize primary sources with high strategic impact and ZERO topic overlap with the media footprint.
5. Put these hidden signals first in used_entry_keys when they match the USER PROMPT.
PROTOCOL;

    private const VERIFICATION_COMPLIANCE_APPENDIX = <<<'APPENDIX'
VERIFICATION COMPLIANCE (strict USER PROMPT guardrails — applies to every entry you select):
- Never select an entry unless required company names, entities, or facts from the USER PROMPT appear in that entry's body text (not just the title).
- Under no circumstances infer a match from metadata, jurisdiction, or topic similarity alone.
- When verification is ambiguous, exclude the entry rather than include it.
APPENDIX;

    private const SUMMARY_OUTPUT_CONTRACT = <<<'CONTRACT'
SYSTEM DIRECTIVE — BRIEFING PROSE (PASS 2 OF 2):
Entries are already chosen. Output plain Markdown only (no JSON wrapper).

{temporalContext}

RULES:
- Cover every SELECTED_ENTRY_KEYS entry once, in that order — one core item per entry.
- Cite each item with its entry_type:entry_id in parentheses (e.g. feed_item:123).
- SELECTED_ENTRIES_DATA has full text for those entries only.
- Follow the user persona for tone, structure, intro/outro, and headings.
- CRITICAL: Output ONLY the requested Markdown. No conversational filler (e.g. "Here is the researcher") and no closing offers (e.g. "Let me know if you need anything else").

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

    /** Pass-1 selection when Pro mode is enabled on the researcher form. */
    public const MODEL_GEMINI_31_PRO_PREVIEW = 'gemini-3.1-pro-preview';

    /** Local picks promoted from each tournament batch (cap). */
    public const TOURNAMENT_SURVIVORS_PER_BATCH = 3;

    /** Hard API output cap for Gemini 3.5 Flash (GA). */
    public const MODEL_OUTPUT_CAP_GEMINI_35_FLASH = 65536;

    /** Practical prose cap per researcher — aligned with Gemini 3.5 Flash hard cap. */
    public const BRIEFING_SUMMARY_OUTPUT_CAP = 65536;

    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private const HTTP_TIMEOUT_SECONDS = 120;

    private const HTTP_TIMEOUT_TWO_PASS_SECONDS = 180;

    private const DEFAULT_MAX_OUTPUT_TOKENS = 65536;

    private const OUTPUT_TOKEN_FLOOR = 2048;

    /** Visible pass-2 tokens scaled per cited item (structured / legal blocks need more than one paragraph). */
    private const OUTPUT_TOKENS_PER_ITEM = 4500;

    /** Reserved for title/headings outside the per-entry bullet budget. */
    private const SUMMARY_FIXED_OVERHEAD_TOKENS = 1536;

    /** Pass-2 batched retry when a single call hits output limits. */
    private const SUMMARY_BATCH_SIZE = 5;

    /** Skip monolithic pass 2 when this many entries are selected. */
    public const PROACTIVE_SUMMARY_BATCH_MIN_ITEMS = 8;

    /** Same floor as standard — preserve cross-entry synthesis before chunking. */
    public const PROACTIVE_SUMMARY_BATCH_MIN_ITEMS_TOURNAMENT = 8;

    private const SELECTION_OUTPUT_TOKENS_BASE = 128;

    private const SELECTION_OUTPUT_TOKENS_PER_ITEM = 120;

    private const SELECTION_REASONING_TOKEN_HEADROOM = 256;

    /** Gemini 3.5 {@see thinkingConfigForPhase()} levels (REST uses uppercase). */
    private const THINKING_LEVEL_SELECTION = 'LOW';

    /** MINIMAL on pass 2 — LOW thinking competes with visible prose on gemini-3.5-flash. */
    private const THINKING_LEVEL_SUMMARY = 'MINIMAL';

    private const DEFAULT_MAX_RETRIES = 4;

    private const RETRY_BACKOFF_SECONDS = 2.0;

    /** @var list<int> */
    private const TRANSIENT_HTTP_STATUSES = [500, 502, 503, 504];

    private readonly string $model;

    private readonly int $maxOutputTokens;

    private readonly ResearcherGeminiContext $researcherContext;

    private int $lastEffectiveCitationCount = 1;

    private bool $rateLimitFallbackMode = false;

    private bool $rateLimitFallbackUsed = false;

    /** @var array<string, mixed> */
    private array $lastGenerationMeta = [];

    /** @var array<string, int> */
    private array $lastPipelineContext = [];

    private int $usagePromptTokens = 0;

    private int $usageOutputTokens = 0;

    private int $usageFlashPromptTokens = 0;

    private int $usageFlashOutputTokens = 0;

    private int $usageProPromptTokens = 0;

    private int $usageProOutputTokens = 0;

    private int $usageApiCalls = 0;

    /** @var array<string, array{prompt_tokens: int, output_tokens: int, api_calls: int, finish_reason?: string}> */
    private array $usageByPhase = [];

    /** @var array<string, string> */
    private array $lastFinishReasonByPhase = [];

    private GeminiResearcherGenerationOptions $generationOptions;

    private GeminiResearcherSelectionProfile $selectionProfile;

    private string $globalFingerprintXml = '';

    private readonly GeminiResearcherSelectionProfileResolver $profileResolver;

    public function __construct(
        private readonly SystemConfigRepository $config,
        private readonly BaseClient $http = new BaseClient(self::HTTP_TIMEOUT_SECONDS),
        ?ResearcherGeminiContext $researcherContext = null,
    ) {
        $this->generationOptions = GeminiResearcherGenerationOptions::defaults();
        $this->profileResolver   = new GeminiResearcherSelectionProfileResolver();
        $this->selectionProfile  = new GeminiResearcherSelectionProfile(
            id: GeminiResearcherSelectionProfile::STANDARD,
            requestedMode: GeminiResearcherGenerationOptions::MODE_STANDARD,
        );
        $this->researcherContext = $researcherContext ?? new ResearcherGeminiContext($config);
        $configured = trim((string)($config->get(self::CONFIG_KEY_MODEL) ?? ''));
        $model      = $configured !== '' ? $configured : self::DEFAULT_MODEL;
        if (!self::usesGemini35Family($model)) {
            error_log(
                'GeminiResearcherService: unsupported gemini:model "' . $model . '"; using ' . self::DEFAULT_MODEL
            );
            $model = self::DEFAULT_MODEL;
        }
        $this->model = $model;

        $rawTokens = trim((string)($config->get(self::CONFIG_KEY_MAX_OUTPUT_TOKENS) ?? ''));
        $hardCap   = self::modelHardOutputCap();
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

    public static function usesGemini31Pro(string $model): bool
    {
        return preg_match('/gemini-3\.1-pro/i', $model) === 1;
    }

    public static function modelHardOutputCapFor(string $model): int
    {
        if (self::usesGemini35Family($model)) {
            return self::MODEL_OUTPUT_CAP_GEMINI_35_FLASH;
        }

        if (preg_match('/gemini-3\.1-pro/i', $model) === 1) {
            return 65536;
        }

        return self::MODEL_OUTPUT_CAP_GEMINI_35_FLASH;
    }

    public static function tournamentSurvivorsForBatchSize(int $entryCount): int
    {
        if ($entryCount < 1) {
            return 1;
        }

        return max(1, min(self::TOURNAMENT_SURVIVORS_PER_BATCH, $entryCount));
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

    /**
     * @return array<string, mixed>
     */
    public function normalizedGenerationMeta(): array
    {
        return GeminiResearcherGenerationMeta::normalize(
            $this->lastGenerationMeta,
            $this->generationOptions,
            $this->lastPipelineContext,
        );
    }

    /** Pass 2 visible prose budget (thinking is configured separately). */
    public static function resolveOutputTokenBudget(
        int $itemCount,
        int $configuredMax,
        string $model = self::DEFAULT_MODEL,
    ): int {
        $hardCap       = self::modelHardOutputCapFor($model);
        $practical     = min($hardCap, self::BRIEFING_SUMMARY_OUTPUT_CAP);
        $configuredMax = max(256, min($hardCap, $configuredMax));
        $scaled        = $itemCount <= 1
            ? $practical
            : self::SUMMARY_FIXED_OVERHEAD_TOKENS + max(1, $itemCount) * self::OUTPUT_TOKENS_PER_ITEM;

        return min($practical, $configuredMax, max(self::OUTPUT_TOKEN_FLOOR, $scaled));
    }

    /** Pass 1 JSON envelope (IDs + selection_reasoning). */
    public static function resolveSelectionPassTokenBudget(
        int $itemCount,
        int $configuredMax,
        string $model = self::DEFAULT_MODEL,
    ): int {
        $hardCap       = self::modelHardOutputCapFor($model);
        $configuredMax = max(512, min($hardCap, $configuredMax));
        $visible       = self::SELECTION_OUTPUT_TOKENS_BASE
            + max(1, $itemCount) * self::SELECTION_OUTPUT_TOKENS_PER_ITEM
            + self::SELECTION_REASONING_TOKEN_HEADROOM;

        return min($configuredMax, max(512, $visible));
    }

    /** Pass 1 tournament batch (longer rationale over ~35 XML entries). */
    public static function resolveTournamentBatchSelectionTokenBudget(
        int $selectionTarget,
        int $configuredMax,
        string $model = self::DEFAULT_MODEL,
    ): int {
        $hardCap       = self::modelHardOutputCapFor($model);
        $configuredMax = max(512, min($hardCap, $configuredMax));
        $visible       = self::SELECTION_OUTPUT_TOKENS_BASE
            + max(1, $selectionTarget) * self::SELECTION_OUTPUT_TOKENS_PER_ITEM
            + 2048;

        return min($configuredMax, max(1024, $visible));
    }

    /**
     * @param list<array<string, mixed>> $contextEntries  Shaped Magnitu rows (relevance-sorted).
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $researcherMeta         Passed to {@see MarkdownResearcherFormatter::format}.
     * @throws GeminiResearcherException
     */
    public function generateSummary(
        string $userSystemPrompt,
        string $markdownContext,
        int $itemCount = 5,
        int $contextEntryCount = 0,
        array $contextEntries = [],
        array $scoresByKey = [],
        array $researcherMeta = [],
        ?ResearcherSourceSelection $moduleSelection = null,
        ?GeminiResearcherGenerationOptions $generationOptions = null,
    ): GeminiResearcherResult {
        $apiKey = trim((string)($this->config->get(SettingsController::KEY_GEMINI_API_KEY) ?? ''));
        if ($apiKey === '') {
            throw GeminiResearcherException::missingApiKey();
        }

        $this->generationOptions       = $generationOptions ?? GeminiResearcherGenerationOptions::defaults();
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
                $researcherMeta,
                $apiKey,
                $moduleSelection,
            );
        } catch (GeminiResearcherException $e) {
            if (!$e->isRateLimitExceeded() || $this->rateLimitFallbackUsed) {
                throw $e;
            }

            $this->rateLimitFallbackUsed = true;
            $this->rateLimitFallbackMode  = true;
            error_log('GeminiResearcherService: HTTP 429 — automatic retry with reduced batched context');

            sleep(ResearcherGeminiContext::RATE_LIMIT_RETRY_PAUSE_SECONDS);

            [$contextEntries, $markdownContext, $contextEntryCount] = $this->shrinkContextForRateLimit(
                $contextEntries,
                $scoresByKey,
                $researcherMeta,
                $moduleSelection,
            );

            $this->lastGenerationMeta = array_merge($this->lastGenerationMeta, [
                'rate_limit_fallback'              => true,
                'rate_limit_fallback_max_entries'  => $this->researcherContext->rateLimitFallbackMaxEntries(),
                'rate_limit_fallback_batch_size'   => ResearcherGeminiContext::RATE_LIMIT_FALLBACK_BATCH_SIZE,
                'selection_strategy'               => 'rate_limit_batched_selection',
            ]);

            return $this->executeGenerateSummary(
                $userSystemPrompt,
                $markdownContext,
                $itemCount,
                $contextEntryCount,
                $contextEntries,
                $scoresByKey,
                $researcherMeta,
                $apiKey,
                $moduleSelection,
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $contextEntries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $researcherMeta
     * @throws GeminiResearcherException
     */
    private function executeGenerateSummary(
        string $userSystemPrompt,
        string $markdownContext,
        int $itemCount,
        int $contextEntryCount,
        array $contextEntries,
        array $scoresByKey,
        array $researcherMeta,
        string $apiKey,
        ?ResearcherSourceSelection $moduleSelection = null,
    ): GeminiResearcherResult {
        [$contextEntries, $markdownContext, $contextEntryCount] = $this->sealContextForGemini(
            $contextEntries,
            $scoresByKey,
            $researcherMeta,
            $markdownContext,
            $moduleSelection,
        );

        $userSystemPrompt = trim($userSystemPrompt);
        if ($userSystemPrompt === '') {
            throw GeminiResearcherException::invalidInput('System prompt is required.');
        }

        if ($itemCount < 1) {
            $itemCount = 5;
        }

        $effectiveCount = $this->effectiveCitationCount($itemCount, $contextEntryCount);
        $this->lastEffectiveCitationCount = $effectiveCount;
        $selectionTarget                = min($itemCount, max(1, $contextEntryCount));

        $this->lastPipelineContext = [
            'pool_entry_count'   => $contextEntryCount,
            'context_entry_count' => $contextEntryCount,
            'item_count'         => $itemCount,
            'selection_target'   => $selectionTarget,
        ];

        $selectionModel = $this->selectionModel();
        $this->selectionProfile = $this->profileResolver->resolve($this->generationOptions, $userSystemPrompt);
        $this->globalFingerprintXml = '';
        if ($this->selectionProfile->useGlobalFingerprint) {
            $this->globalFingerprintXml = ResearcherGlobalFingerprint::buildXml(
                $contextEntries,
                new ResearcherEntryGatherer(),
                $moduleSelection,
            );
        }

        $this->lastGenerationMeta = array_merge($this->lastGenerationMeta, [
            'model'                       => $this->model,
            'summary_model'               => $this->model,
            'selection_model'             => $selectionModel,
            'selection_mode'              => $this->generationOptions->selectionMode(),
            'selection_profile'           => $this->selectionProfile->id,
            'selection_profile_requested' => $this->selectionProfile->requestedMode,
            'verification_auto_detected'  => $this->selectionProfile->verificationAutoDetected,
            'global_fingerprint'          => $this->globalFingerprintXml !== '',
            'tournament_mode'             => $this->generationOptions->tournamentMode(),
            'pro_selection_mode'          => $this->generationOptions->proSelectionMode,
            'pool_entry_count'            => $contextEntryCount,
            'item_count'                  => $itemCount,
            'selection_target'            => $selectionTarget,
            'max_output_tokens_configured' => $this->maxOutputTokens,
            'selection_batch_size_configured' => $this->researcherContext->selectionBatchSize(),
            'thinking_selection_configured' => self::THINKING_LEVEL_SELECTION,
            'thinking_selection_effective'  => $this->effectiveSelectionThinkingLevel(),
            'thinking_summary'              => self::THINKING_LEVEL_SUMMARY,
            'rate_limit_thinking'           => $this->rateLimitFallbackMode,
            'selection_strategy_planned'    => $this->plannedSelectionStrategy($contextEntryCount),
            'summary_strategy_planned'      => $this->plannedSummaryStrategy($effectiveCount),
        ]);

        return $this->generateSummaryTwoPass(
            $userSystemPrompt,
            $markdownContext,
            $itemCount,
            $effectiveCount,
            $contextEntries,
            $scoresByKey,
            $researcherMeta,
            $apiKey,
        );
    }

    /**
     * @param list<array<string, mixed>> $contextEntries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $researcherMeta
     * @return array{0: list<array<string, mixed>>, 1: string, 2: int}
     */
    private function shrinkContextForRateLimit(
        array $contextEntries,
        array $scoresByKey,
        array $researcherMeta,
        ?ResearcherSourceSelection $moduleSelection,
    ): array {
        $gatherer = new ResearcherEntryGatherer();
        if ($moduleSelection !== null) {
            $capped = ResearcherGeminiContext::capEntryListStratified(
                $contextEntries,
                $this->researcherContext->rateLimitFallbackMaxEntries(),
                $scoresByKey,
                $gatherer,
                $moduleSelection,
            );
            $entries = $capped['entries'];
            $guard   = new ResearcherModuleGuard($gatherer);
            $sealed  = $guard->sealGeminiContext(
                $entries,
                $scoresByKey,
                $this->metaWithEntryBodyBudget($researcherMeta, count($entries)),
                $moduleSelection,
            );

            return [$sealed['entries'], $sealed['markdown'], count($sealed['entries'])];
        }

        $capped  = ResearcherGeminiContext::capEntryList(
            $contextEntries,
            $this->researcherContext->rateLimitFallbackMaxEntries(),
        );
        $entries = $capped['entries'];
        $meta    = $this->metaWithEntryBodyBudget($researcherMeta, count($entries));
        $meta['total'] = count($entries);
        $markdown = MarkdownResearcherFormatter::format(
            $entries,
            $scoresByKey,
            $meta,
            true,
            MarkdownResearcherFormatter::FORMAT_XML,
        );

        return [$entries, $markdown, count($entries)];
    }

    /**
     * @param list<array<string, mixed>> $contextEntries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $researcherMeta
     * @return array{0: list<array<string, mixed>>, 1: string, 2: int}
     */
    private function sealContextForGemini(
        array $contextEntries,
        array $scoresByKey,
        array $researcherMeta,
        string $markdownContext,
        ?ResearcherSourceSelection $moduleSelection,
    ): array {
        if ($moduleSelection === null) {
            return [$contextEntries, $markdownContext, count($contextEntries)];
        }

        $guard  = new ResearcherModuleGuard(new ResearcherEntryGatherer());
        $sealed = $guard->sealGeminiContext(
            $contextEntries,
            $scoresByKey,
            $this->metaWithEntryBodyBudget($researcherMeta, count($contextEntries)),
            $moduleSelection,
        );

        return [$sealed['entries'], $sealed['markdown'], count($sealed['entries'])];
    }

    /**
     * @param list<array<string, mixed>> $contextEntries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $researcherMeta
     * @throws GeminiResearcherException
     */
    private function generateSummaryTwoPass(
        string $userSystemPrompt,
        string $markdownContext,
        int $itemCount,
        int $effectiveCount,
        array $contextEntries,
        array $scoresByKey,
        array $researcherMeta,
        string $apiKey,
    ): GeminiResearcherResult {
        $this->resetGeminiUsageTotals();

        $poolEntries     = $contextEntries;
        $poolCount       = count($poolEntries);
        $selectionTarget = min($effectiveCount, max(1, $poolCount));

        $poolContext = $this->buildEntryXmlContext($poolEntries, $scoresByKey, $researcherMeta, $markdownContext);

        $this->lastPipelineContext['pool_entry_count'] = $poolCount;
        $this->lastPipelineContext['selection_target'] = $selectionTarget;
        $this->mergeGenerationMeta([
            'pool_entry_count'   => $poolCount,
            'selection_target'   => $selectionTarget,
        ]);

        if ($this->selectionProfile->usesTournamentPipeline() && $poolCount >= 2 && !$this->rateLimitFallbackMode) {
            $selectedKeys = $this->runTournamentSelectionPasses(
                $userSystemPrompt,
                $poolEntries,
                $scoresByKey,
                $researcherMeta,
                $poolContext,
                $itemCount,
                $selectionTarget,
                $apiKey,
            );
        } elseif ($poolCount >= $this->batchedSelectionMinEntries()) {
            $selectedKeys = $this->runBatchedSelectionPasses(
                $userSystemPrompt,
                $poolEntries,
                $scoresByKey,
                $researcherMeta,
                $poolContext,
                $itemCount,
                $selectionTarget,
                $apiKey,
            );
            $this->mergeGenerationMeta([
                'batched_selection'    => true,
                'selection_strategy'   => 'rate_limit_batched_selection',
            ]);
        } else {
            $selectedKeys = $this->runSelectionPass(
                $userSystemPrompt,
                $poolContext,
                $itemCount,
                $selectionTarget,
                $apiKey,
                false,
            );
            $this->mergeGenerationMeta([
                'skinny_global_selection' => true,
                'selection_strategy'      => 'global_single_pass',
            ]);
        }

        $selectedKeys = $this->finalizeSelectedKeys($selectedKeys, $selectionTarget);

        $summaryEntries = $this->entriesForKeys($contextEntries, $selectedKeys);

        $finalCount = count($selectedKeys);
        $this->lastEffectiveCitationCount = max(1, $finalCount);
        $this->mergeGenerationMeta([
            'selected_entry_keys'  => $selectedKeys,
            'selection_keys_count' => $finalCount,
            'selection_completed'  => true,
        ]);

        $result = $this->runSummaryPassWithBatchFallback(
            $userSystemPrompt,
            $summaryEntries,
            $scoresByKey,
            $researcherMeta,
            $markdownContext,
            $selectedKeys,
            $itemCount,
            $finalCount,
            $apiKey,
        );
        $this->flushGeminiUsageToMeta();

        return $result;
    }

    private function composeSelectionSystemInstruction(
        string $userSystemPrompt,
        string $poolContext,
        int $itemCount,
        int $selectionTarget,
        bool $batchLocal = false,
    ): string {
        $contract = $batchLocal ? self::SELECTION_BATCH_OUTPUT_CONTRACT : self::SELECTION_OUTPUT_CONTRACT;
        $envelope = $this->expandContract($contract, [
            '{itemCount}'       => (string)$itemCount,
            '{maxCoreItems}'    => (string)$selectionTarget,
            '{markdownContext}' => trim($poolContext),
            '{temporalContext}' => $this->temporalContextBlock(),
        ]);

        $suffix = $this->selectionInstructionSuffix();

        return trim($userSystemPrompt) . "\n\n" . $envelope . $suffix;
    }

    private function selectionInstructionSuffix(): string
    {
        $parts = [];

        if ($this->globalFingerprintXml !== '') {
            $parts[] = 'GLOBAL POOL INDEX (all capped entries — titles/modules only; use for cross-batch and cross-module checks):'
                . "\n" . $this->globalFingerprintXml;
        }

        if ($this->selectionProfile->useNegativeSpaceContract) {
            $parts[] = self::RELATIONAL_NEGATIVE_SPACE_PROTOCOL;
        }

        if ($this->selectionProfile->verificationAutoDetected) {
            $parts[] = self::VERIFICATION_COMPLIANCE_APPENDIX;
        }

        if ($this->selectionProfile->keysOnlyJson) {
            $parts[] = 'Return JSON with used_entry_keys ONLY. Do not emit selection_reasoning.';
        } else {
            $parts[] = 'Emit used_entry_keys before any selection_reasoning text.';
        }

        return $parts === [] ? '' : "\n\n" . implode("\n\n", $parts);
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

        return MarkdownResearcherFormatter::format(
            $entries,
            $scoresByKey,
            $meta,
            true,
            MarkdownResearcherFormatter::FORMAT_XML,
        );
    }

    /**
     * @return list<string>
     * @throws GeminiResearcherException
     */
    /**
     * @return array<string, mixed>
     */
    private function buildSelectionPayload(
        string $userSystemPrompt,
        string $poolContext,
        int $itemCount,
        int $selectionTarget,
        bool $batchLocal,
        bool $forceKeysOnlySchema = false,
        bool $forceMinimalThinking = false,
    ): array {
        $systemText = $this->composeSelectionSystemInstruction(
            $userSystemPrompt,
            $poolContext,
            $itemCount,
            $selectionTarget,
            $batchLocal,
        );

        $userText = $batchLocal
            ? 'Wähle bis zu ' . $selectionTarget . ' Einträge nur aus diesem ENTRIES_DATA-Batch. '
                . 'Vergleiche jeden Eintrag im Block. Strikte Einhaltung des USER PROMPT (weniger ist korrekt). '
                . 'JSON: used_entry_keys in Researcher-Reihenfolge.'
            : 'Wähle bis zu ' . $selectionTarget . ' Einträge global aus ENTRIES_DATA. '
                . 'Strikte Einhaltung des USER PROMPT (weniger ist korrekt). '
                . 'JSON: used_entry_keys in Researcher-Reihenfolge.';

        return [
            'systemInstruction' => ['parts' => [['text' => $systemText]]],
            'contents'          => [['role' => 'user', 'parts' => [['text' => $userText]]]],
            'generationConfig'  => $this->selectionGenerationConfig(
                $itemCount,
                $selectionTarget,
                true,
                $batchLocal,
                $forceKeysOnlySchema,
                $forceMinimalThinking,
            ),
        ];
    }

    /**
     * @return list<string>
     * @throws GeminiResearcherException
     */
    private function runSelectionPass(
        string $userSystemPrompt,
        string $poolContext,
        int $itemCount,
        int $selectionTarget,
        string $apiKey,
        bool $batchLocal = false,
        bool $allowEmptyKeys = false,
        bool $forceKeysOnlySchema = false,
        bool $forceMinimalThinking = false,
    ): array {
        $payload  = $this->buildSelectionPayload(
            $userSystemPrompt,
            $poolContext,
            $itemCount,
            $selectionTarget,
            $batchLocal,
            $forceKeysOnlySchema,
            $forceMinimalThinking,
        );
        $response = $this->postPayloadWithSchemaFallback($payload, $apiKey, 'selection');

        return $this->resolveSelectionPassKeys($response, $payload, $apiKey, $allowEmptyKeys);
    }

    /**
     * Tournament prelims (parallel) + optional championship pass.
     *
     * @param list<array<string, mixed>> $poolEntries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $researcherMeta
     * @return list<string>
     * @throws GeminiResearcherException
     */
    private function runTournamentSelectionPasses(
        string $userSystemPrompt,
        array $poolEntries,
        array $scoresByKey,
        array $researcherMeta,
        string $fallbackXml,
        int $itemCount,
        int $selectionTarget,
        string $apiKey,
    ): array {
        $batchSize  = $this->researcherContext->selectionBatchSize();
        $batches    = ResearcherGeminiContext::chunkEntryList($poolEntries, $batchSize);
        $batchCount = count($batches);
        if ($batchCount === 0) {
            return [];
        }

        $this->mergeGenerationMeta([
            'tournament_selection'      => true,
            'batched_selection'         => true,
            'selection_strategy'        => $this->selectionProfile->id === GeminiResearcherSelectionProfile::RELATIONAL
                ? 'relational_tournament_parallel_batches'
                : ($batchCount > 1 ? 'tournament_parallel_batches' : 'tournament_batches'),
            'selection_batches'         => $batchCount,
            'selection_batch_size'      => $batchSize,
            'selection_batch_survivors' => self::TOURNAMENT_SURVIVORS_PER_BATCH,
            'selection_parallel'        => $batchCount > 1,
            'selection_championship'    => false,
        ]);

        /** @var list<array{poolContext: string, selectionTarget: int, batchLocal: bool, entries: list<array<string, mixed>>}> $jobs */
        $jobs = [];
        foreach ($batches as $batch) {
            $batchContext = $this->buildEntryXmlContext($batch, $scoresByKey, $researcherMeta, $fallbackXml);
            $jobs[]       = [
                'poolContext'      => $batchContext,
                'selectionTarget'  => self::tournamentSurvivorsForBatchSize(count($batch)),
                'batchLocal'       => $batchCount > 1,
                'entries'          => $batch,
            ];
        }

        $batchKeyLists = $batchCount > 1
            ? $this->runSelectionPassesParallel(
                $userSystemPrompt,
                $jobs,
                $itemCount,
                $apiKey,
                $scoresByKey,
                $researcherMeta,
                $fallbackXml,
            )
            : [
                $this->runSelectionPass(
                    $userSystemPrompt,
                    $jobs[0]['poolContext'],
                    $itemCount,
                    $jobs[0]['selectionTarget'],
                    $apiKey,
                    $jobs[0]['batchLocal'],
                    true,
                ),
            ];

        $merged = [];
        $seen   = [];
        $emptyBatches = 0;
        foreach ($batchKeyLists as $keys) {
            if ($keys === []) {
                $emptyBatches++;
            }
            foreach ($keys as $key) {
                $normalized = strtolower(trim($key));
                if ($normalized === '' || isset($seen[$normalized])) {
                    continue;
                }
                $seen[$normalized] = true;
                $merged[]          = $key;
            }
        }

        $this->mergeGenerationMeta([
            'selection_batches_empty' => $emptyBatches,
        ]);

        if ($merged === []) {
            throw GeminiResearcherException::invalidInput(
                'Tournament selection found no entries matching your prompt in any batch. '
                . 'Relax your criteria, widen the pool, or turn off tournament mode.',
            );
        }

        if (count($merged) <= $selectionTarget) {
            return $merged;
        }

        $finalists = $this->entriesForKeys($poolEntries, $merged);
        if ($finalists === []) {
            return array_slice($merged, 0, $selectionTarget);
        }

        $championContext = $this->buildEntryXmlContext(
            $finalists,
            $scoresByKey,
            $researcherMeta,
            $fallbackXml,
        );
        $championKeys = $this->runSelectionPass(
            $userSystemPrompt,
            $championContext,
            $itemCount,
            $selectionTarget,
            $apiKey,
            false,
            true,
        );

        $this->mergeGenerationMeta([
            'selection_championship' => true,
            'selection_finalists'    => count($finalists),
        ]);

        if ($championKeys !== []) {
            return $this->finalizeSelectedKeys($championKeys, $selectionTarget);
        }

        error_log(
            'GeminiResearcherService: championship returned no used_entry_keys; '
            . 'using tournament prelim order (' . count($merged) . ' finalists)'
        );
        $this->mergeGenerationMeta(['selection_championship_fallback' => true]);

        return array_slice($merged, 0, $selectionTarget);
    }

    /**
     * @param list<array{poolContext: string, selectionTarget: int, batchLocal: bool, entries: list<array<string, mixed>>}> $jobs
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $researcherMeta
     * @return list<list<string>>
     * @throws GeminiResearcherException
     */
    private function runSelectionPassesParallel(
        string $userSystemPrompt,
        array $jobs,
        int $itemCount,
        string $apiKey,
        array $scoresByKey,
        array $researcherMeta,
        string $fallbackXml,
    ): array {
        $payloads = [];
        foreach ($jobs as $job) {
            $payloads[] = $this->buildSelectionPayload(
                $userSystemPrompt,
                $job['poolContext'],
                $itemCount,
                $job['selectionTarget'],
                $job['batchLocal'],
            );
        }

        $responses = $this->postSelectionPayloadsParallel($payloads, $apiKey);

        $results      = [];
        $batchErrors  = [];
        foreach ($responses as $index => $response) {
            if (!$response->isOk()) {
                error_log(
                    'GeminiResearcherService: parallel selection batch ' . ($index + 1)
                    . ' failed with status ' . $response->status . '; retrying sequentially'
                );
                $response = $this->postPayloadWithSchemaFallback($payloads[$index], $apiKey, 'selection');
            }

            if (!$response->isOk()) {
                throw $this->exceptionFromFailedResponse($response);
            }

            $keys = [];
            $parseError = null;
            try {
                $keys = $this->resolveSelectionPassKeys(
                    $response,
                    $payloads[$index],
                    $apiKey,
                    true,
                );
            } catch (GeminiResearcherException $e) {
                $parseError = $e;
            }

            if ($keys === []) {
                $reason = $parseError !== null
                    ? $parseError->getMessage()
                    : 'Selection batch returned no used_entry_keys.';
                $keys = $this->retryTournamentBatchAfterFailure(
                    $userSystemPrompt,
                    $jobs[$index],
                    $itemCount,
                    $apiKey,
                    $scoresByKey,
                    $researcherMeta,
                    $fallbackXml,
                    $index + 1,
                    $reason,
                );
                if ($keys === []) {
                    error_log(
                        'GeminiResearcherService: tournament batch ' . ($index + 1)
                        . ' selection failed after retry: ' . $reason
                    );
                    $batchErrors[] = [
                        'batch'   => $index + 1,
                        'message' => $reason,
                    ];
                } else {
                    $batchErrors[] = [
                        'batch'     => $index + 1,
                        'message'   => $reason,
                        'recovered' => true,
                    ];
                }
            }

            $results[] = $keys;
        }

        if ($batchErrors !== []) {
            $this->lastGenerationMeta = array_merge($this->lastGenerationMeta, [
                'selection_batch_errors' => $batchErrors,
            ]);
        }

        return $results;
    }

    /**
     * @param array{poolContext: string, selectionTarget: int, batchLocal: bool, entries: list<array<string, mixed>>} $job
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $researcherMeta
     * @return list<string>
     */
    private function retryTournamentBatchAfterFailure(
        string $userSystemPrompt,
        array $job,
        int $itemCount,
        string $apiKey,
        array $scoresByKey,
        array $researcherMeta,
        string $fallbackXml,
        int $batchNumber,
        string $reason,
    ): array {
        $entries = $job['entries'];
        if ($entries === []) {
            return [];
        }

        error_log(
            'GeminiResearcherService: tournament batch ' . $batchNumber
            . ' retry after failure: ' . $reason
        );

        $retryCount = (int)($this->lastGenerationMeta['selection_batch_retries'] ?? 0);
        $this->mergeGenerationMeta(['selection_batch_retries' => $retryCount + 1]);

        try {
            $keys = $this->runSelectionPass(
                $userSystemPrompt,
                $job['poolContext'],
                $itemCount,
                $job['selectionTarget'],
                $apiKey,
                $job['batchLocal'],
                true,
                true,
                true,
            );
            if ($keys !== []) {
                $this->noteSelectionBatchRetry($batchNumber, 'keys_only_minimal');

                return $keys;
            }
        } catch (GeminiResearcherException) {
            // fall through to split retry
        }

        if (count($entries) <= 1) {
            return [];
        }

        $merged = [];
        $seen   = [];
        $halfSize = max(1, (int)ceil(count($entries) / 2));
        foreach (ResearcherGeminiContext::chunkEntryList($entries, $halfSize) as $subBatch) {
            $subContext = $this->buildEntryXmlContext($subBatch, $scoresByKey, $researcherMeta, $fallbackXml);
            try {
                $subKeys = $this->runSelectionPass(
                    $userSystemPrompt,
                    $subContext,
                    $itemCount,
                    self::tournamentSurvivorsForBatchSize(count($subBatch)),
                    $apiKey,
                    true,
                    true,
                    true,
                    true,
                );
            } catch (GeminiResearcherException) {
                continue;
            }
            foreach ($subKeys as $key) {
                $normalized = strtolower(trim($key));
                if ($normalized === '' || isset($seen[$normalized])) {
                    continue;
                }
                $seen[$normalized] = true;
                $merged[]          = $key;
            }
        }

        if ($merged === []) {
            return [];
        }

        $this->noteSelectionBatchRetry($batchNumber, 'split_halved');

        return array_slice($merged, 0, $job['selectionTarget']);
    }

    private function noteSelectionBatchRetry(int $batchNumber, string $strategy): void
    {
        $retries = $this->lastGenerationMeta['selection_batch_retry_log'] ?? [];
        if (!is_array($retries)) {
            $retries = [];
        }
        $retries[] = [
            'batch'    => $batchNumber,
            'strategy' => $strategy,
        ];
        $this->mergeGenerationMeta(['selection_batch_retry_log' => $retries]);
    }

    /**
     * @param list<array<string, mixed>> $payloads
     * @return list<Response>
     * @throws GeminiResearcherException
     */
    private function postSelectionPayloadsParallel(array $payloads, string $apiKey): array
    {
        if ($payloads === []) {
            return [];
        }

        if (!function_exists('curl_init') || !function_exists('curl_multi_init')) {
            $responses = [];
            foreach ($payloads as $payload) {
                $responses[] = $this->postPayloadWithSchemaFallback($payload, $apiKey, 'selection');
            }

            return $responses;
        }

        $model   = $this->selectionModel();
        $url     = self::API_BASE . rawurlencode($model) . ':generateContent';
        $timeout = self::HTTP_TIMEOUT_TWO_PASS_SECONDS;
        $mh      = curl_multi_init();
        if ($mh === false) {
            throw GeminiResearcherException::transportFailed();
        }

        /** @var list<\CurlHandle> $handles */
        $handles = [];
        foreach ($payloads as $payload) {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
            $ch   = curl_init($url);
            if ($ch === false) {
                foreach ($handles as $handle) {
                    curl_multi_remove_handle($mh, $handle);
                    curl_close($handle);
                }
                curl_multi_close($mh);
                throw GeminiResearcherException::transportFailed();
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
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $responses = [];
        foreach ($handles as $ch) {
            $raw    = curl_multi_getcontent($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($raw === false || $raw === '') {
                $raw = '';
            }
            $responses[] = new Response($status, $raw, [], $url);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);

        return $responses;
    }

    private function selectionModel(): string
    {
        if ($this->generationOptions->proSelectionMode) {
            return self::MODEL_GEMINI_31_PRO_PREVIEW;
        }

        return $this->model;
    }

    /**
     * Selection over entry chunks to stay under Gemini TPM/RPM per request.
     *
     * @param list<array<string, mixed>> $poolEntries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $researcherMeta
     * @return list<string>
     * @throws GeminiResearcherException
     */
    private function runBatchedSelectionPasses(
        string $userSystemPrompt,
        array $poolEntries,
        array $scoresByKey,
        array $researcherMeta,
        string $fallbackXml,
        int $itemCount,
        int $selectionTarget,
        string $apiKey,
    ): array {
        $batchSize  = $this->effectiveSelectionBatchSize();
        $batches    = ResearcherGeminiContext::chunkEntryList($poolEntries, $batchSize);
        $batchCount = count($batches);
        if ($batchCount === 0) {
            return [];
        }

        $merged = [];
        $seen   = [];

        foreach ($batches as $index => $batch) {
            if ($index > 0) {
                sleep($this->batchPauseSeconds());
            }

            $batchContext = $this->buildEntryXmlContext($batch, $scoresByKey, $researcherMeta, $fallbackXml);
            $batchTarget  = min($selectionTarget, count($batch));
            $keys         = $this->runSelectionPass(
                $userSystemPrompt,
                $batchContext,
                $itemCount,
                $batchTarget,
                $apiKey,
                false,
            );

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
            'batched_selection'       => true,
            'selection_batches'       => $batchCount,
            'selection_batch_size'    => $batchSize,
            'selection_championship'  => false,
        ]);

        if (count($merged) <= $selectionTarget) {
            return $merged;
        }

        $finalists = $this->entriesForKeys($poolEntries, $merged);
        if ($finalists === []) {
            return array_slice($merged, 0, $selectionTarget);
        }

        sleep($this->batchPauseSeconds());
        $championContext = $this->buildEntryXmlContext(
            $finalists,
            $scoresByKey,
            $researcherMeta,
            $fallbackXml,
        );
        $championKeys = $this->runSelectionPass(
            $userSystemPrompt,
            $championContext,
            $itemCount,
            $selectionTarget,
            $apiKey,
            false,
        );

        $this->lastGenerationMeta = array_merge($this->lastGenerationMeta, [
            'selection_championship' => true,
            'selection_finalists'    => count($finalists),
        ]);

        if ($championKeys !== []) {
            return $this->finalizeSelectedKeys($championKeys, $selectionTarget);
        }

        return array_slice($merged, 0, $selectionTarget);
    }

    /**
     * @param list<array<string, mixed>> $summaryEntries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $researcherMeta
     * @param list<string> $selectedKeys
     * @throws GeminiResearcherException
     */
    private function runSummaryPassWithBatchFallback(
        string $userSystemPrompt,
        array $summaryEntries,
        array $scoresByKey,
        array $researcherMeta,
        string $fallbackXml,
        array $selectedKeys,
        int $itemCount,
        int $effectiveCount,
        string $apiKey,
    ): GeminiResearcherResult {
        $proactiveMin = $this->selectionProfile->usesTournamentPipeline()
            || $this->selectionProfile->id === GeminiResearcherSelectionProfile::RELATIONAL
            ? self::PROACTIVE_SUMMARY_BATCH_MIN_ITEMS_TOURNAMENT
            : self::PROACTIVE_SUMMARY_BATCH_MIN_ITEMS;

        if ($effectiveCount >= $proactiveMin) {
            $batchSize = min(self::SUMMARY_BATCH_SIZE, $effectiveCount);
            error_log(
                'GeminiResearcherService: proactive batched pass 2 for ' . $effectiveCount
                . ' cited item(s) (batch size ' . $batchSize . ')'
            );
            $this->mergeGenerationMeta([
                'summary_proactive_batch' => true,
                'summary_strategy'        => 'proactive_batched',
                'summary_batch_size'      => $batchSize,
            ]);

            return $this->runBatchedSummaryPasses(
                $userSystemPrompt,
                $summaryEntries,
                $scoresByKey,
                $researcherMeta,
                $fallbackXml,
                $selectedKeys,
                $itemCount,
                $apiKey,
                $batchSize,
            );
        }

        $summaryContext = $this->buildSummaryContextForKeys(
            $summaryEntries,
            $scoresByKey,
            $researcherMeta,
            $fallbackXml,
            $selectedKeys,
        );

        $this->mergeGenerationMeta([
            'summary_strategy' => 'monolithic_single_pass',
        ]);

        try {
            $result = $this->runSummaryPass(
                $userSystemPrompt,
                $summaryContext,
                $selectedKeys,
                $itemCount,
                $effectiveCount,
                $apiKey,
                true,
            );
            $this->mergeGenerationMeta(['summary_completed' => true]);

            return $result;
        } catch (GeminiResearcherException $e) {
            if (!$this->shouldRetrySummaryInBatches($e, $effectiveCount)) {
                throw $e;
            }
        }

        return $this->retrySummaryInBatches(
            $userSystemPrompt,
            $summaryEntries,
            $scoresByKey,
            $researcherMeta,
            $fallbackXml,
            $selectedKeys,
            $itemCount,
            $effectiveCount,
            $apiKey,
        );
    }

    /**
     * @param list<string> $selectedKeys
     * @throws GeminiResearcherException
     */
    private function retrySummaryInBatches(
        string $userSystemPrompt,
        array $summaryEntries,
        array $scoresByKey,
        array $researcherMeta,
        string $fallbackXml,
        array $selectedKeys,
        int $itemCount,
        int $effectiveCount,
        string $apiKey,
    ): GeminiResearcherResult {
        $batchSize = min(self::SUMMARY_BATCH_SIZE, max(1, $effectiveCount));
        error_log(
            'GeminiResearcherService: pass 2 output truncated for ' . $effectiveCount
            . ' cited item(s); retrying with batched summary (batch size ' . $batchSize . ')'
        );
        $this->mergeGenerationMeta([
            'summary_batch_retry_attempted' => true,
            'summary_strategy'              => 'reactive_batched',
            'summary_batch_retry_reason'    => 'output_truncated',
            'summary_batch_retry_size'      => $batchSize,
        ]);

        return $this->runBatchedSummaryPasses(
            $userSystemPrompt,
            $summaryEntries,
            $scoresByKey,
            $researcherMeta,
            $fallbackXml,
            $selectedKeys,
            $itemCount,
            $apiKey,
            $batchSize,
        );
    }

    private function shouldRetrySummaryInBatches(GeminiResearcherException $e, int $effectiveCount): bool
    {
        if ($effectiveCount < 1) {
            return false;
        }

        if ($e->shouldRetryWithBatchedSummary()) {
            return true;
        }

        if ($effectiveCount < 2) {
            return false;
        }

        // Pass 2 sometimes returns malformed / empty content without an explicit MAX_TOKENS finish reason.
        // When multiple items are expected, a batched retry is often the safest recovery.
        if ($e->httpStatus !== null && $e->httpStatus !== 400) {
            return false;
        }

        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'summary pass')
            || str_contains($msg, 'unexpected response')
            || str_contains($msg, 'no summary')
            || str_contains($msg, 'candidate missing content')
            || str_contains($msg, 'no text parts')
            || str_contains($msg, 'no candidates');
    }

    /**
     * @param list<array<string, mixed>> $summaryEntries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $researcherMeta
     * @param list<string> $selectedKeys
     */
    private function buildSummaryContextForKeys(
        array $summaryEntries,
        array $scoresByKey,
        array $researcherMeta,
        string $fallbackXml,
        array $selectedKeys,
    ): string {
        if ($summaryEntries === []) {
            return $this->filterXmlContextByKeys($fallbackXml, $selectedKeys);
        }

        $subset = $this->entriesForKeys($summaryEntries, $selectedKeys);
        if ($subset === []) {
            return $this->filterXmlContextByKeys($fallbackXml, $selectedKeys);
        }

        $meta             = $researcherMeta;
        $meta['total']    = count($subset);
        $meta['selected'] = count($selectedKeys);
        unset($meta['use_recipe_snippets']); // Pass 2 summary pass always uses full text bodies

        return $this->buildEntryXmlContext($subset, $scoresByKey, $meta, $fallbackXml);
    }

    /**
     * @param list<array<string, mixed>> $summaryEntries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $researcherMeta
     * @param list<string> $selectedKeys
     * @throws GeminiResearcherException
     */
    private function runBatchedSummaryPasses(
        string $userSystemPrompt,
        array $summaryEntries,
        array $scoresByKey,
        array $researcherMeta,
        string $fallbackXml,
        array $selectedKeys,
        int $itemCount,
        string $apiKey,
        int $batchSize = self::SUMMARY_BATCH_SIZE,
    ): GeminiResearcherResult {
        try {
            return $this->runBatchedSummaryPassesCore(
                $userSystemPrompt,
                $summaryEntries,
                $scoresByKey,
                $researcherMeta,
                $fallbackXml,
                $selectedKeys,
                $itemCount,
                $apiKey,
                $batchSize,
            );
        } catch (GeminiResearcherException $e) {
            if (!$e->isOutputTruncated() || $batchSize <= 1) {
                throw $batchSize <= 1 && $e->isOutputTruncated()
                    ? GeminiResearcherException::outputTruncatedAfterBatching()
                    : $e;
            }
        }

        error_log(
            'GeminiResearcherService: batched summary still truncated at batch size ' . $batchSize
            . '; retrying one item per request'
        );

        return $this->runBatchedSummaryPassesCore(
            $userSystemPrompt,
            $summaryEntries,
            $scoresByKey,
            $researcherMeta,
            $fallbackXml,
            $selectedKeys,
            $itemCount,
            $apiKey,
            1,
        );
    }

    /**
     * @param list<array<string, mixed>> $summaryEntries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $researcherMeta
     * @param list<string> $selectedKeys
     * @throws GeminiResearcherException
     */
    private function runBatchedSummaryPassesCore(
        string $userSystemPrompt,
        array $summaryEntries,
        array $scoresByKey,
        array $researcherMeta,
        string $fallbackXml,
        array $selectedKeys,
        int $itemCount,
        string $apiKey,
        int $batchSize,
    ): GeminiResearcherResult {
        $batchSize  = max(1, $batchSize);
        $batches    = array_chunk($selectedKeys, $batchSize);
        $batchCount = count($batches);
        $parts      = [];

        foreach ($batches as $index => $keyBatch) {
            if ($index > 0) {
                sleep($this->batchPauseSeconds());
            }

            $batchCountKeys = count($keyBatch);
            $batchContext   = $this->buildSummaryContextForKeys(
                $summaryEntries,
                $scoresByKey,
                $researcherMeta,
                $fallbackXml,
                $keyBatch,
            );

            $part = $this->runSummaryPass(
                $userSystemPrompt,
                $batchContext,
                $keyBatch,
                $itemCount,
                $batchCountKeys,
                $apiKey,
                $index === 0,
                $index + 1,
                $batchCount,
                true,
            );
            $parts[] = trim($part->markdown);
        }

        $merged = trim(implode("\n\n", array_filter($parts, static fn(string $p): bool => $p !== '')));
        if ($merged === '') {
            throw GeminiResearcherException::emptyResponse('Batched summary passes returned no Markdown.');
        }

        $this->mergeGenerationMeta([
            'batched_summary'       => true,
            'summary_completed'     => true,
            'summary_batches'       => $batchCount,
            'summary_batch_size'    => $batchSize,
            'summary_output_tokens' => self::resolveOutputTokenBudget(
                max(1, $batchSize),
                $this->maxOutputTokens,
                $this->model,
            ),
        ]);

        return new GeminiResearcherResult($merged, $selectedKeys, true);
    }

    /**
     * @param array<string, mixed> $patch
     */
    private function mergeGenerationMeta(array $patch): void
    {
        $this->lastGenerationMeta = array_merge($this->lastGenerationMeta, $patch);
    }

    private function resetGeminiUsageTotals(): void
    {
        $this->usagePromptTokens      = 0;
        $this->usageOutputTokens      = 0;
        $this->usageFlashPromptTokens = 0;
        $this->usageFlashOutputTokens = 0;
        $this->usageProPromptTokens   = 0;
        $this->usageProOutputTokens   = 0;
        $this->usageApiCalls          = 0;
        $this->usageByPhase           = [];
        $this->lastFinishReasonByPhase = [];
    }

    private function flushGeminiUsageToMeta(): void
    {
        if ($this->usageApiCalls < 1) {
            return;
        }

        $this->mergeGenerationMeta([
            'gemini_usage' => [
                'prompt_tokens'        => $this->usagePromptTokens,
                'output_tokens'        => $this->usageOutputTokens,
                'total_tokens'         => $this->usagePromptTokens + $this->usageOutputTokens,
                'flash_prompt_tokens'  => $this->usageFlashPromptTokens,
                'flash_output_tokens'  => $this->usageFlashOutputTokens,
                'pro_prompt_tokens'    => $this->usageProPromptTokens,
                'pro_output_tokens'    => $this->usageProOutputTokens,
                'api_calls'            => $this->usageApiCalls,
                'by_phase'             => $this->usageByPhase,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $json
     */
    private function recordGeminiUsageFromJson(array $json, string $phase): void
    {
        $usage = $json['usageMetadata'] ?? null;
        if (!is_array($usage)) {
            return;
        }

        $prompt = max(0, (int)($usage['promptTokenCount'] ?? 0));
        $output = max(0, (int)($usage['candidatesTokenCount'] ?? 0))
            + max(0, (int)($usage['thoughtsTokenCount'] ?? 0));

        $model = $phase === 'selection' ? $this->selectionModel() : $this->model;
        $isFlash = GeminiResearcherFlashPricing::isFlashModel($model);

        $this->usagePromptTokens += $prompt;
        $this->usageOutputTokens += $output;
        $this->usageApiCalls++;
        if ($isFlash) {
            $this->usageFlashPromptTokens += $prompt;
            $this->usageFlashOutputTokens += $output;
        } else {
            $this->usageProPromptTokens += $prompt;
            $this->usageProOutputTokens += $output;
        }

        if (!isset($this->usageByPhase[$phase])) {
            $this->usageByPhase[$phase] = [
                'prompt_tokens' => 0,
                'output_tokens' => 0,
                'api_calls'     => 0,
            ];
        }
        $this->usageByPhase[$phase]['prompt_tokens'] += $prompt;
        $this->usageByPhase[$phase]['output_tokens'] += $output;
        $this->usageByPhase[$phase]['api_calls']++;

        $candidates = $json['candidates'] ?? null;
        if (is_array($candidates) && isset($candidates[0]) && is_array($candidates[0])) {
            $finishReason = trim((string)($candidates[0]['finishReason'] ?? ''));
            if ($finishReason !== '') {
                $this->lastFinishReasonByPhase[$phase] = $finishReason;
                $this->usageByPhase[$phase]['finish_reason'] = $finishReason;
            }
        }
    }

    private function lastFinishReasonForPhase(string $phase): string
    {
        return $this->lastFinishReasonByPhase[$phase] ?? '';
    }

    private function effectiveSelectionThinkingLevel(): string
    {
        if ($this->rateLimitFallbackMode) {
            return 'MINIMAL';
        }

        return $this->generationOptions->tournamentMode()
            ? self::THINKING_LEVEL_SUMMARY
            : self::THINKING_LEVEL_SELECTION;
    }

    private function plannedSelectionStrategy(int $poolCount): string
    {
        if ($this->rateLimitFallbackMode) {
            return 'rate_limit_batched_selection';
        }

        if ($this->selectionProfile->id === GeminiResearcherSelectionProfile::RELATIONAL && $poolCount >= 2) {
            return 'relational_tournament_parallel_batches';
        }

        if ($this->selectionProfile->usesTournamentPipeline() && $poolCount >= 2) {
            return 'tournament_parallel_batches';
        }

        if ($poolCount >= $this->batchedSelectionMinEntries()) {
            return 'rate_limit_batched_selection';
        }

        return 'global_single_pass';
    }

    private function plannedSummaryStrategy(int $effectiveCount): string
    {
        $proactiveMin = $this->selectionProfile->usesTournamentPipeline()
            || $this->selectionProfile->id === GeminiResearcherSelectionProfile::RELATIONAL
            ? self::PROACTIVE_SUMMARY_BATCH_MIN_ITEMS_TOURNAMENT
            : self::PROACTIVE_SUMMARY_BATCH_MIN_ITEMS;

        if ($effectiveCount >= $proactiveMin) {
            return 'proactive_batched';
        }

        return 'monolithic_single_pass';
    }

    /**
     * @param list<string> $selectedKeys
     * @throws GeminiResearcherException
     */
    private function runSummaryPass(
        string $userSystemPrompt,
        string $summaryContext,
        array $selectedKeys,
        int $itemCount,
        int $effectiveCount,
        string $apiKey,
        bool $includeFullResearcherIntro = true,
        int $batchIndex = 1,
        int $batchCount = 1,
        bool $allowTruncatedPartial = false,
    ): GeminiResearcherResult {
        $keysBlock = implode("\n", $selectedKeys);
        $envelope  = $this->expandContract(self::SUMMARY_OUTPUT_CONTRACT, [
            '{selectedEntryKeys}' => $keysBlock,
            '{markdownContext}'   => trim($summaryContext),
            '{temporalContext}'   => $this->temporalContextBlock(),
        ]);
        $systemText = trim($userSystemPrompt) . "\n\n" . $envelope;

        if ($batchCount > 1 && !$includeFullResearcherIntro) {
            $userText = 'Setze das Briefing fort (Teil ' . $batchIndex . ' von ' . $batchCount . '). '
                . 'KEINE Hauptüberschrift (# …), KEINE Zwischenüberschriften (### …), KEINE Zusammenfassung, KEIN Radar/Ausblick. '
                . 'Schreibe AUSSCHLIESSLICH die reinen Entwicklungs-Bullets (`* **...**`) ohne umschliessende Überschrift für genau diese ' . $effectiveCount
                . ' SELECTED_ENTRY_KEYS in dieser Reihenfolge: '
                . implode(', ', $selectedKeys) . '. '
                . 'Zitiere jedes Item mit entry_type:entry_id in Klammern.';
        } else {
            $userText = 'Schreibe das vollständige Briefing als plain Markdown. '
                . 'Decke alle ' . $effectiveCount . ' SELECTED_ENTRY_KEYS in dieser Reihenfolge ab. '
                . 'Zitiere jedes Item mit entry_type:entry_id in Klammern.';
            if ($batchCount > 1 && $includeFullResearcherIntro) {
                $userText .= ' Teil 1 von ' . $batchCount . ': nur Überschrift (# …) und die Bullets für die obigen Keys'
                    . ' — keine Zusammenfassung, kein Radar/Ausblick.';
            } elseif ($batchCount > 1) {
                $userText .= ' (Teil ' . $batchIndex . ' von ' . $batchCount . ' — nur die obigen Keys in diesem Aufruf.)';
            }
        }

        $generationConfig = $this->summaryGenerationConfig($itemCount, $effectiveCount);
        $payload          = [
            'systemInstruction' => ['parts' => [['text' => $systemText]]],
            'contents'          => [['role' => 'user', 'parts' => [['text' => $userText]]]],
            'generationConfig'  => $generationConfig,
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

        $enforceHeuristic = $batchCount <= 1 && !$allowTruncatedPartial;

        return $this->parseSummaryResponse(
            $response,
            $selectedKeys,
            $enforceHeuristic,
            $allowTruncatedPartial,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @throws GeminiResearcherException
     */
    private function postPayloadWithSchemaFallback(array $payload, string $apiKey, string $phase): Response
    {
        $model    = $phase === 'selection' ? $this->selectionModel() : $this->model;
        $url      = self::API_BASE . rawurlencode($model) . ':generateContent';
        $response = $this->postWithRetries($url, $payload, $apiKey, $phase === 'selection' || $phase === 'summary');

        if (!$response->isOk() && $this->shouldRetryWithoutResponseSchema($response)) {
            error_log(
                'GeminiResearcherService: responseSchema rejected for ' . $phase
                . ' on model ' . $model . '; retrying without schema'
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
    private function selectionGenerationConfig(
        int $itemCount,
        int $effectiveCount,
        bool $useStructuredSchema,
        bool $batchLocal = false,
        bool $forceKeysOnlySchema = false,
        bool $forceMinimalThinking = false,
    ): array {
        $selectionModel = $this->selectionModel();
        $tokenBudget      = $batchLocal
            ? self::resolveTournamentBatchSelectionTokenBudget($effectiveCount, $this->maxOutputTokens, $selectionModel)
            : self::resolveSelectionPassTokenBudget($itemCount, $this->maxOutputTokens, $selectionModel);
        $config           = $this->applyModelGenerationDefaults([
            'maxOutputTokens' => $tokenBudget,
            'responseMimeType' => 'application/json',
        ], 'selection', $itemCount, $selectionModel);
        if ($forceMinimalThinking && self::usesGemini35Family($selectionModel)) {
            $config['thinkingConfig'] = ['thinkingLevel' => 'MINIMAL'];
        }
        if ($forceKeysOnlySchema && self::usesGemini35Family($selectionModel)) {
            $config['responseSchema'] = $this->selectionResponseSchemaKeysOnly($effectiveCount);
        } elseif ($useStructuredSchema && self::usesGemini35Family($selectionModel)) {
            $config['responseSchema'] = $this->selectionResponseSchema($effectiveCount, $this->selectionProfile);
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function selectionPayloadWithoutSchema(array $payload): array
    {
        $config = $payload['generationConfig'] ?? null;
        if (!is_array($config)) {
            return $payload;
        }
        unset($config['responseSchema']);
        $payload['generationConfig'] = $config;

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     * @throws GeminiResearcherException
     */
    private function resolveSelectionPassKeys(
        Response $response,
        array $payload,
        string $apiKey,
        bool $allowEmptyKeys,
    ): array {
        try {
            $keys = $this->parseSelectionResponse($response, $allowEmptyKeys);
        } catch (GeminiResearcherException $e) {
            $finishReason = $this->lastFinishReasonForPhase('selection');
            error_log(
                'GeminiResearcherService: selection pass failed'
                . ($finishReason !== '' ? ' (finish: ' . $finishReason . ')' : '')
                . ': ' . $e->getMessage()
            );
            $this->mergeGenerationMeta([
                'selection_pass_failed' => true,
                'selection_finish_reason' => $finishReason !== '' ? $finishReason : null,
            ]);

            throw $e;
        }

        if ($keys === [] && !$allowEmptyKeys) {
            $finishReason = $this->lastFinishReasonForPhase('selection');
            error_log(
                'GeminiResearcherService: selection pass returned no used_entry_keys'
                . ($finishReason !== '' ? ' (finish: ' . $finishReason . ')' : '')
            );
            $this->mergeGenerationMeta([
                'selection_pass_failed' => true,
                'selection_finish_reason' => $finishReason !== '' ? $finishReason : null,
            ]);

            throw GeminiResearcherException::emptyResponse('Selection pass returned no used_entry_keys.');
        }

        return $keys;
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryGenerationConfig(int $itemCount, int $effectiveCount): array
    {
        return $this->applyModelGenerationDefaults([
            'maxOutputTokens' => self::resolveOutputTokenBudget($effectiveCount, $this->maxOutputTokens, $this->model),
            'responseMimeType' => 'text/plain',
        ], 'summary', $itemCount, $this->model);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function applyModelGenerationDefaults(
        array $config,
        string $phase,
        int $itemCount,
        string $model,
    ): array {
        $config['maxOutputTokens'] = min(
            (int)($config['maxOutputTokens'] ?? $this->maxOutputTokens),
            self::modelHardOutputCapFor($model),
        );
        $thinking = $this->thinkingConfigForPhase($phase, $itemCount, $model);
        if ($thinking !== []) {
            $config['thinkingConfig'] = $thinking;
        }

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function thinkingConfigForPhase(string $phase, int $itemCount, string $model): array
    {
        if ($this->rateLimitFallbackMode) {
            return self::usesGemini35Family($model) ? ['thinkingLevel' => 'MINIMAL'] : [];
        }

        if (!self::usesGemini35Family($model)) {
            return [];
        }

        $level = match ($phase) {
            'selection' => $this->selectionProfile->usesTournamentPipeline()
                || $this->selectionProfile->id === GeminiResearcherSelectionProfile::RELATIONAL
                ? self::THINKING_LEVEL_SUMMARY
                : self::THINKING_LEVEL_SELECTION,
            'summary'   => self::THINKING_LEVEL_SUMMARY,
            default     => self::THINKING_LEVEL_SUMMARY,
        };

        return ['thinkingLevel' => $level];
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function metaWithEntryBodyBudget(array $meta, int $entryCount): array
    {
        $meta['entry_body_max_chars'] = MarkdownResearcherFormatter::dynamicEntryBodyMaxChars($entryCount);

        return $meta;
    }

    private function temporalContextBlock(): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Zurich'));

        return 'CURRENT CONTEXT:' . "\n"
            . 'Today is ' . $now->format('l') . ', ' . $now->format('F j, Y')
            . ' (Europe/Zurich). Use natural relative dates from each entry <published_date> when writing the researcher.';
    }

    /**
     * @param array<string, string> $replacements
     */
    private function expandContract(string $contract, array $replacements): string
    {
        return str_replace(array_keys($replacements), array_values($replacements), $contract);
    }

    /**
     * @return array<string, mixed>
     */
    private function selectionResponseSchema(int $selectionTarget, GeminiResearcherSelectionProfile $profile): array
    {
        $keysSchema = [
            'type'        => 'ARRAY',
            'description' => 'Up to ' . $selectionTarget . ' entry_type:entry_id values from ENTRIES_DATA <id>, researcher order.',
            'items'       => ['type' => 'STRING'],
            'minItems'    => 0,
            'maxItems'    => $selectionTarget,
        ];

        if ($profile->keysOnlyJson || !$profile->includeSelectionReasoning) {
            return $this->selectionResponseSchemaKeysOnly($selectionTarget);
        }

        return [
            'type'       => 'OBJECT',
            'properties' => [
                'used_entry_keys'     => $keysSchema,
                'selection_reasoning' => [
                    'type'        => 'STRING',
                    'description' => 'Brief rationale after used_entry_keys.',
                ],
            ],
            'required' => ['used_entry_keys'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function selectionResponseSchemaVerificationInline(int $selectionTarget): array
    {
        return [
            'type'       => 'OBJECT',
            'properties' => [
                'used_entries' => [
                    'type'        => 'ARRAY',
                    'description' => 'Selected entries with inline verification rationale (max 15 words each).',
                    'items'       => [
                        'type'       => 'OBJECT',
                        'properties' => [
                            'key' => [
                                'type'        => 'STRING',
                                'description' => 'entry_type:entry_id from ENTRIES_DATA <id>.',
                            ],
                            'verification_rationale' => [
                                'type'        => 'STRING',
                                'description' => 'Max 15 words: why this entry passes USER PROMPT verification.',
                            ],
                        ],
                        'required' => ['key'],
                    ],
                    'minItems' => 0,
                    'maxItems' => $selectionTarget,
                ],
            ],
            'required' => ['used_entries'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function selectionResponseSchemaKeysOnly(int $selectionTarget): array
    {
        return [
            'type'       => 'OBJECT',
            'properties' => [
                'used_entry_keys' => [
                    'type'        => 'ARRAY',
                    'description' => 'Up to ' . $selectionTarget . ' entry_type:entry_id values from ENTRIES_DATA <id>, researcher order.',
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
                'researcher_markdown' => [
                    'type'        => 'STRING',
                    'description' => 'Complete executive researcher Markdown covering all ' . $effectiveCount
                        . ' selected entries in SELECTED_ENTRY_KEYS order.',
                ],
            ],
            'required' => ['researcher_markdown'],
        ];
    }

    /**
     * @return list<string>
     * @throws GeminiResearcherException
     */
    private function parseSelectionResponse(Response $response, bool $allowEmptyKeys = false): array
    {
        $raw     = $this->extractCandidateText($response, true, 'selection');
        $decoded = $this->decodeResearcherJsonObject($raw);
        if ($decoded === null) {
            throw GeminiResearcherException::badResponse('Selection pass JSON could not be parsed.');
        }

        $reasoning = trim((string)($decoded['selection_reasoning'] ?? ''));
        if ($reasoning !== '') {
            $this->lastGenerationMeta['selection_reasoning'] = $reasoning;
        }

        $verificationRationales = $this->extractVerificationRationalesFromDecoded($decoded);
        if ($verificationRationales !== []) {
            $this->lastGenerationMeta['selection_verification_rationales'] = $verificationRationales;
        }

        $keys = $this->extractUsedEntryKeysFromDecoded($decoded);
        if ($keys === [] && !$allowEmptyKeys) {
            throw GeminiResearcherException::emptyResponse('Selection pass returned no used_entry_keys.');
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return list<string>
     */
    private function extractUsedEntryKeysFromDecoded(array $decoded): array
    {
        $fromInline = $this->extractKeysFromUsedEntries($decoded['used_entries'] ?? null);
        if ($fromInline !== []) {
            return $fromInline;
        }

        foreach (['used_entry_keys', 'selected_entry_keys', 'entry_keys', 'keys'] as $field) {
            $keys = $this->normalizeUsedEntryKeys($decoded[$field] ?? null);
            if ($keys !== []) {
                return $keys;
            }
        }

        if (array_is_list($decoded)) {
            return $this->normalizeUsedEntryKeys($decoded);
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function extractKeysFromUsedEntries(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $keys = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = trim((string)($row['key'] ?? ''));
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return $this->normalizeUsedEntryKeys($keys);
    }

    /**
     * @return array<string, string>
     */
    private function extractVerificationRationalesFromDecoded(array $decoded): array
    {
        $raw = $decoded['used_entries'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $rationales = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = trim((string)($row['key'] ?? ''));
            $text = trim((string)($row['verification_rationale'] ?? ''));
            if ($key !== '' && $text !== '') {
                $rationales[$key] = $text;
            }
        }

        return $rationales;
    }

    /**
     * @param list<string> $selectedKeys
     * @throws GeminiResearcherException
     */
    private function parseSummaryResponse(
        Response $response,
        array $selectedKeys,
        bool $enforceLengthHeuristic = true,
        bool $allowTruncatedPartial = false,
    ): GeminiResearcherResult {
        $raw = $this->extractCandidateText($response, $allowTruncatedPartial, 'summary');
        if (!str_starts_with(trim($raw), '{')) {
            $markdown = trim($raw);
            if ($markdown === '') {
                throw GeminiResearcherException::emptyResponse('Summary pass returned empty Markdown.');
            }
            $result = new GeminiResearcherResult($markdown, $selectedKeys, true);
            $this->noteResearcherLengthHeuristic($result, $enforceLengthHeuristic);

            return $result;
        }

        $decoded = $this->decodeResearcherJsonObject($raw);
        if ($decoded !== null) {
            $markdown = trim((string)($decoded['researcher_markdown'] ?? ''));
            if ($markdown === '') {
                throw GeminiResearcherException::emptyResponse('Summary pass missing researcher_markdown.');
            }

            $result = new GeminiResearcherResult($markdown, $selectedKeys, true);
            $this->noteResearcherLengthHeuristic($result, $enforceLengthHeuristic);

            return $result;
        }

        $jsonText = LenientJsonParser::extractMarkdownJson($raw);
        $salvaged = $this->salvageResearcherFromBrokenJson($jsonText);
        if ($salvaged !== null) {
            error_log('GeminiResearcherService summary pass: recovered markdown from broken JSON.');
            $result = new GeminiResearcherResult($salvaged->markdown, $selectedKeys, false);
            $this->noteResearcherLengthHeuristic($result, $enforceLengthHeuristic);

            return $result;
        }

        throw GeminiResearcherException::badResponse('Summary pass JSON could not be parsed.');
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
                'GeminiResearcherService: selection returned ' . count($selectedKeys)
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

        return '<seismo_researcher selected_only="true"><entries>' . implode('', $blocks) . '</entries></seismo_researcher>';
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
            return ResearcherGeminiContext::RATE_LIMIT_BATCHED_SELECTION_MIN_ENTRIES;
        }

        return ResearcherGeminiContext::BATCHED_SELECTION_MIN_ENTRIES;
    }

    private function effectiveSelectionBatchSize(): int
    {
        if ($this->rateLimitFallbackMode) {
            return ResearcherGeminiContext::RATE_LIMIT_FALLBACK_BATCH_SIZE;
        }

        return $this->researcherContext->selectionBatchSize();
    }

    private function batchPauseSeconds(): int
    {
        if ($this->rateLimitFallbackMode) {
            return ResearcherGeminiContext::RATE_LIMIT_BATCH_PAUSE_SECONDS;
        }

        return ResearcherGeminiContext::BATCH_PAUSE_SECONDS;
    }

    /**
     * @param array<string, mixed> $payload
     * @throws GeminiResearcherException
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
                    error_log('GeminiResearcherService transport: ' . $e->getMessage());
                    throw GeminiResearcherException::transportFailed();
                }
                $this->sleepBeforeRetry($attempt);

                continue;
            } catch (\JsonException $e) {
                error_log('GeminiResearcherService request JSON: ' . $e->getMessage());
                throw GeminiResearcherException::transportFailed();
            }

            if ($response->status === 429) {
                return $response;
            }

            if ($this->isTransientHttp($response->status) && $attempt < $attempts - 1) {
                error_log(
                    'GeminiResearcherService transient HTTP ' . $response->status
                    . '; retry ' . ($attempt + 1) . '/' . ($attempts - 1)
                );
                $this->sleepBeforeRetry($attempt);

                continue;
            }

            return $response;
        }

        if ($lastTransport !== null) {
            error_log('GeminiResearcherService transport: ' . $lastTransport->getMessage());
        }

        throw GeminiResearcherException::transportFailed();
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
     * @throws GeminiResearcherException
     */
    private function exceptionFromFailedResponse(Response $response): GeminiResearcherException
    {
        $apiMessage = $this->parseApiErrorMessage($response);
        $bodySample = substr($response->body, 0, 500);
        error_log(
            'GeminiResearcherService HTTP ' . $response->status
            . ' model=' . $this->model
            . ($apiMessage !== '' ? ': ' . $apiMessage : ': ' . $bodySample)
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

    /**
     * @throws GeminiResearcherException
     */
    private function extractCandidateText(
        Response $response,
        bool $allowTruncatedPartial = false,
        string $phase = 'summary',
    ): string {
        try {
            $json = $response->json();
        } catch (\JsonException $e) {
            error_log('GeminiResearcherService response JSON: ' . $e->getMessage());
            throw GeminiResearcherException::badResponse('Could not parse API JSON: ' . $e->getMessage());
        }

        $this->recordGeminiUsageFromJson($json, $phase);

        if (isset($json['error']) && is_array($json['error'])) {
            $msg = trim((string)($json['error']['message'] ?? ''));
            error_log('GeminiResearcherService API error: ' . ($msg !== '' ? $msg : 'unknown'));
            if ($msg !== '') {
                throw GeminiResearcherException::fromApiMessage(400, $msg);
            }

            throw GeminiResearcherException::badResponse('API returned an error object without a message.');
        }

        $candidates = $json['candidates'] ?? null;
        if (!is_array($candidates) || $candidates === []) {
            $block = $json['promptFeedback']['blockReason'] ?? null;
            if (is_string($block) && $block !== '') {
                throw GeminiResearcherException::blocked($block);
            }

            throw GeminiResearcherException::emptyResponse('API response had no candidates.');
        }

        $first = $candidates[0];
        if (!is_array($first)) {
            throw GeminiResearcherException::badResponse('First candidate is not a valid object.');
        }

        $finish = (string)($first['finishReason'] ?? '');
        if ($finish === 'SAFETY' || $finish === 'RECITATION') {
            throw GeminiResearcherException::blocked($finish);
        }
        $truncated = $this->isOutputTruncatedFinishReason($finish);

        $content = $first['content'] ?? null;
        if (!is_array($content)) {
            if ($truncated && $allowTruncatedPartial) {
                throw GeminiResearcherException::outputTruncated();
            }
            throw GeminiResearcherException::badResponse(
                'Candidate missing content'
                . ($finish !== '' ? ' (finish: ' . $finish . ').' : '.')
            );
        }

        $parts = $content['parts'] ?? null;
        if (!is_array($parts)) {
            throw GeminiResearcherException::badResponse('Candidate content has no text parts.');
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

        $text = trim($text);
        if ($text === '') {
            throw $truncated
                ? GeminiResearcherException::outputTruncated()
                : GeminiResearcherException::emptyResponse();
        }

        if ($truncated && !$allowTruncatedPartial) {
            throw GeminiResearcherException::outputTruncated();
        }

        if ($truncated && $allowTruncatedPartial) {
            error_log(
                'GeminiResearcherService: pass 2 MAX_TOKENS with ' . strlen($text)
                . ' chars of partial Markdown (batched pass kept)'
            );
            $this->lastGenerationMeta['summary_partial_truncation'] = true;
        }

        return $text;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeResearcherJsonObject(string $raw): ?array
    {
        $jsonText = LenientJsonParser::extractMarkdownJson($raw);

        try {
            $decoded = json_decode($jsonText, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException $e) {
            error_log('GeminiResearcherService JSON strict parse: ' . $e->getMessage());
        }

        $repaired = LenientJsonParser::parseObject($raw);
        if ($repaired !== null) {
            error_log('GeminiResearcherService JSON repaired via lenient parser.');
        }

        return $repaired;
    }

    private function isOutputTruncatedFinishReason(string $finishReason): bool
    {
        $normalized = strtoupper(trim($finishReason));

        return in_array($normalized, ['MAX_TOKENS', 'LENGTH', 'MAX_OUTPUT_TOKENS', 'OUTPUT_TOKEN_LIMIT'], true);
    }

    /**
     * Log citation/length hints only — pass 2 truncation is handled via API finish reason or batched retry.
     */
    private function noteResearcherLengthHeuristic(GeminiResearcherResult $result, bool $mayThrowOnShortOutput): void
    {
        $keysInMarkdown = $this->countDistinctEntryKeysInMarkdown($result->markdown);
        $citedKeys      = count($result->usedEntryKeys);

        if ($citedKeys > 0 && $keysInMarkdown > 0 && $keysInMarkdown < $citedKeys) {
            error_log(
                'GeminiResearcherService: researcher_markdown cites ' . $keysInMarkdown
                . ' entries but selection listed ' . $citedKeys
                . ' (returning researcher; source cards use selection keys)'
            );
            $this->lastGenerationMeta['citation_keys_in_markdown'] = $keysInMarkdown;
            $this->lastGenerationMeta['citation_gap']             = true;
        }

        if (!$mayThrowOnShortOutput) {
            return;
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
                'GeminiResearcherService: expected ' . $itemsToCheck
                . ' entry_type:entry_id tokens in markdown, found ' . $keysInMarkdown
                . ($mayThrowOnShortOutput ? ' (retrying in batched parts)' : ' (returning researcher anyway)')
            );
            $this->lastGenerationMeta['citation_keys_in_markdown'] = $keysInMarkdown;
            $this->lastGenerationMeta['citation_gap']             = true;

            return;
        }

        $minChars = $itemsToCheck * 120;
        if (strlen($result->markdown) < $minChars && $keysInMarkdown === 0) {
            error_log(
                'GeminiResearcherService: researcher_markdown very short (' . strlen($result->markdown)
                . ' chars) with no entry_type:entry_id tokens'
            );
            throw GeminiResearcherException::outputTruncated();
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

    private function salvageResearcherFromBrokenJson(string $jsonText): ?GeminiResearcherResult
    {
        $needle = '"researcher_markdown"';
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

        return new GeminiResearcherResult($markdown, [], false);
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
