<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Service\GeminiBriefingService;

final class GeminiBriefingServiceTest extends TestCase
{
    public function testResolveThinkingBudgetScalesAndCaps(): void
    {
        self::assertSame(768, GeminiBriefingService::resolveThinkingBudget(1));
        self::assertSame(3072, GeminiBriefingService::resolveThinkingBudget(10));
        self::assertSame(4096, GeminiBriefingService::resolveThinkingBudget(20));
    }

    public function testResolveOutputTokenBudgetScalesWithItemCount(): void
    {
        self::assertSame(8192, GeminiBriefingService::resolveOutputTokenBudget(1, 8192));
        self::assertSame(8192, GeminiBriefingService::resolveOutputTokenBudget(5, 8192));
        self::assertSame(12048, GeminiBriefingService::resolveOutputTokenBudget(10, 8192));
    }

    public function testResolveSelectionPassTokenBudgetIncludesThinkingHeadroom(): void
    {
        self::assertGreaterThan(
            GeminiBriefingService::resolveThinkingBudget(10),
            GeminiBriefingService::resolveSelectionPassTokenBudget(10, 8192),
        );
        self::assertSame(4800, GeminiBriefingService::resolveSelectionPassTokenBudget(10, 4096));
    }
}
