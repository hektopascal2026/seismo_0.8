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

    public function testResolveOutputTokenBudgetClampsToPracticalCap(): void
    {
        self::assertSame(8192, GeminiBriefingService::resolveOutputTokenBudget(15, 8192, 'gemini-3.5-flash'));
        self::assertSame(17012, GeminiBriefingService::resolveOutputTokenBudget(15, 65536, 'gemini-3.5-flash'));
        self::assertSame(6012, GeminiBriefingService::resolveOutputTokenBudget(5, 8192, 'gemini-3.5-flash'));
        self::assertSame(7112, GeminiBriefingService::resolveOutputTokenBudget(6, 65536, 'gemini-3.5-flash'));
    }

    public function testResolveSelectionPassTokenBudgetIncludesReasoningHeadroom(): void
    {
        self::assertSame(656, GeminiBriefingService::resolveSelectionPassTokenBudget(6, 8192, 'gemini-3.5-flash'));
    }
}
