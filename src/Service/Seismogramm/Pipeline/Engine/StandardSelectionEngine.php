<?php

declare(strict_types=1);

namespace Seismo\Service\Seismogramm\Pipeline\Engine;

use Seismo\Service\Seismogramm\Pipeline\ResilientGeminiClient;
use Seismo\Service\Seismogramm\Pipeline\SelectionPipelineContext;
use Seismo\Service\Seismogramm\Pipeline\SelectionResponseParser;
use Seismo\Service\Seismogramm\Pipeline\TokenBudgeteer;
use Seismo\Service\Seismogramm\SeismogrammContracts;

final class StandardSelectionEngine
{
    public function __construct(
        private readonly ResilientGeminiClient $client,
        private readonly SelectionResponseParser $parser
    ) {}

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<string>
     */
    public function select(
        string $model,
        string $apiKey,
        string $userSystemPrompt,
        string $xmlContext,
        int $itemCount,
        int $configuredMaxTokens,
        array $entries,
        ?SelectionPipelineContext $pipelineContext = null,
    ): array {
        $pipelineContext ??= new SelectionPipelineContext();

        $envelope = SeismogrammContracts::expandSelectionEnvelope(
            SeismogrammContracts::SELECTION_PASS_OUTPUT_CONTRACT,
            $itemCount,
            $itemCount,
            $xmlContext,
        );

        $systemText = trim($userSystemPrompt) . "\n\n" . $envelope;
        if ($pipelineContext->globalFingerprintXml !== '' && !$pipelineContext->contextCacheActive()) {
            $systemText .= "\n\nGLOBAL POOL INDEX (titles/modules only):\n" . $pipelineContext->globalFingerprintXml;
        }
        if ($pipelineContext->useNegativeSpace) {
            $systemText .= "\n\n" . SeismogrammContracts::RELATIONAL_NEGATIVE_SPACE_PROTOCOL;
        }

        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => 'Run selection pipeline and return used_entry_keys JSON.']]],
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
                        'selection_reasoning' => [
                            'type'                 => 'object',
                            'additionalProperties' => ['type' => 'string'],
                        ],
                    ],
                    'required' => ['used_entry_keys'],
                ],
                'maxOutputTokens' => TokenBudgeteer::resolveSelectionPassTokenBudget(
                    $itemCount,
                    $configuredMaxTokens,
                    $model,
                ),
            ], 'selection', $model),
        ];

        $payload = $this->client->attachContextCache($payload, $pipelineContext->contextCacheName);

        $response = $this->client->postPayloadWithSchemaFallback($model, $payload, $apiKey, 'selection');
        $rawText = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';

        return $this->parser->parseSelectionResponse($rawText, $entries, $itemCount);
    }
}
