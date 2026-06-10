<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Service\Seismogramm\SeismogrammOrchestrator;
use Seismo\Service\Seismogramm\SeismogrammPresetProfile;
use Seismo\Service\Seismogramm\SeismogrammContracts;
use Seismo\Service\Seismogramm\Pipeline\ResilientGeminiClient;

final class SeismogrammOrchestratorTest extends TestCase
{
    public function testMonitorEmptySelectionReturnsEmptyReport(): void
    {
        // Create a mock of ResilientGeminiClient
        $mockClient = $this->createMock(ResilientGeminiClient::class);
        $mockClient->method('postPayloadWithSchemaFallback')
            ->willReturn([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => '{"used_entry_keys":[]}']
                            ]
                        ]
                    ]
                ]
            ]);

        $mockClient->method('usageReport')
            ->willReturn([
                'prompt_tokens' => 100,
                'output_tokens' => 10,
                'api_calls' => 1,
            ]);

        $orchestrator = new SeismogrammOrchestrator($mockClient);

        $entries = [
            ['entry_type' => 'feed_item', 'entry_id' => '123', 'title' => 'Test title']
        ];
        
        $result = $orchestrator->generateBriefing(
            apiKey: 'dummy_key',
            model: 'gemini-3.5-flash',
            userSystemPrompt: 'Dummy system prompt',
            xmlContext: '<context></context>',
            itemCount: 5,
            maxOutputTokens: 2000,
            entries: $entries,
            scoresByKey: [],
            formatterMeta: [],
            preset: SeismogrammPresetProfile::MONITOR
        );

        self::assertSame(SeismogrammContracts::MONITOR_EMPTY_REPORT_MARKDOWN, $result->markdown);
        self::assertSame([], $result->usedEntryKeys);
        self::assertFalse($result->attributionParsed);
        self::assertSame(1, $result->usage['api_calls']);
    }
}
