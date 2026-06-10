<?php

declare(strict_types=1);

namespace Seismo\Service\Seismogramm;

/**
 * Preset-driven gather and selection defaults for Seismogramm modes.
 */
final class SeismogrammPresetProfile
{
    public const BRIEFING  = 'Briefing';
    public const BLINDSPOT = 'Blindspot';
    public const RESEARCH  = 'Research';

    /** Switch Briefing to tournament when the capped pool exceeds this count. */
    public const TOURNAMENT_POOL_THRESHOLD = 80;

    public const RESEARCH_DEFAULT_MAX_CONTEXT = 300;

    public static function normalizePreset(string $raw): string
    {
        $raw = trim($raw);
        if (in_array($raw, [self::BRIEFING, self::BLINDSPOT, self::RESEARCH], true)) {
            return $raw;
        }

        return self::BRIEFING;
    }

    public static function resolveSelectionMode(
        string $preset,
        int $poolCount,
        string $postedMode,
        bool $customAdvanced,
    ): string {
        if ($preset === self::BLINDSPOT) {
            return 'relational';
        }

        if ($preset === self::RESEARCH) {
            return 'tournament';
        }

        if ($customAdvanced && in_array($postedMode, ['standard', 'tournament', 'relational'], true)) {
            return $postedMode;
        }

        if ($poolCount > self::TOURNAMENT_POOL_THRESHOLD) {
            return 'tournament';
        }

        return 'standard';
    }

    public static function usesGlobalFingerprint(string $preset, string $selectionMode): bool
    {
        return $preset === self::BLINDSPOT
            || $selectionMode === 'tournament'
            || $selectionMode === 'relational';
    }

    public static function usesNegativeSpaceProtocol(string $selectionMode): bool
    {
        return $selectionMode === 'relational';
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    public static function filterSelectionPool(string $preset, array $entries): array
    {
        if ($preset !== self::BLINDSPOT) {
            return $entries;
        }

        $primary = array_values(array_filter(
            $entries,
            static fn(array $e): bool => in_array(
                (string)($e['entry_type'] ?? ''),
                ['lex_item', 'calendar_event'],
                true,
            ),
        ));

        return $primary !== [] ? $primary : $entries;
    }

    /**
     * Apply preset gather defaults unless the user opened advanced settings.
     *
     * @return array{disregardMagnitu: bool, useRecipeSnippets: bool, maxContextFloor: ?int}
     */
    public static function gatherDefaults(string $preset, bool $customAdvanced): array
    {
        if ($customAdvanced) {
            return [
                'disregardMagnitu'  => false,
                'useRecipeSnippets' => false,
                'maxContextFloor'   => null,
            ];
        }

        return match ($preset) {
            self::RESEARCH => [
                'disregardMagnitu'  => true,
                'useRecipeSnippets' => true,
                'maxContextFloor'   => self::RESEARCH_DEFAULT_MAX_CONTEXT,
            ],
            default => [
                'disregardMagnitu'  => false,
                'useRecipeSnippets' => false,
                'maxContextFloor'   => null,
            ],
        };
    }
}
