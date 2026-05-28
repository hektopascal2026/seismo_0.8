<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Service\GeminiResearcherService;

final class GeminiResearcherServiceTest extends TestCase
{
    public function testModelHardOutputCapForGemini35(): void
    {
        self::assertSame(
            GeminiResearcherService::MODEL_OUTPUT_CAP_GEMINI_35_FLASH,
            GeminiResearcherService::modelHardOutputCapFor('gemini-3.5-flash'),
        );
        self::assertTrue(GeminiResearcherService::usesGemini35Family('gemini-3.5-flash'));
    }

    public function testResolveOutputTokenBudgetClampsToPracticalCap(): void
    {
        self::assertSame(8192, GeminiResearcherService::resolveOutputTokenBudget(15, 8192, 'gemini-3.5-flash'));
        self::assertSame(49152, GeminiResearcherService::resolveOutputTokenBudget(15, 65536, 'gemini-3.5-flash'));
        self::assertSame(8192, GeminiResearcherService::resolveOutputTokenBudget(5, 8192, 'gemini-3.5-flash'));
        self::assertSame(27512, GeminiResearcherService::resolveOutputTokenBudget(6, 65536, 'gemini-3.5-flash'));
        self::assertSame(49152, GeminiResearcherService::resolveOutputTokenBudget(1, 65536, 'gemini-3.5-flash'));
    }

    public function testResolveSelectionPassTokenBudgetIncludesReasoningHeadroom(): void
    {
        self::assertSame(656, GeminiResearcherService::resolveSelectionPassTokenBudget(6, 8192, 'gemini-3.5-flash'));
    }
}
