<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Service\Seismogramm\SeismogrammRequestContext;

final class SeismogrammRequestContextCapTest extends TestCase
{
    public function testHalveContextCapFloorsAtMinimum(): void
    {
        $ctx = new SeismogrammRequestContext();
        self::assertSame(50, $ctx->halveContextCap(100));
        self::assertSame(20, $ctx->halveContextCap(20));
    }

    public function testApplyContextCapHalvesOnUserRetry(): void
    {
        $ctx = new SeismogrammRequestContext();
        $filters = ['preset' => 'Briefing', 'customAdvanced' => false, 'gatherDefaults' => []];
        $applied = $ctx->applyContextCapForRequest($filters, 100, null, true);

        self::assertTrue($applied['rate_limit_user_retry']);
        self::assertSame(100, $applied['original_cap']);
        self::assertSame(50, $applied['effective_cap']);
        self::assertSame(50, $applied['filters']['maxContextEntries']);
    }
}
