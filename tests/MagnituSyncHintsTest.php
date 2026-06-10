<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Core\Magnitu\MagnituSyncHints;

final class MagnituSyncHintsTest extends TestCase
{
    public function testAscendingOrderParam(): void
    {
        $this->assertTrue(MagnituSyncHints::isAscendingOrder('asc'));
        $this->assertTrue(MagnituSyncHints::isAscendingOrder(' ASC '));
        $this->assertFalse(MagnituSyncHints::isAscendingOrder('desc'));
        $this->assertFalse(MagnituSyncHints::isAscendingOrder(null));
    }

    public function testAscendingBatchRecommendsNextSinceAndDrainFlag(): void
    {
        $entries = [
            [
                'entry_type'      => 'feed_item',
                'published_date'  => '2026-06-01 10:00:00',
                'cached_at'       => null,
            ],
            [
                'entry_type'      => 'feed_item',
                'published_date'  => '2026-06-02 12:00:00',
                'cached_at'       => null,
            ],
        ];

        $hints = MagnituSyncHints::forBatch($entries, true, 200);

        $this->assertSame('asc', $hints['order']);
        $this->assertSame('2026-06-01 10:00:00', $hints['oldest_published_date']);
        $this->assertSame('2026-06-02 12:00:00', $hints['newest_published_date']);
        $this->assertSame('2026-06-02 12:00:00', $hints['recommended_next_since']);
        $this->assertTrue($hints['drain_complete']);
    }

    public function testDescendingBatchDoesNotRecommendNextSince(): void
    {
        $entries = [
            [
                'entry_type'     => 'email',
                'published_date' => '2026-06-02 12:00:00',
            ],
        ];

        $hints = MagnituSyncHints::forBatch($entries, false, 200);

        $this->assertSame('desc', $hints['order']);
        $this->assertNull($hints['recommended_next_since']);
        $this->assertStringContainsString('do not set since', strtolower($hints['pagination_note']));
    }

    public function testFullBatchMarksDrainIncomplete(): void
    {
        $entries = array_fill(0, 200, [
            'entry_type'     => 'lex_item',
            'published_date' => '2026-06-01 00:00:00',
        ]);

        $hints = MagnituSyncHints::forBatch($entries, true, 200);

        $this->assertFalse($hints['drain_complete']);
    }

    public function testCalendarEventCursorUsesFetchedAtAndCapsAtNow(): void
    {
        $futureEvent = '2099-12-31 00:00:00';
        $fetchedAt = '2026-06-02 08:00:00';
        $entries = [
            [
                'entry_type'      => 'calendar_event',
                'published_date'  => $futureEvent,
                'fetched_at'      => $fetchedAt,
            ],
        ];

        $hints = MagnituSyncHints::forBatch($entries, true, 200, cursorOnIngestTimeOnly: true);

        $this->assertSame($futureEvent, $hints['newest_published_date']);
        $this->assertSame($fetchedAt, $hints['recommended_next_since']);
        $this->assertStringContainsString('fetched_at', $hints['pagination_note']);
    }
}
