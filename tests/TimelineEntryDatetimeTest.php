<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Util\TimelineEntryDatetime;

/**
 * Dashboard timeline sort keys must match entry-card bottom-right clocks.
 */
final class TimelineEntryDatetimeTest extends TestCase
{
    public function testFeedItemShowsZurichClockMatchingSortInstant(): void
    {
        $row = ['published_date' => '2026-05-21 11:50:00'];
        self::assertSame('21.05.2026 13:50', TimelineEntryDatetime::formatFeedItemDatetime($row));
        self::assertGreaterThan(0, TimelineEntryDatetime::feedItemUnix($row));
    }

    public function testEmailLaterThanFeedWhenZurichLabelsSaySo(): void
    {
        $feed = ['published_date' => '2026-05-21 10:00:00'];
        $mail = ['date_received' => '2026-05-21 11:50:00'];

        self::assertSame('21.05.2026 12:00', TimelineEntryDatetime::formatFeedItemDatetime($feed));
        self::assertSame('21.05.2026 13:50', TimelineEntryDatetime::formatEmailDatetime($mail));

        self::assertGreaterThan(
            TimelineEntryDatetime::feedItemUnix($feed),
            TimelineEntryDatetime::emailUnix($mail),
            '13:50 card must sort above 12:00 feed card'
        );
    }

    public function testMorningFeedZurichLabelBeforeNoonFeed(): void
    {
        $morning = ['published_date' => '2026-05-21 06:30:00'];
        $noon    = ['published_date' => '2026-05-21 10:00:00'];

        self::assertSame('21.05.2026 08:30', TimelineEntryDatetime::formatFeedItemDatetime($morning));
        self::assertGreaterThan(
            TimelineEntryDatetime::feedItemUnix($morning),
            TimelineEntryDatetime::feedItemUnix($noon)
        );
    }

    public function testLexDateOnlyUsesCreatedAtForClockAndSort(): void
    {
        $row = [
            'document_date' => '2026-05-21',
            'created_at'    => '2026-05-21 08:15:00',
        ];

        self::assertSame('21.05.2026 10:15', TimelineEntryDatetime::formatLexItemDatetime($row));
        self::assertSame(
            TimelineEntryDatetime::storedUtcToUnix('2026-05-21 08:15:00'),
            TimelineEntryDatetime::lexItemUnix($row)
        );
    }

    public function testLegWithSignalUsesLegFeedAtForSortAndClock(): void
    {
        $row = [
            'event_date'  => '2026-05-21',
            'created_at'  => '2026-05-21 17:12:00',
            'metadata'    => json_encode([
                'leg_signal'  => 'antwort_br',
                'leg_feed_at' => '2026-05-13 12:00:00',
            ], JSON_THROW_ON_ERROR),
        ];

        self::assertSame('13.05.2026 14:00', TimelineEntryDatetime::formatCalendarEventDate($row));
        self::assertSame(
            TimelineEntryDatetime::storedUtcToUnix('2026-05-13 12:00:00'),
            TimelineEntryDatetime::calendarEventUnix($row)
        );
    }

    public function testLegWithoutSignalUsesCreatedAtNotEventDate(): void
    {
        $row = [
            'event_date'  => '2026-06-01 14:00:00',
            'created_at'  => '2026-05-21 09:45:00',
        ];

        self::assertSame(
            TimelineEntryDatetime::storedUtcToUnix('2026-05-21 09:45:00'),
            TimelineEntryDatetime::calendarEventUnix($row)
        );
    }

    public function testLegDateOnlyUsesCreatedAtLikeLex(): void
    {
        $row = [
            'event_date'  => '2026-05-21',
            'created_at'  => '2026-05-21 09:45:00',
        ];

        self::assertSame('21.05.2026 11:45', TimelineEntryDatetime::formatCalendarEventDate($row));
        self::assertSame(
            TimelineEntryDatetime::storedUtcToUnix('2026-05-21 09:45:00'),
            TimelineEntryDatetime::calendarEventUnix($row)
        );
    }

    public function testWrapperClockLabelMatchesDateUnix(): void
    {
        $row = ['published_date' => '2026-05-21 09:00:00'];
        $wrapper = [
            'entry_type' => 'feed_item',
            'date'       => TimelineEntryDatetime::feedItemUnix($row),
            'data'       => $row,
        ];
        $wrapper['clock_label'] = TimelineEntryDatetime::formatWrapperCardClock($wrapper);

        self::assertSame('21.05.2026 11:00', $wrapper['clock_label']);
    }

    public function testFutureFeedItemIsCappedAtCachedAt(): void
    {
        $row = [
            'published_date' => '2026-06-12 09:00:00', // future
            'cached_at'      => '2026-06-03 08:30:00', // ingestion (today)
        ];

        // The Unix timestamp should resolve to cached_at, not published_date
        self::assertSame(
            TimelineEntryDatetime::storedUtcToUnix('2026-06-03 08:30:00'),
            TimelineEntryDatetime::feedItemUnix($row)
        );

        // The clock label should format to Zurich time for cached_at (08:30 UTC -> 10:30 Zurich)
        self::assertSame('03.06.2026 10:30', TimelineEntryDatetime::formatFeedItemDatetime($row));
    }

    public function testFutureCalendarEventIsCappedAtCreatedAt(): void
    {
        $row = [
            'event_date'  => '2026-06-12',
            'created_at'  => '2026-06-03 08:57:00', // ingestion (today)
            'metadata'    => json_encode([
                'leg_signal'  => 'antwort_br',
                'leg_feed_at' => '2026-06-03 12:00:00', // future compared to created_at
            ], JSON_THROW_ON_ERROR),
        ];

        // The Unix timestamp should resolve to created_at
        self::assertSame(
            TimelineEntryDatetime::storedUtcToUnix('2026-06-03 08:57:00'),
            TimelineEntryDatetime::calendarEventUnix($row)
        );

        // The clock label should format to Zurich time for created_at (08:57 UTC -> 10:57 Zurich)
        self::assertSame('03.06.2026 10:57', TimelineEntryDatetime::formatCalendarEventDate($row));
    }
}
