<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Service\BriefingLookback;

final class BriefingLookbackTest extends TestCase
{
    public function testFeedItemInWindowViaCachedAtWhenPublishedIsOlder(): void
    {
        $since = '2026-05-18T00:00:00Z';
        $entry = [
            'entry_type'      => 'feed_item',
            'published_date'  => '2026-05-01T10:00:00',
            'cached_at'       => '2026-05-20T12:00:00',
        ];

        self::assertTrue(BriefingLookback::entryInWindow($entry, $since));
    }

    public function testFeedItemOutOfWindowWhenBothDatesAreOlder(): void
    {
        $since = '2026-05-18T00:00:00Z';
        $entry = [
            'entry_type'     => 'feed_item',
            'published_date' => '2026-05-01T10:00:00',
            'cached_at'      => '2026-05-10T12:00:00',
        ];

        self::assertFalse(BriefingLookback::entryInWindow($entry, $since));
    }

    public function testFeedItemsSinceClauseDoublesSinceParam(): void
    {
        $clause = BriefingLookback::feedItemsSinceClause('2026-05-18T00:00:00Z');

        self::assertStringContainsString('published_date', $clause['sql']);
        self::assertStringContainsString('cached_at', $clause['sql']);
        self::assertSame(['2026-05-18T00:00:00Z', '2026-05-18T00:00:00Z'], $clause['params']);
    }

    public function testEntrySortTimestampUsesLatestFeedInstant(): void
    {
        $entry = [
            'entry_type'     => 'feed_item',
            'published_date' => '2026-05-01T10:00:00Z',
            'cached_at'      => '2026-05-22T08:00:00Z',
        ];

        self::assertSame(
            BriefingLookback::instantUnix('2026-05-22T08:00:00Z'),
            BriefingLookback::entrySortTimestamp($entry),
        );
    }
}
