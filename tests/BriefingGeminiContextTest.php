<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Service\BriefingEntryGatherer;
use Seismo\Service\BriefingGeminiContext;
use Seismo\Service\BriefingSourceSelection;

final class BriefingGeminiContextTest extends TestCase
{
    public function testCapEntryListTruncatesBeyondMax(): void
    {
        $entries = array_fill(0, 120, ['entry_type' => 'feed_item', 'entry_id' => 1]);
        $capped  = BriefingGeminiContext::capEntryList($entries, BriefingGeminiContext::DEFAULT_MAX_ENTRIES);

        self::assertCount(BriefingGeminiContext::DEFAULT_MAX_ENTRIES, $capped['entries']);
        self::assertSame(20, $capped['truncated']);
    }

    public function testChunkEntryListSplitsByBatchSize(): void
    {
        $entries = array_fill(0, 25, ['entry_type' => 'feed_item', 'entry_id' => 1]);
        $chunks  = BriefingGeminiContext::chunkEntryList($entries, 10);

        self::assertCount(3, $chunks);
        self::assertCount(10, $chunks[0]);
        self::assertCount(10, $chunks[1]);
        self::assertCount(5, $chunks[2]);
    }

    public function testStratifiedCapReservesLexRowsWhenFeedsDominateRelevance(): void
    {
        $selection = BriefingSourceSelection::forModules(true, false, false, false, true, true);
        $gatherer  = new BriefingEntryGatherer();

        $feeds = [];
        for ($i = 1; $i <= 90; $i++) {
            $feeds[] = [
                'entry_type'      => 'feed_item',
                'entry_id'        => $i,
                'source_type'     => 'rss',
                'source_category' => 'news',
                'published_date'  => '2026-05-24',
            ];
        }

        $lex = [];
        for ($i = 1; $i <= 30; $i++) {
            $lex[] = [
                'entry_type'  => 'lex_item',
                'entry_id'    => $i,
                'source_type' => 'lex_de',
                'published_date' => '2026-05-24',
            ];
        }

        $leg = [[
            'entry_type'  => 'calendar_event',
            'entry_id'    => 1,
            'source_type' => 'parliament_ch',
            'published_date' => '2026-05-24',
        ]];

        $entries = array_merge($feeds, $lex, $leg);
        $scores  = [];
        foreach ($feeds as $row) {
            $key = $row['entry_type'] . ':' . $row['entry_id'];
            $scores[$key] = ['relevance_score' => 0.95];
        }
        foreach ($lex as $row) {
            $key = $row['entry_type'] . ':' . $row['entry_id'];
            $scores[$key] = ['relevance_score' => 0.40];
        }
        foreach ($leg as $row) {
            $key = $row['entry_type'] . ':' . $row['entry_id'];
            $scores[$key] = ['relevance_score' => 0.85];
        }

        $capped = BriefingGeminiContext::capEntryListStratified(
            $entries,
            100,
            $scores,
            $gatherer,
            $selection,
        );

        self::assertTrue($capped['stratified']);
        self::assertCount(100, $capped['entries']);

        $lexKept = 0;
        foreach ($capped['entries'] as $row) {
            if (($row['entry_type'] ?? '') === 'lex_item') {
                $lexKept++;
            }
        }

        self::assertGreaterThanOrEqual(30, $lexKept, 'Lex rows must not be dropped entirely when feeds score higher');
    }

    public function testRateLimitBatchedSelectionThresholdAllowsFallbackPool(): void
    {
        self::assertSame(2, BriefingGeminiContext::RATE_LIMIT_BATCHED_SELECTION_MIN_ENTRIES);
        self::assertGreaterThanOrEqual(
            BriefingGeminiContext::RATE_LIMIT_BATCHED_SELECTION_MIN_ENTRIES,
            BriefingGeminiContext::RATE_LIMIT_FALLBACK_MAX_ENTRIES,
        );
    }
}
