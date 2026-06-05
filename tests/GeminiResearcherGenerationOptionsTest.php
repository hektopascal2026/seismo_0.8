<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Service\GeminiResearcherGenerationOptions;
use Seismo\Service\GeminiResearcherService;

final class GeminiResearcherGenerationOptionsTest extends TestCase
{
    public function testFromPostDefaultsOff(): void
    {
        $options = GeminiResearcherGenerationOptions::fromPost([]);
        self::assertSame(GeminiResearcherGenerationOptions::MODE_STANDARD, $options->selectionMode());
        self::assertFalse($options->tournamentMode());
        self::assertFalse($options->proSelectionMode);
    }

    public function testFromPostReadsSelectionModeAndProCheckbox(): void
    {
        $options = GeminiResearcherGenerationOptions::fromPost([
            'selection_mode'     => 'relational',
            'pro_selection_mode' => '1',
        ]);
        self::assertSame(GeminiResearcherGenerationOptions::MODE_RELATIONAL, $options->selectionMode());
        self::assertTrue($options->tournamentMode());
        self::assertTrue($options->proSelectionMode);
    }

    public function testFromPostLegacyTournamentCheckbox(): void
    {
        $options = GeminiResearcherGenerationOptions::fromPost([
            'tournament_mode' => '1',
        ]);
        self::assertSame(GeminiResearcherGenerationOptions::MODE_TOURNAMENT, $options->selectionMode());
        self::assertTrue($options->tournamentMode());
    }

    public function testTournamentSurvivorsForBatchSize(): void
    {
        self::assertSame(1, GeminiResearcherService::tournamentSurvivorsForBatchSize(1));
        self::assertSame(2, GeminiResearcherService::tournamentSurvivorsForBatchSize(2));
        self::assertSame(3, GeminiResearcherService::tournamentSurvivorsForBatchSize(30));
        self::assertSame(3, GeminiResearcherService::tournamentSurvivorsForBatchSize(100));
    }

    public function testUsesGemini31ProModelId(): void
    {
        self::assertTrue(GeminiResearcherService::usesGemini31Pro('gemini-3.1-pro-preview'));
        self::assertFalse(GeminiResearcherService::usesGemini31Pro('gemini-3.5-flash'));
    }

    public function testTournamentBatchSelectionTokenBudgetIsLargerThanDefault(): void
    {
        $default = GeminiResearcherService::resolveSelectionPassTokenBudget(5, 8192, 'gemini-3.5-flash');
        $batch   = GeminiResearcherService::resolveTournamentBatchSelectionTokenBudget(3, 8192, 'gemini-3.5-flash');
        self::assertGreaterThan($default, $batch);
    }
}
