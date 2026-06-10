<?php

declare(strict_types=1);

namespace Seismo\Service\Seismogramm\Pipeline\Engine;

use Seismo\Formatter\MarkdownResearcherFormatter;
use Seismo\Service\ResearcherGeminiContext;
use Seismo\Service\Seismogramm\Pipeline\ResilientGeminiClient;
use Seismo\Service\Seismogramm\Pipeline\SelectionPipelineContext;
use Seismo\Service\Seismogramm\Pipeline\SelectionResponseParser;
use Seismo\Service\Seismogramm\Pipeline\TokenBudgeteer;
use Seismo\Service\Seismogramm\SeismogrammContracts;

final class TournamentSelectionEngine
{
    public function __construct(
        private readonly ResilientGeminiClient $client,
        private readonly SelectionResponseParser $parser,
        private readonly StandardSelectionEngine $standardEngine
    ) {}

    /**
     * @param list<array<string, mixed>> $poolEntries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $researcherMeta
     * @return list<string>
     */
    public function select(
        string $model,
        string $apiKey,
        string $userSystemPrompt,
        array $poolEntries,
        array $scoresByKey,
        array $researcherMeta,
        int $itemCount,
        int $configuredMaxTokens,
        SelectionPipelineContext $pipelineContext,
    ): array {
        $batchSize = 35;
        $batches = ResearcherGeminiContext::chunkEntryList($poolEntries, $batchSize);
        $batchCount = count($batches);

        if ($batchCount === 0) {
            return [];
        }

        $cacheName = $this->resolveContextCache($model, $apiKey, $pipelineContext);

        if ($batchCount === 1) {
            $formatter = new MarkdownResearcherFormatter();
            $xmlContext = $formatter->format(
                $poolEntries,
                $scoresByKey,
                $researcherMeta,
                true,
                MarkdownResearcherFormatter::FORMAT_XML,
            );

            return $this->standardEngine->select(
                $model,
                $apiKey,
                $userSystemPrompt,
                $xmlContext,
                $itemCount,
                $configuredMaxTokens,
                $poolEntries,
                $pipelineContext,
            );
        }

        $jobs = [];
        $formatter = new MarkdownResearcherFormatter();

        foreach ($batches as $index => $batch) {
            $xmlContext = $formatter->format(
                $batch,
                $scoresByKey,
                $researcherMeta,
                true,
                MarkdownResearcherFormatter::FORMAT_XML,
            );
            $survivorsCount = max(1, min(3, count($batch)));

            $envelope = SeismogrammContracts::expandSelectionEnvelope(
                SeismogrammContracts::SELECTION_BATCH_OUTPUT_CONTRACT,
                $itemCount,
                $survivorsCount,
                $xmlContext,
            );

            $systemText = trim($userSystemPrompt) . "\n\n" . $envelope
                . $this->instructionSuffix($pipelineContext, $cacheName);

            $payload = [
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => 'Run tournament batch selection and return used_entry_keys only.']]],
                ],
                'systemInstruction' => [
                    'parts' => [['text' => $systemText]],
                ],
                'generationConfig' => TokenBudgeteer::applyGemini35Thinking([
                    'responseMimeType' => 'application/json',
                    'responseSchema' => [
                        'type'       => 'object',
                        'properties' => [
                            'used_entry_keys' => [
                                'type'  => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                        'required' => ['used_entry_keys'],
                    ],
                    'maxOutputTokens' => TokenBudgeteer::resolveTournamentBatchSelectionTokenBudget(
                        $survivorsCount,
                        $configuredMaxTokens,
                        $model,
                    ),
                ], 'selection', $model),
            ];

            $jobs['batch_' . $index] = $this->client->attachContextCache($payload, $cacheName);
        }

        $responses = $this->client->postParallel($model, $jobs, $apiKey);

        $mergedKeys = [];
        $seen = [];

        foreach ($responses as $jobId => $res) {
            $rawText = '';
            if ($res['status'] === 200) {
                $data = json_decode($res['body'], true);
                $rawText = is_array($data)
                    ? (string)($data['candidates'][0]['content']['parts'][0]['text'] ?? '')
                    : '';
            }

            $index = (int)str_replace('batch_', '', $jobId);
            $batchEntries = $batches[$index] ?? [];
            $survivorsCount = max(1, min(3, count($batchEntries)));

            $keys = $this->parser->parseSelectionResponse($rawText, $batchEntries, $survivorsCount);
            foreach ($keys as $k) {
                $norm = strtolower(trim($k));
                if ($norm !== '' && !isset($seen[$norm])) {
                    $seen[$norm] = true;
                    $mergedKeys[] = $norm;
                }
            }
        }

        if ($mergedKeys === []) {
            return [];
        }

        $finalistEntries = [];
        $mergedKeysSet = array_flip($mergedKeys);
        foreach ($poolEntries as $e) {
            $key = strtolower((string)($e['entry_type'] ?? '') . ':' . (string)($e['entry_id'] ?? ''));
            if (isset($mergedKeysSet[$key])) {
                $finalistEntries[] = $e;
            }
        }

        $championContext = $formatter->format(
            $finalistEntries,
            $scoresByKey,
            $researcherMeta,
            true,
            MarkdownResearcherFormatter::FORMAT_XML,
        );

        return $this->standardEngine->select(
            $model,
            $apiKey,
            $userSystemPrompt,
            $championContext,
            $itemCount,
            $configuredMaxTokens,
            $finalistEntries,
            $pipelineContext,
        );
    }

    private function resolveContextCache(
        string $model,
        string $apiKey,
        SelectionPipelineContext $pipelineContext,
    ): ?string {
        if ($pipelineContext->contextCacheName !== null) {
            return $pipelineContext->contextCacheName;
        }

        if ($pipelineContext->globalFingerprintXml === '') {
            return null;
        }

        $cacheBody = "GLOBAL POOL INDEX (titles/modules only — use for cross-module negative-space checks):\n"
            . $pipelineContext->globalFingerprintXml;

        return $this->client->createContextCache($model, $cacheBody, $apiKey);
    }

    private function instructionSuffix(SelectionPipelineContext $pipelineContext, ?string $cacheName): string
    {
        $parts = [];

        if ($pipelineContext->globalFingerprintXml !== '' && ($cacheName === null || $cacheName === '')) {
            $parts[] = 'GLOBAL POOL INDEX (all capped entries — titles/modules only; use for cross-batch and cross-module checks):'
                . "\n" . $pipelineContext->globalFingerprintXml;
        } elseif ($cacheName !== null && $cacheName !== '') {
            $parts[] = 'GLOBAL POOL INDEX is provided via context cache — use it for cross-module checks.';
        }

        if ($pipelineContext->useNegativeSpace) {
            $parts[] = SeismogrammContracts::RELATIONAL_NEGATIVE_SPACE_PROTOCOL;
        }

        $parts[] = 'Return JSON with used_entry_keys ONLY. Do not emit selection_reasoning.';

        return "\n\n" . implode("\n\n", $parts);
    }
}
