<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Service\Seismogramm\SeismogrammPresetProfile;

final class SeismogrammPresetProfileTest extends TestCase
{
    public function testBlindspotUsesRelationalSelection(): void
    {
        self::assertSame(
            'relational',
            SeismogrammPresetProfile::resolveSelectionMode(SeismogrammPresetProfile::BLINDSPOT, 50, 'standard', false),
        );
    }

    public function testResearchUsesTournamentSelection(): void
    {
        self::assertSame(
            'tournament',
            SeismogrammPresetProfile::resolveSelectionMode(SeismogrammPresetProfile::RESEARCH, 10, 'standard', false),
        );
    }

    public function testBriefingSwitchesToTournamentOnLargePool(): void
    {
        self::assertSame(
            'tournament',
            SeismogrammPresetProfile::resolveSelectionMode(SeismogrammPresetProfile::BRIEFING, 120, 'standard', false),
        );
        self::assertSame(
            'standard',
            SeismogrammPresetProfile::resolveSelectionMode(SeismogrammPresetProfile::BRIEFING, 40, 'standard', false),
        );
    }

    public function testBlindspotFiltersPrimaryPool(): void
    {
        $entries = [
            ['entry_type' => 'lex_item', 'entry_id' => '1'],
            ['entry_type' => 'feed_item', 'entry_id' => '2'],
            ['entry_type' => 'calendar_event', 'entry_id' => '3'],
        ];
        $filtered = SeismogrammPresetProfile::filterSelectionPool(SeismogrammPresetProfile::BLINDSPOT, $entries);
        self::assertCount(2, $filtered);
    }

    public function testResearchGatherDefaultsBypassMagnituAndEnableSnippets(): void
    {
        $defaults = SeismogrammPresetProfile::gatherDefaults(SeismogrammPresetProfile::RESEARCH, false);
        self::assertTrue($defaults['disregardMagnitu']);
        self::assertTrue($defaults['useRecipeSnippets']);
        self::assertSame(300, $defaults['maxContextFloor']);
    }
}
