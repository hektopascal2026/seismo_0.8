<?php

declare(strict_types=1);

namespace Seismo\Service\Seismogramm\Pipeline\Engine;

use Seismo\Service\Seismogramm\Pipeline\ResilientGeminiClient;
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
     * Executes standard selection (Pass 1).
     */
    public function select(
        string $model,
        string $apiKey,
        string $userSystemPrompt,
        string $xmlContext,
        int $itemCount,
        int $configuredMaxTokens,
        array $entries
    ): array {
        // Build selection system instruction and user prompt payload
        $directive = str_replace(
            ['{temporalContext}', '{maxCoreItems}', '{markdownContext}'],
            [
                'Today is ' . (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Zurich')))->format('l, Y-m-d H:i:s'),
                (string)$itemCount,
                $xmlContext
            ],
            SeismogrammContracts::SELECTION_OUTPUT_CONTRACT
        );

        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => 'Run selection pipeline over the ENTRIES_DATA below and return used_entry_keys JSON.']]]
            ],
            'systemInstruction' => [
                'parts' => [
                    ['text' => $userSystemPrompt . "\n\n" . $directive]
                ]
            ],
            'generationConfig' => TokenBudgeteer::applyGemini35Thinking([
                'responseMimeType' => 'application/json',
                'responseSchema' => json_decode(SeismogrammContracts::SELECTION_OUTPUT_CONTRACT, true),
                'maxOutputTokens' => TokenBudgeteer::resolveSelectionPassTokenBudget($itemCount, $configuredMaxTokens, $model),
            ], 'selection', $model),
        ];

        // Ensure Schema isn't empty/malformed
        if (empty($payload['generationConfig']['responseSchema']) || !isset($payload['generationConfig']['responseSchema']['properties'])) {
            // Re-apply schema matching SELECTION_OUTPUT_CONTRACT if JSON decode returned raw metadata
            $payload['generationConfig']['responseSchema'] = [
                'type' => 'object',
                'properties' => [
                    'used_entry_keys' => [
                        'type' => 'array',
                        'items' => ['type' => 'string']
                    ],
                    'selection_reasoning' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'string']
                    ]
                ],
                'required' => ['used_entry_keys']
            ];
        }

        // Run client request
        $response = $this->client->postPayloadWithSchemaFallback($model, $payload, $apiKey, 'selection');

        $rawText = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';

        return $this->parser->parseSelectionResponse($rawText, $entries, $itemCount);
    }
}
