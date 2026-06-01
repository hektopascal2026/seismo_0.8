<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Service\GeminiResearcherGenerationMeta;
use Seismo\Service\GeminiResearcherGenerationOptions;

final class GeminiResearcherGenerationMetaTest extends TestCase
{
    public function testNormalizeStandardGlobalSelection(): void
    {
        $meta = GeminiResearcherGenerationMeta::normalize(
            [
                'skinny_global_selection' => true,
                'selection_model'         => 'gemini-3.5-flash',
                'summary_model'           => 'gemini-3.5-flash',
                'entries_sent_to_gemini'  => 80,
            ],
            GeminiResearcherGenerationOptions::defaults(),
            ['pool_entry_count' => 80, 'item_count' => 5, 'selection_target' => 5],
        );

        self::assertSame('global_single_pass', $meta['selection_strategy']);
        self::assertFalse($meta['tournament_selection']);
        self::assertFalse($meta['dual_model_selection']);
        self::assertNotEmpty($meta['meta_summary_line']);
        self::assertStringContainsString('global single pass', $meta['meta_summary_line']);
    }

    public function testNormalizeTournamentMode(): void
    {
        $options = new GeminiResearcherGenerationOptions(tournamentMode: true);
        $meta    = GeminiResearcherGenerationMeta::normalize(
            [
                'tournament_selection' => true,
                'selection_parallel'   => true,
                'selection_batches'    => 5,
                'selected_entry_keys'  => ['feed_item:1', 'lex_item:2'],
            ],
            $options,
            ['pool_entry_count' => 150, 'item_count' => 5, 'selection_target' => 5],
        );

        self::assertSame('tournament_parallel_batches', $meta['selection_strategy']);
        self::assertTrue($meta['tournament_mode']);
        self::assertSame(2, $meta['selection_keys_count']);
        self::assertStringContainsString('tournament', $meta['meta_summary_line']);
    }

    public function testNormalizeProDualModel(): void
    {
        $options = new GeminiResearcherGenerationOptions(proSelectionMode: true);
        $meta    = GeminiResearcherGenerationMeta::normalize(
            [
                'selection_model' => 'gemini-3.1-pro-preview',
                'summary_model'   => 'gemini-3.5-flash',
            ],
            $options,
            ['pool_entry_count' => 100, 'item_count' => 5, 'selection_target' => 5],
        );

        self::assertTrue($meta['pro_selection_mode']);
        self::assertTrue($meta['dual_model_selection']);
        self::assertStringContainsString('Pro sel', $meta['meta_summary_line']);
        self::assertStringContainsString('gemini-3.1-pro-preview', $meta['meta_summary_line']);
    }

    public function testNormalizeFailureFlags(): void
    {
        $meta = GeminiResearcherGenerationMeta::normalize(
            ['generation_failed' => true],
            new GeminiResearcherGenerationOptions(tournamentMode: true),
            ['pool_entry_count' => 150, 'item_count' => 5, 'selection_target' => 5],
        );

        self::assertTrue($meta['generation_failed']);
        self::assertTrue($meta['tournament_mode']);
        self::assertStringContainsString('failed', $meta['meta_summary_line']);
    }
}
