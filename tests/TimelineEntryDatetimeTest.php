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
    public function testFeedItemShowsUtcFaceAndSortsBySameInstant(): void
    {
        $row = ['published_date' => '2026-05-21 11:50:00'];
        self::assertSame('21.05.2026 11:50', TimelineEntryDatetime::formatFeedItemDatetime($row));
        self::assertGreaterThan(0, TimelineEntryDatetime::feedItemUnix($row));
    }

    public function testEmailLaterThanFeedWhenLabelsSaySo(): void
    {
        $feed = ['published_date' => '2026-05-21 10:00:00'];
        $mail = ['date_received' => '2026-05-21 11:50:00'];

        self::assertSame('21.05.2026 10:00', TimelineEntryDatetime::formatFeedItemDatetime($feed));
        self::assertSame('21.05.2026 13:50', TimelineEntryDatetime::formatEmailDatetime($mail));

        self::assertGreaterThan(
            TimelineEntryDatetime::feedItemUnix($feed),
            TimelineEntryDatetime::emailUnix($mail),
            '13:50 card must sort above 10:00 feed card'
        );
    }

    public function testMorningFeedAppearsBeforeNoonFeed(): void
    {
        $morning = ['published_date' => '2026-05-21 06:30:00'];
        $noon    = ['published_date' => '2026-05-21 10:00:00'];

        self::assertSame('21.05.2026 06:30', TimelineEntryDatetime::formatFeedItemDatetime($morning));
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

        self::assertSame('21.05.2026 09:00', $wrapper['clock_label']);
    }
}
