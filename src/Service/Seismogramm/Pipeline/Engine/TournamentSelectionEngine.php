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
    /** @var array<string, mixed> */
    private array $lastBatchRecoveryMeta = [];

    public function __construct(
        private readonly ResilientGeminiClient $client,
        private readonly SelectionResponseParser $parser,
        private readonly StandardSelectionEngine $standardEngine
    ) {}

    /** @return array<string, mixed> */
    public function lastBatchRecoveryMeta(): array
    {
        return $this->lastBatchRecoveryMeta;
    }

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
        $this->lastBatchRecoveryMeta = [];

        $batchSize = 35;
        $batches = ResearcherGeminiContext::chunkEntryList($poolEntries, $batchSize);
        $batchCount = count($batches);

        if ($batchCount === 0) {
            return [];
        }

        $cacheName = $this->resolveContextCache($model, $apiKey, $pipelineContext);
        if ($cacheName !== null && $cacheName !== '') {
            $pipelineContext->contextCacheName = $cacheName;
        }

        if ($batchCount === 1) {
            $formatter = new MarkdownResearcherFormatter();
            $xmlContext = $formatter->format(
                $poolEntries,
                $scoresByKey,
                $this->metaForEntries($researcherMeta, $poolEntries),
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
            $jobs['batch_' . $index] = $this->buildBatchPayload(
                $batch,
                $scoresByKey,
                $researcherMeta,
                $userSystemPrompt,
                $itemCount,
                $configuredMaxTokens,
                $model,
                $pipelineContext,
                $cacheName,
            );
        }

        $responses = $this->client->postParallel($model, $jobs, $apiKey);

        $mergedKeys = [];
        $seen = [];

        foreach ($responses as $jobId => $res) {
            $index = (int)str_replace('batch_', '', $jobId);
            $batchEntries = $batches[$index] ?? [];
            $survivorsCount = max(1, min(3, count($batchEntries)));
            $batchNumber = $index + 1;

            $keys = $this->keysFromBatchResponse($res, $batchEntries, $survivorsCount);
            $needsRetry = $res['status'] !== 200 || $keys === [];

            if ($needsRetry) {
                $reason = $res['status'] !== 200
                    ? 'HTTP ' . (int)$res['status']
                    : 'Selection batch returned no used_entry_keys.';
                $payload = $jobs[$jobId] ?? null;
                $retryKeys = is_array($payload)
                    ? $this->retryFailedBatchOnce(
                        $model,
                        $payload,
                        $batchEntries,
                        $survivorsCount,
                        $apiKey,
                        $batchNumber,
                        $reason,
                    )
                    : [];

                if ($retryKeys !== []) {
                    $this->noteBatchOutcome($batchNumber, $reason, true);
                    $keys = $retryKeys;
                } else {
                    $this->noteBatchOutcome($batchNumber, $reason, false);
                }
            }

            foreach ($keys as $k) {
                $norm = strtolower(trim($k));
                if ($norm !== '' && !isset($seen[$norm])) {
                    $seen[$norm] = true;
                    $mergedKeys[] = $norm;
                }
            }
        }

        $this->finalizeBatchRecoveryMeta();

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
            $this->metaForEntries($researcherMeta, $finalistEntries),
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

    /**
     * @param list<array<string, mixed>> $batch
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $researcherMeta
     * @return array<string, mixed>
     */
    private function buildBatchPayload(
        array $batch,
        array $scoresByKey,
        array $researcherMeta,
        string $userSystemPrompt,
        int $itemCount,
        int $configuredMaxTokens,
        string $model,
        SelectionPipelineContext $pipelineContext,
        ?string $cacheName,
    ): array {
        $formatter = new MarkdownResearcherFormatter();
        $xmlContext = $formatter->format(
            $batch,
            $scoresByKey,
            $this->metaForEntries($researcherMeta, $batch),
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

        return $this->client->attachContextCache($payload, $cacheName);
    }

    /**
     * @param array{status: int, body: string} $res
     * @param list<array<string, mixed>> $batchEntries
     * @return list<string>
     */
    private function keysFromBatchResponse(array $res, array $batchEntries, int $survivorsCount): array
    {
        if ($res['status'] !== 200) {
            return [];
        }

        $data = json_decode($res['body'], true);
        $rawText = is_array($data)
            ? (string)($data['candidates'][0]['content']['parts'][0]['text'] ?? '')
            : '';

        return $this->parser->parseSelectionResponse($rawText, $batchEntries, $survivorsCount);
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<array<string, mixed>> $batchEntries
     * @return list<string>
     */
    private function retryFailedBatchOnce(
        string $model,
        array $payload,
        array $batchEntries,
        int $survivorsCount,
        string $apiKey,
        int $batchNumber,
        string $reason,
    ): array {
        if ($batchEntries === []) {
            return [];
        }

        error_log(
            'TournamentSelectionEngine: batch ' . $batchNumber
            . ' retry after failure: ' . $reason,
        );

        $this->lastBatchRecoveryMeta['selection_batch_retries'] =
            (int)($this->lastBatchRecoveryMeta['selection_batch_retries'] ?? 0) + 1;

        try {
            $response = $this->client->postPayloadWithSchemaFallback($model, $payload, $apiKey, 'selection');
            $rawText = (string)($response['candidates'][0]['content']['parts'][0]['text'] ?? '');

            return $this->parser->parseSelectionResponse($rawText, $batchEntries, $survivorsCount);
        } catch (\Throwable $e) {
            error_log(
                'TournamentSelectionEngine: batch ' . $batchNumber
                . ' retry failed: ' . $e->getMessage(),
            );

            return [];
        }
    }

    private function noteBatchOutcome(int $batchNumber, string $reason, bool $recovered): void
    {
        $errors = $this->lastBatchRecoveryMeta['selection_batch_errors'] ?? [];
        if (!is_array($errors)) {
            $errors = [];
        }

        $errors[] = [
            'batch'     => $batchNumber,
            'message'   => $reason,
            'recovered' => $recovered,
        ];
        $this->lastBatchRecoveryMeta['selection_batch_errors'] = $errors;

        if ($recovered) {
            $this->lastBatchRecoveryMeta['selection_batch_recovered_count'] =
                (int)($this->lastBatchRecoveryMeta['selection_batch_recovered_count'] ?? 0) + 1;
        }
    }

    private function finalizeBatchRecoveryMeta(): void
    {
        $errors = $this->lastBatchRecoveryMeta['selection_batch_errors'] ?? [];
        if (!is_array($errors) || $errors === []) {
            $this->lastBatchRecoveryMeta = [];

            return;
        }

        $recoveredCount = (int)($this->lastBatchRecoveryMeta['selection_batch_recovered_count'] ?? 0);
        if ($recoveredCount > 0) {
            $this->lastBatchRecoveryMeta['selection_batch_recovered'] = true;
        } else {
            unset($this->lastBatchRecoveryMeta['selection_batch_recovered']);
            unset($this->lastBatchRecoveryMeta['selection_batch_recovered_count']);
        }
    }

    /**
     * @param array<string, mixed> $baseMeta
     * @param list<array<string, mixed>> $entries
     * @return array<string, mixed>
     */
    private function metaForEntries(array $baseMeta, array $entries): array
    {
        $meta = $baseMeta;
        $meta['entry_body_max_chars'] = MarkdownResearcherFormatter::dynamicEntryBodyMaxChars(count($entries));

        return $meta;
    }

    private function resolveContextCache(
        string $model,
        string $apiKey,
        SelectionPipelineContext $pipelineContext,
    ): ?string {
        if (!$pipelineContext->useContextCache) {
            return null;
        }

        if ($pipelineContext->contextCacheName !== null && $pipelineContext->contextCacheName !== '') {
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
