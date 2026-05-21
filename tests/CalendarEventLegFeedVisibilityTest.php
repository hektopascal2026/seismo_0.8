<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Repository\CalendarEventRepository;
use Seismo\Util\ParlChLegSignal;

final class CalendarEventLegFeedVisibilityTest extends TestCase
{
    public function testLegacyCompletedRowIsHidden(): void
    {
        $repo = new CalendarEventRepository($this->makePdo());
        $row = [
            'status'     => 'completed',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'metadata'   => null,
        ];
        $this->assertFalse($repo->rowVisibleInDefaultLegFeed($row));
    }

    public function testAntwortBrOlderThanSevenDaysIsHidden(): void
    {
        $repo = new CalendarEventRepository($this->makePdo());
        $row = [
            'status'   => 'scheduled',
            'metadata' => json_encode([
                'leg_signal'       => ParlChLegSignal::SIGNAL_ANTWORT_BR,
                'leg_feed_at'      => '2026-05-01 12:00:00',
                'has_br_response'  => true,
            ], JSON_THROW_ON_ERROR),
        ];
        $this->assertFalse($repo->rowVisibleInDefaultLegFeed($row));
    }

    public function testNewSubmissionWithinWindowIsVisible(): void
    {
        $repo = new CalendarEventRepository($this->makePdo());
        $row = [
            'status'     => 'scheduled',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'metadata'   => json_encode([
                'leg_signal'      => ParlChLegSignal::SIGNAL_NEW,
                'leg_feed_at'     => gmdate('Y-m-d') . ' 12:00:00',
                'submission_date' => gmdate('Y-m-d'),
            ], JSON_THROW_ON_ERROR),
        ];
        $this->assertTrue($repo->rowVisibleInDefaultLegFeed($row));
    }

    private function makePdo(): \PDO
    {
        return new \PDO('sqlite::memory:');
    }
}
