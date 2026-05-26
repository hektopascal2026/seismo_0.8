<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Service\PluginRunResult;

final class PluginRunResultTest extends TestCase
{
    public function testBatchFeedsAppendsUpsertSkipNote(): void
    {
        $r = PluginRunResult::batchFeeds(3, 2, 0, 5);

        $this->assertSame('ok', $r->status);
        $this->assertStringContainsString('5 feed row(s) skipped at upsert', (string)$r->message);
    }

    public function testThrottleSkippedPersistsToPluginRunLog(): void
    {
        $r = PluginRunResult::throttleSkipped('Throttled — last successful run is fresher than 900s.');

        $this->assertTrue($r->persistToPluginRunLog);
        $this->assertTrue($r->isThrottleSkipped());
    }
}
