<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Service\BriefingGeminiContext;

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
}
