<?php

declare(strict_types=1);

namespace Seismo\Service\Seismogramm;

use Seismo\Formatter\MarkdownResearcherFormatter;
use Seismo\Service\GeminiResearcherException;
use Seismo\Service\GeminiResearcherResult;
use Seismo\Service\ResearcherEntryGatherer;
use Seismo\Service\ResearcherGlobalFingerprint;
use Seismo\Service\ResearcherSourceSelection;
use Seismo\Service\Seismogramm\Pipeline\Engine\RelationalSelectionEngine;
use Seismo\Service\Seismogramm\Pipeline\Engine\StandardSelectionEngine;
use Seismo\Service\Seismogramm\Pipeline\Engine\TournamentSelectionEngine;
use Seismo\Service\Seismogramm\Pipeline\ResilientGeminiClient;
use Seismo\Service\Seismogramm\Pipeline\SelectionPipelineContext;
use Seismo\Service\Seismogramm\Pipeline\SelectionResponseParser;
use Seismo\Service\Seismogramm\Pipeline\SummaryBriefingEngine;

final class SeismogrammOrchestrator
{
    private readonly ResilientGeminiClient $client;
    private readonly SelectionResponseParser $parser;
    private readonly StandardSelectionEngine $selectionEngine;
    private readonly TournamentSelectionEngine $tournamentEngine;
    private readonly RelationalSelectionEngine $relationalEngine;
    private readonly SummaryBriefingEngine $summaryEngine;

    /** @var array<string, mixed> */
    private array $lastPipelineMeta = [];

