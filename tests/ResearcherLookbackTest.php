<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Service\ResearcherLookback;

final class ResearcherLookbackTest extends TestCase
{
    public function testFeedItemInWindowViaCachedAtWhenPublishedIsOlder(): void
    {
        $since = '2026-05-18T00:00:00Z';
        $entry = [
            'entry_type'      => 'feed_item',
            'published_date'  => '2026-05-01T10:00:00',
            'cached_at'       => '2026-05-20T12:00:00',
        ];

        self::assertTrue(ResearcherLookback::entryInWindow($entry, $since));
    }

    public function testFeedItemOutOfWindowWhenBothDatesAreOlder(): void
    {
        $since = '2026-05-18T00:00:00Z';
        $entry = [
            'entry_type'     => 'feed_item',
            'published_date' => '2026-05-01T10:00:00',
            'cached_at'      => '2026-05-10T12:00:00',
        ];

        self::assertFalse(ResearcherLookback::entryInWindow($entry, $since));
    }

    public function testFeedItemsSinceClauseDoublesSinceParam(): void
    {
        $clause = ResearcherLookback::feedItemsSinceClause('2026-05-18T00:00:00Z');

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
            ResearcherLookback::instantUnix('2026-05-22T08:00:00Z'),
            ResearcherLookback::entrySortTimestamp($entry),
        );
    }
}
