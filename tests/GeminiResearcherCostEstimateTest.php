<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Service\GeminiResearcherFlashPricing;
use Seismo\Service\GeminiResearcherGenerationMeta;
use Seismo\Service\GeminiResearcherGenerationOptions;

final class GeminiResearcherCostEstimateTest extends TestCase
{
    public function testFlashPricingEstimate(): void
    {
        $usd = GeminiResearcherFlashPricing::estimateStandardUsd(1_000_000, 500_000);
        self::assertEqualsWithDelta(1.50 + 4.50, $usd, 0.0001);
    }

    public function testBuildCostEstimateStandardPipeline(): void
    {
        $meta = GeminiResearcherGenerationMeta::normalize(
            [
                'gemini_usage' => [
                    'prompt_tokens'       => 120_000,
                    'output_tokens'       => 8_000,
                    'flash_prompt_tokens' => 120_000,
                    'flash_output_tokens' => 8_000,
                    'api_calls'           => 2,
                    'by_phase'            => [
                        'selection' => ['prompt_tokens' => 100_000, 'output_tokens' => 500, 'api_calls' => 1],
                        'summary'   => ['prompt_tokens' => 20_000, 'output_tokens' => 7_500, 'api_calls' => 1],
                    ],
                ],
            ],
            GeminiResearcherGenerationOptions::defaults(),
            ['pool_entry_count' => 80, 'item_count' => 5, 'selection_target' => 5],
        );

        self::assertIsArray($meta['cost_estimate']);
        self::assertSame('standard', $meta['cost_estimate']['pipeline']);
        self::assertSame(120_000, $meta['cost_estimate']['prompt_tokens']);
        self::assertStringStartsWith('$', $meta['cost_estimate']['estimated_usd_display']);
        self::assertGreaterThan(0, $meta['cost_estimate']['estimated_usd']);
        self::assertStringContainsString('aistudio.google.com/spend', $meta['cost_estimate']['spend_console_url']);
    }

    public function testBuildCostEstimateTournamentPipeline(): void
    {
        $meta = GeminiResearcherGenerationMeta::normalize(
            [
                'tournament_mode' => true,
                'gemini_usage'    => [
                    'prompt_tokens' => 400_000,
                    'output_tokens' => 12_000,
                    'api_calls'     => 6,
                ],
            ],
            new GeminiResearcherGenerationOptions(selectionMode: GeminiResearcherGenerationOptions::MODE_TOURNAMENT),
            ['pool_entry_count' => 150, 'item_count' => 5, 'selection_target' => 5],
        );

        self::assertSame('tournament', $meta['cost_estimate']['pipeline']);
    }

    public function testBuildCostEstimateExcludesProTokensFromUsd(): void
    {
        $meta = GeminiResearcherGenerationMeta::normalize(
            [
                'gemini_usage' => [
                    'prompt_tokens'       => 200_000,
                    'output_tokens'       => 10_000,
                    'flash_prompt_tokens' => 50_000,
                    'flash_output_tokens' => 5_000,
                    'pro_prompt_tokens'   => 150_000,
                    'pro_output_tokens'   => 5_000,
                    'api_calls'           => 3,
                ],
                'selection_model' => 'gemini-3.1-pro-preview',
                'summary_model'   => 'gemini-3.5-flash',
            ],
            new GeminiResearcherGenerationOptions(proSelectionMode: true),
            ['pool_entry_count' => 100, 'item_count' => 5, 'selection_target' => 5],
        );

        $expected = GeminiResearcherFlashPricing::estimateStandardUsd(50_000, 5_000);
        self::assertEqualsWithDelta($expected, $meta['cost_estimate']['estimated_usd'], 0.000001);
        self::assertTrue($meta['cost_estimate']['pro_tokens_excluded']);
    }
}