    public function __construct()
    {
        $this->client = new ResilientGeminiClient();
        $this->parser = new SelectionResponseParser();
        $this->selectionEngine = new StandardSelectionEngine($this->client, $this->parser);
        $this->tournamentEngine = new TournamentSelectionEngine($this->client, $this->parser, $this->selectionEngine);
        $this->relationalEngine = new RelationalSelectionEngine($this->tournamentEngine);
        $this->summaryEngine = new SummaryBriefingEngine($this->client);
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $formatterMeta  Gather meta for {@see MarkdownResearcherFormatter::format}.
     */
    public function generateBriefing(
        string $apiKey,
        string $model,
        string $userSystemPrompt,
        string $xmlContext,
        int $itemCount,
        int $maxOutputTokens,
        array $entries,
        array $scoresByKey,
        array $formatterMeta,
        string $preset,
        string $postedSelectionMode,
        bool $customAdvanced,
        bool $useContextCache = false,
        ?ResearcherSourceSelection $moduleSelection = null,
    ): GeminiResearcherResult {
        $preset = SeismogrammPresetProfile::normalizePreset($preset);
        $poolCount = count($entries);
        $selectionMode = SeismogrammPresetProfile::resolveSelectionMode(
            $preset,
            $poolCount,
            $postedSelectionMode,
            $customAdvanced,
        );

        $selectionPool = SeismogrammPresetProfile::filterSelectionPool($preset, $entries);

        if ($preset === SeismogrammPresetProfile::BLINDSPOT && $selectionPool === []) {
            throw GeminiResearcherException::badResponse(
                'Blindspot requires Lex or Leg entries in the gathered pool. Enable Lex and Leg sources and widen the lookback window.',
            );
        }

        $globalFingerprintXml = '';
        if (SeismogrammPresetProfile::usesGlobalFingerprint($preset, $selectionMode)) {
            $globalFingerprintXml = ResearcherGlobalFingerprint::buildXml(
                $entries,
                new ResearcherEntryGatherer(),
                $moduleSelection,
            );
        }

        $pipelineContext = new SelectionPipelineContext(
            globalFingerprintXml: $globalFingerprintXml,
            useNegativeSpace: SeismogrammPresetProfile::usesNegativeSpaceProtocol($selectionMode),
            useContextCache: $useContextCache,
        );

        $this->lastPipelineMeta = [
            'preset'              => $preset,
            'selection_mode'      => $selectionMode,
            'pool_entry_count'    => $poolCount,
            'selection_pool_count' => count($selectionPool),
            'global_fingerprint'  => $globalFingerprintXml !== '',
        ];

        if ($selectionMode === 'relational') {
            $selectedKeys = $this->relationalEngine->select(
                $model,
                $apiKey,
                $userSystemPrompt,
                $selectionPool,
                $scoresByKey,
                $formatterMeta,
                $itemCount,
                $maxOutputTokens,
                $pipelineContext,
            );
        } elseif ($selectionMode === 'tournament') {
            $selectedKeys = $this->tournamentEngine->select(
                $model,
                $apiKey,
                $userSystemPrompt,
                $selectionPool,
                $scoresByKey,
                $formatterMeta,
                $itemCount,
                $maxOutputTokens,
                $pipelineContext,
            );
        } else {
            $selectedKeys = $this->selectionEngine->select(
                $model,
                $apiKey,
                $userSystemPrompt,
                $xmlContext,
                $itemCount,
                $maxOutputTokens,
                $selectionPool,
                $pipelineContext,
            );
        }

        $this->lastPipelineMeta['context_cache_used'] = $this->client->contextCacheUsed;
        if ($this->client->contextCacheName !== null) {
            $this->lastPipelineMeta['context_cache_name'] = $this->client->contextCacheName;
        }

        if (in_array($selectionMode, ['tournament', 'relational'], true)) {
            $this->lastPipelineMeta = array_merge(
                $this->lastPipelineMeta,
                $this->tournamentEngine->lastBatchRecoveryMeta(),
            );
        }

        if ($selectedKeys === []) {
            throw GeminiResearcherException::badResponse(
                'Pass 1 selection returned no entry keys. Try a smaller pool, a different preset, or adjust your prompt.',
            );
        }

        $selectedKeys = array_values(array_unique(array_map(
            static fn(string $key): string => strtolower(trim($key)),
            $selectedKeys,
        )));

        $summaryContext = $this->buildSummaryContextForKeys($entries, $scoresByKey, $formatterMeta, $selectedKeys);

        $markdown = $this->summaryEngine->compileBriefing(
            $model,
            $apiKey,
            $userSystemPrompt,
            $summaryContext,
            $selectedKeys,
            $itemCount,
            $maxOutputTokens,
        );

        $usage = [
            'prompt_tokens' => $this->client->usagePromptTokens,
            'output_tokens' => $this->client->usageOutputTokens,
            'api_calls'     => $this->client->usageApiCalls,
        ];

        return new GeminiResearcherResult(
            $markdown,
            $selectedKeys,
            count($selectedKeys) > 0,
            $usage,
        );
    }

    /** @return array<string, mixed> */
    public function lastPipelineMeta(): array
    {
        return SeismogrammPipelineMeta::enrich($this->lastPipelineMeta);
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $formatterMeta
     * @param list<string> $selectedKeys
     */
    private function buildSummaryContextForKeys(
        array $entries,
        array $scoresByKey,
        array $formatterMeta,
        array $selectedKeys,
    ): string {
        $subset = [];
        $selectedKeysSet = array_flip($selectedKeys);
        foreach ($entries as $e) {
            $key = strtolower((string)($e['entry_type'] ?? '') . ':' . (string)($e['entry_id'] ?? ''));
            if (isset($selectedKeysSet[$key])) {
                $subset[] = $e;
            }
        }

        if ($subset === []) {
            throw GeminiResearcherException::badResponse(
                'Selected entry keys could not be matched to the gathered pool.',
            );
        }

        $meta = $formatterMeta;
        $meta['entry_body_max_chars'] = MarkdownResearcherFormatter::dynamicEntryBodyMaxChars(count($subset));
        $meta['total'] = count($subset);
        $meta['selected'] = count($selectedKeys);

        return MarkdownResearcherFormatter::format(
            $subset,
            $scoresByKey,
            $meta,
            true,
            MarkdownResearcherFormatter::FORMAT_XML,
        );
    }
}
