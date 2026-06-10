<?php

declare(strict_types=1);

namespace Seismo\Service\Seismogramm\Pipeline\Engine;

use Seismo\Formatter\MarkdownResearcherFormatter;
use Seismo\Service\Seismogramm\Pipeline\ResilientGeminiClient;
use Seismo\Service\Seismogramm\Pipeline\SelectionResponseParser;
use Seismo\Service\Seismogramm\Pipeline\TokenBudgeteer;
use Seismo\Service\Seismogramm\SeismogrammContracts;
use Seismo\Service\ResearcherGeminiContext;

final class TournamentSelectionEngine
{
    public function __construct(
        private readonly ResilientGeminiClient $client,
        private readonly SelectionResponseParser $parser,
        private readonly StandardSelectionEngine $standardEngine
    ) {}

    /**
     * Executes tournament selection (parallel batch preliminaries + final championship).
     */
    public function select(
        string $model,
        string $apiKey,
        string $userSystemPrompt,
        array $poolEntries,
        array $scoresByKey,
        array $researcherMeta,
        int $itemCount,
        int $configuredMaxTokens
    ): array {
        // Chunk pool entries into batches (approx 35 entries per batch)
        $batchSize = 35;
        $batches = ResearcherGeminiContext::chunkEntryList($poolEntries, $batchSize);
        $batchCount = count($batches);

        if ($batchCount === 0) {
            return [];
        }

        if ($batchCount === 1) {
            // Fall back to standard selection if it fits in one batch
            $formatter = new MarkdownResearcherFormatter();
            $xmlContext = $formatter->format(
                $poolEntries,
                $scoresByKey,
                $researcherMeta,
                true,
                MarkdownResearcherFormatter::FORMAT_XML,
            );
            return $this->standardEngine->select($model, $apiKey, $userSystemPrompt, $xmlContext, $itemCount, $configuredMaxTokens, $poolEntries);
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
            $survivorsCount = max(1, min(3, count($batch))); // Cap survivors per batch

            $directive = str_replace(
                ['{temporalContext}', '{maxCoreItems}', '{markdownContext}'],
                [
                    'Today is ' . (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Zurich')))->format('l, Y-m-d H:i:s'),
                    (string)$survivorsCount,
                    $xmlContext
                ],
                SeismogrammContracts::SELECTION_OUTPUT_CONTRACT // Uses the batch selection contract
            );

            $payload = [
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => 'Run tournament batch selection and return used_entry_keys.']]]
                ],
                'systemInstruction' => [
                    'parts' => [
                        ['text' => $userSystemPrompt . "\n\n" . $directive]
                    ]
                ],
                'generationConfig' => TokenBudgeteer::applyGemini35Thinking([
                    'responseMimeType' => 'application/json',
                    'responseSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'used_entry_keys' => [
                                'type' => 'array',
                                'items' => ['type' => 'string']
                            ]
                        ],
                        'required' => ['used_entry_keys']
                    ],
                    'maxOutputTokens' => TokenBudgeteer::resolveTournamentBatchSelectionTokenBudget($survivorsCount, $configuredMaxTokens, $model),
                ], 'selection', $model),
            ];

            $jobs['batch_' . $index] = $payload;
        }

        // Run batch selections in parallel
        $responses = $this->client->postParallel($model, $jobs, $apiKey);

        $mergedKeys = [];
        $seen = [];

        foreach ($responses as $jobId => $res) {
            $rawText = '';
            if ($res['status'] === 200) {
                $data = json_decode($res['body'], true);
                $rawText = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            }

            $index = (int)str_replace('batch_', '', $jobId);
            $batchEntries = $batches[$index];
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

        // Championship pass over the merged finalist survivors
        $finalistEntries = [];
        $mergedKeysSet = array_flip(array_map(
            static fn(string $key): string => strtolower(trim($key)),
            $mergedKeys,
        ));
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
            $finalistEntries
        );
    }
}
