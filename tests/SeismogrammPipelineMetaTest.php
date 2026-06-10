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
}
