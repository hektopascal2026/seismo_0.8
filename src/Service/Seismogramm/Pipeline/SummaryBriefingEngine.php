<?php

declare(strict_types=1);

namespace Seismo\Service\Seismogramm\Pipeline;

use Seismo\Service\Seismogramm\SeismogrammContracts;

final class SummaryBriefingEngine
{
    public function __construct(
        private readonly ResilientGeminiClient $client
    ) {}

    /**
     * Renders/Compiles the briefing prose (Pass 2).
     */
    public function compileBriefing(
        string $model,
        string $apiKey,
        string $userSystemPrompt,
        string $xmlContext,
        array $selectedKeys,
        int $itemCount,
        int $configuredMaxTokens
    ): string {
        $temporalContext = 'Today is ' . (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Zurich')))->format('l, Y-m-d H:i:s');
        $selectedKeysStr = implode(', ', $selectedKeys);

        $directive = str_replace(
            ['{temporalContext}', '{selectedEntryKeys}', '{markdownContext}'],
            [$temporalContext, $selectedKeysStr, $xmlContext],
            SeismogrammContracts::SUMMARY_OUTPUT_CONTRACT
        );

        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => 'Generate briefing Markdown.']]]
            ],
            'systemInstruction' => [
                'parts' => [
                    ['text' => $userSystemPrompt . "\n\n" . $directive]
                ]
            ],
            'generationConfig' => TokenBudgeteer::applyGemini35Thinking([
                'maxOutputTokens' => TokenBudgeteer::resolveOutputTokenBudget(count($selectedKeys), $configuredMaxTokens, $model),
            ], 'summary', $model),
        ];

        $response = $this->client->postPayloadWithSchemaFallback($model, $payload, $apiKey, 'summary');
        return $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }
}
