<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Service\Seismogramm\SeismogrammPipelineMeta;

final class SeismogrammPipelineMetaTest extends TestCase
{
    public function testSummaryLineIncludesBatchRecoveryNote(): void
    {
        $line = SeismogrammPipelineMeta::formatSummaryLine([
            'pool_entry_count' => 120,
            'preset' => 'Research',
            'selection_mode' => 'tournament',
            'selection_batch_recovered' => true,
            'selection_batch_recovered_count' => 1,
        ]);

        self::assertStringContainsString('120 in pool', $line);
        self::assertStringContainsString('1 tournament batch recovered after retry', $line);
    }

    public function testEnrichAddsMetaSummaryLine(): void
    {
        $meta = SeismogrammPipelineMeta::enrich([
            'entries_sent_to_gemini' => 80,
            'preset' => 'Briefing',
            'cited_entry_count' => 5,
        ]);

        self::assertArrayHasKey('meta_summary_line', $meta);
        self::assertStringContainsString('80 sent to Gemini', $meta['meta_summary_line']);
        self::assertStringContainsString('5 cited', $meta['meta_summary_line']);
    }

    public function testNoRecoveryNoteWithoutFlag(): void
    {
        self::assertSame('', SeismogrammPipelineMeta::batchRecoveryNote([
            'selection_batch_recovered_count' => 1,
        ]));
    }

    public function testRateLimitRetryNote(): void
    {
        self::assertSame(
            'rate-limit retry (cap 50)',
            SeismogrammPipelineMeta::rateLimitRetryNote([
                'rate_limit_user_retry' => true,
                'max_context_entries' => 50,
            ]),
        );
    }

    public function testNormalizeAddsPresetNativeFields(): void
    {
        $meta = SeismogrammPipelineMeta::normalize([
            'preset' => 'Research',
            'selection_mode' => 'tournament',
        ]);

        self::assertSame(1, $meta['generation_meta_version']);
        self::assertSame('two_pass', $meta['pipeline_name']);
        self::assertSame('tournament_parallel_batches', $meta['selection_strategy']);
    }

    public function testBuildCostEstimateIncludesByPhase(): void
    {
        $estimate = SeismogrammPipelineMeta::buildCostEstimate([
            'prompt_tokens' => 1000,
            'output_tokens' => 200,
            'api_calls' => 4,
            'by_phase' => [
                'selection' => ['prompt_tokens' => 800, 'output_tokens' => 50, 'api_calls' => 3],
                'summary' => ['prompt_tokens' => 200, 'output_tokens' => 150, 'api_calls' => 1],
            ],
        ], 'tournament');

        self::assertNotNull($estimate);
        self::assertSame('tournament', $estimate['pipeline']);
        self::assertArrayHasKey('by_phase', $estimate);
        self::assertSame(800, $estimate['by_phase']['selection']['prompt_tokens']);
    }
}
