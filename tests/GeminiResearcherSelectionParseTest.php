<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Service\GeminiResearcherService;

final class GeminiResearcherSelectionParseTest extends TestCase
{
    public function testExtractUsedEntryKeysFromInlineVerificationEntries(): void
    {
        $method = new \ReflectionMethod(GeminiResearcherService::class, 'extractUsedEntryKeysFromDecoded');
        $method->setAccessible(true);

        $service = new \ReflectionClass(GeminiResearcherService::class);
        $instance = $service->newInstanceWithoutConstructor();

        $keys = $method->invoke($instance, [
            'used_entries' => [
                ['key' => 'calendar_event:11416', 'verification_rationale' => 'Company named in body.'],
                ['key' => 'feed_item:42'],
            ],
        ]);

        self::assertSame(['calendar_event:11416', 'feed_item:42'], $keys);
    }

    public function testNormalizeMetaRecordsSelectionFinishReason(): void
    {
        $meta = \Seismo\Service\GeminiResearcherGenerationMeta::normalize(
            [
                'gemini_usage' => [
                    'prompt_tokens' => 1000,
                    'output_tokens' => 200,
                    'api_calls'     => 2,
                    'by_phase'      => [
                        'selection' => [
                            'prompt_tokens' => 800,
                            'output_tokens' => 150,
                            'api_calls'     => 1,
                            'finish_reason' => 'MAX_TOKENS',
                        ],
                    ],
                ],
            ],
            \Seismo\Service\GeminiResearcherGenerationOptions::defaults(),
            ['pool_entry_count' => 80, 'item_count' => 5, 'selection_target' => 5],
        );

        self::assertSame('MAX_TOKENS', $meta['gemini_usage']['by_phase']['selection']['finish_reason']);
    }
}
