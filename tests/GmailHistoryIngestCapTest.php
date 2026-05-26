<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Core\Mail\GmailHistoryIngestCap;

final class GmailHistoryIngestCapTest extends TestCase
{
    public function testCollectsOldestFirstUpToCap(): void
    {
        $batch = GmailHistoryIngestCap::collect(
            ['h1', 'h2'],
            [['a', 'b'], ['c', 'd']],
            3,
        );

        $this->assertSame(['a', 'b', 'c'], $batch['message_ids']);
        $this->assertSame('h1', $batch['checkpoint_history_id']);
        $this->assertTrue($batch['truncated']);
    }

    public function testPartialHistoryRecordDoesNotAdvanceCheckpoint(): void
    {
        $batch = GmailHistoryIngestCap::collect(
            ['h1', 'h2'],
            [['a'], ['b', 'c', 'd']],
            2,
        );

        $this->assertSame(['a', 'b'], $batch['message_ids']);
        $this->assertSame('h1', $batch['checkpoint_history_id']);
        $this->assertTrue($batch['truncated']);
    }

    public function testFullConsumeNotTruncated(): void
    {
        $batch = GmailHistoryIngestCap::collect(
            ['h1'],
            [['a', 'b']],
            10,
        );

        $this->assertSame(['a', 'b'], $batch['message_ids']);
        $this->assertSame('h1', $batch['checkpoint_history_id']);
        $this->assertFalse($batch['truncated']);
    }
}
