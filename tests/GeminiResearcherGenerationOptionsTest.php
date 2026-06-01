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
        self::assertFalse($options->tournamentMode);
        self::assertFalse($options->proSelectionMode);
    }

    public function testFromPostReadsCheckboxes(): void
    {
        $options = GeminiResearcherGenerationOptions::fromPost([
            'tournament_mode'      => '1',
            'pro_selection_mode' => '1',
        ]);
        self::assertTrue($options->tournamentMode);
        self::assertTrue($options->proSelectionMode);
    }

    public function testTournamentSurvivorsForBatchSize(): void
    {
        self::assertSame(1, GeminiResearcherService::tournamentSurvivorsForBatchSize(1));
        self::assertSame(2, GeminiResearcherService::tournamentSurvivorsForBatchSize(2));
        self::assertSame(3, GeminiResearcherService::tournamentSurvivorsForBatchSize(30));
        self::assertSame(3, GeminiResearcherService::tournamentSurvivorsForBatchSize(100));
    }
}
