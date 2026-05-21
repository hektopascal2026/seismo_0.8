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
    public function testFeedItemSortUnixMatchesZurichFormattedClock(): void
    {
        $row = ['published_date' => '2026-05-21 11:50:00'];
        $label = TimelineEntryDatetime::formatFeedItemDatetime($row);
        self::assertSame('21.05.2026 13:50', $label);
        self::assertGreaterThan(0, TimelineEntryDatetime::feedItemUnix($row));
    }

    public function testEmailLaterThanFeedWhenLabelsSaySo(): void
    {
        $feed = ['published_date' => '2026-05-21 10:00:00'];
        $mail = ['date_received' => '2026-05-21 11:50:00'];

        $feedLabel = TimelineEntryDatetime::formatFeedItemDatetime($feed);
        $mailLabel = TimelineEntryDatetime::formatEmailDatetime($mail);
        self::assertSame('21.05.2026 12:00', $feedLabel);
        self::assertSame('21.05.2026 13:50', $mailLabel);

        self::assertGreaterThan(
            TimelineEntryDatetime::feedItemUnix($feed),
            TimelineEntryDatetime::emailUnix($mail),
            '13:50 card must sort above 12:00 card'
        );
    }

    public function testLexSortUsesDocumentDateLabelOnly(): void
    {
        $older = ['document_date' => '2026-05-19', 'created_at' => '2026-05-21 15:00:00'];
        $newer = ['document_date' => '2026-05-21', 'created_at' => '2026-05-21 08:00:00'];

        self::assertSame('19.05.2026', TimelineEntryDatetime::formatLexItemDate($older));
        self::assertGreaterThan(
            TimelineEntryDatetime::lexItemUnix($older),
            TimelineEntryDatetime::lexItemUnix($newer)
        );
    }

    public function testCalendarLabelSuffixDoesNotChangeSortKey(): void
    {
        $viewTz = TimelineEntryDatetime::viewTimezone();
        $today  = new \DateTimeImmutable('today', $viewTz);
        $row    = ['event_date' => $today->format('Y-m-d')];

        $label = TimelineEntryDatetime::formatCalendarEventDate($row);
        self::assertStringContainsString('(today)', $label);
        self::assertSame(
            TimelineEntryDatetime::unixDateOnlyInViewTz($row['event_date']),
            TimelineEntryDatetime::calendarEventUnix($row)
        );
    }
}
