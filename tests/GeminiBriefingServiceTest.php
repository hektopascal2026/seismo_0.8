<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Service\GeminiBriefingService;

final class GeminiBriefingServiceTest extends TestCase
{
    public function testModelHardOutputCapForGemini35(): void
    {
        self::assertSame(
            GeminiBriefingService::MODEL_OUTPUT_CAP_GEMINI_35_FLASH,
            GeminiBriefingService::modelHardOutputCapFor('gemini-3.5-flash'),
        );
        self::assertTrue(GeminiBriefingService::usesGemini35Family('gemini-3.5-flash'));
    }

    public function testModelHardOutputCapForGemini25(): void
    {
        self::assertSame(
            GeminiBriefingService::MODEL_OUTPUT_CAP_GEMINI_25_FLASH,
            GeminiBriefingService::modelHardOutputCapFor('gemini-2.5-flash'),
        );
        self::assertFalse(GeminiBriefingService::usesGemini35Family('gemini-2.5-flash'));
    }

    public function testResolveOutputTokenBudgetClampsToPracticalCap(): void
    {
        self::assertSame(6512, GeminiBriefingService::resolveOutputTokenBudget(15, 8192, 'gemini-3.5-flash'));
        self::assertSame(6512, GeminiBriefingService::resolveOutputTokenBudget(15, 8192, 'gemini-2.5-flash'));
        self::assertSame(2912, GeminiBriefingService::resolveOutputTokenBudget(6, 65536, 'gemini-3.5-flash'));
    }

    public function testResolveSelectionPassTokenBudgetIsSmall(): void
    {
        self::assertSame(272, GeminiBriefingService::resolveSelectionPassTokenBudget(6, 8192, 'gemini-3.5-flash'));
        self::assertLessThan(
            1024,
            GeminiBriefingService::resolveSelectionPassTokenBudget(10, 8192, 'gemini-2.5-flash'),
        );
    }
}
