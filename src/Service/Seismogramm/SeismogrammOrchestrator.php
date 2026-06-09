<?php

declare(strict_types=1);

namespace Seismo\Service\Seismogramm;

use Seismo\Service\GeminiResearcherResult;
use Seismo\Service\Seismogramm\Pipeline\ResilientGeminiClient;
use Seismo\Service\Seismogramm\Pipeline\SelectionResponseParser;
use Seismo\Service\Seismogramm\Pipeline\Engine\StandardSelectionEngine;
use Seismo\Service\Seismogramm\Pipeline\Engine\TournamentSelectionEngine;
use Seismo\Service\Seismogramm\Pipeline\Engine\RelationalSelectionEngine;
use Seismo\Service\Seismogramm\Pipeline\SummaryBriefingEngine;

final class SeismogrammOrchestrator
{
    private readonly ResilientGeminiClient $client;
    private readonly SelectionResponseParser $parser;
    private readonly StandardSelectionEngine $selectionEngine;
    private readonly TournamentSelectionEngine $tournamentEngine;
    private readonly RelationalSelectionEngine $relationalEngine;
    private readonly SummaryBriefingEngine $summaryEngine;

    public function __construct()
    {
        $this->client = new ResilientGeminiClient();
        $this->parser = new SelectionResponseParser();
        $this->selectionEngine = new StandardSelectionEngine($this->client, $this->parser);
        $this->tournamentEngine = new TournamentSelectionEngine($this->client, $this->parser, $this->selectionEngine);
        $this->relationalEngine = new RelationalSelectionEngine($this->client, $this->parser, $this->selectionEngine);
        $this->summaryEngine = new SummaryBriefingEngine($this->client);
    }

    /**
     * Orchestrates the standard, tournament, or relational selection and summary execution flow.
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
        array $researcherMeta,
        string $selectionMode = 'standard'
    ): GeminiResearcherResult {
        // Step 1: Run selection pass based on mode
        if ($selectionMode === 'tournament') {
            $selectedKeys = $this->tournamentEngine->select(
                $model,
                $apiKey,
                $userSystemPrompt,
                $entries,
                $scoresByKey,
                $researcherMeta,
                $itemCount,
                $maxOutputTokens
            );
        } elseif ($selectionMode === 'relational') {
            $selectedKeys = $this->relationalEngine->select(
                $model,
                $apiKey,
                $userSystemPrompt,
                $entries,
                $scoresByKey,
                $researcherMeta,
                $itemCount,
                $maxOutputTokens
            );
        } else {
            $selectedKeys = $this->selectionEngine->select(
                $model,
                $apiKey,
                $userSystemPrompt,
                $xmlContext,
                $itemCount,
                $maxOutputTokens,
                $entries
            );
        }

        if ($selectedKeys === []) {
            // Re-attempt selection or fallback to top-priority entries by score
            $selectedKeys = array_map(
                fn($e) => $e['entry_type'] . ':' . $e['entry_id'],
                array_slice($entries, 0, min($itemCount, count($entries)))
            );
        }

        // Filter XML context dynamically to only contain the selected entries for Pass 2 summary
        $summaryContext = $this->buildSummaryContextForKeys($entries, $scoresByKey, $researcherMeta, $selectedKeys);

        // Step 2: Compile the final Markdown briefing (Pass 2)
        $markdown = $this->summaryEngine->compileBriefing(
            $model,
            $apiKey,
            $userSystemPrompt,
            $summaryContext,
            $selectedKeys,
            $itemCount,
            $maxOutputTokens
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
            $usage
        );
    }

    /**
     * Builds standard XML context snippet containing only the selected keys.
     */
    private function buildSummaryContextForKeys(
        array $entries,
        array $scoresByKey,
        array $researcherMeta,
        array $selectedKeys
    ): string {
        $subset = [];
        $selectedKeysSet = array_flip($selectedKeys);
        foreach ($entries as $e) {
            $key = $e['entry_type'] . ':' . $e['entry_id'];
            if (isset($selectedKeysSet[$key])) {
                $subset[] = $e;
            }
        }

        if ($subset === []) {
            $subset = array_slice($entries, 0, count($selectedKeys));
        }

        $meta = $researcherMeta;
        $meta['total'] = count($subset);
        $meta['selected'] = count($selectedKeys);

        // Render subset to XML
        $formatter = new \Seismo\Formatter\MarkdownResearcherFormatter();
        return $formatter->format($subset, $scoresByKey, $meta, true);
    }
}
