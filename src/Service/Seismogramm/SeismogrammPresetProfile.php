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
    public const MONITOR   = 'Monitor';

    /** Switch Briefing to tournament when the capped pool exceeds this count. */
    public const TOURNAMENT_POOL_THRESHOLD = 80;

    public const RESEARCH_DEFAULT_MAX_CONTEXT = 300;

    public const POOL_PRIORITY_HIGHEST = 'highest';
    public const POOL_PRIORITY_NEWEST  = 'newest';

    public static function normalizePreset(string $raw): string
    {
        $raw = trim($raw);
        if (in_array($raw, [self::BRIEFING, self::BLINDSPOT, self::RESEARCH, self::MONITOR], true)) {
            return $raw;
        }

        return self::BRIEFING;
    }

    /**
     * Effective Briefing / Blindspot / Research behavior for pipeline steps.
     * Custom preset names normalize to Briefing for library lookup; this restores the intended mode.
     */
    public static function resolvePipelinePreset(
        string $presetRaw,
        ?string $postedBaseMode,
        ?string $selectionMode,
        bool $disregardMagnitu = false,
        bool $useRecipeSnippets = false,
    ): string {
        if (in_array($presetRaw, [self::BRIEFING, self::BLINDSPOT, self::RESEARCH, self::MONITOR], true)) {
            return $presetRaw;
        }

        if ($selectionMode === 'relational') {
            return self::BLINDSPOT;
        }

        if ($selectionMode === 'tournament' && ($disregardMagnitu || $useRecipeSnippets)) {
            return self::RESEARCH;
        }

        $posted = trim((string)$postedBaseMode);
        if (in_array($posted, [self::BRIEFING, self::BLINDSPOT, self::RESEARCH, self::MONITOR], true)) {
            return $posted;
        }

        return self::BRIEFING;
    }

    public static function resolveSelectionMode(string $preset, int $poolCount): string
    {
        if ($preset === self::BLINDSPOT) {
            return 'relational';
        }

        if ($preset === self::RESEARCH) {
            return 'tournament';
        }

        if ($poolCount > self::TOURNAMENT_POOL_THRESHOLD) {
            return 'tournament';
        }

        return 'standard';
    }

    public static function usesGlobalFingerprint(string $preset, string $selectionMode): bool
    {
        return $preset === self::BLINDSPOT;
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

        return array_values(array_filter(
            $entries,
            static fn(array $e): bool => in_array(
                (string)($e['entry_type'] ?? ''),
                ['lex_item', 'calendar_event'],
                true,
            ),
        ));
    }

    public static function defaultPoolPriority(string $preset): string
    {
        return $preset === self::RESEARCH
            ? self::POOL_PRIORITY_NEWEST
            : self::POOL_PRIORITY_HIGHEST;
    }

    public static function normalizePoolPriority(string $raw): string
    {
        return $raw === self::POOL_PRIORITY_NEWEST
            ? self::POOL_PRIORITY_NEWEST
            : self::POOL_PRIORITY_HIGHEST;
    }

    public static function allowsRateLimitUserRetry(string $preset): bool
    {
        return self::normalizePreset($preset) !== self::RESEARCH;
    }

    /**
     * Apply preset gather defaults unless the user opened advanced settings.
     *
     * @return array{disregardMagnitu: bool, useRecipeSnippets: bool, maxContextFloor: ?int, poolPriority: string}
     */
    public static function gatherDefaults(string $preset, bool $customAdvanced): array
    {
        if ($customAdvanced) {
            return [
                'disregardMagnitu'  => false,
                'useRecipeSnippets' => false,
                'maxContextFloor'   => null,
                'poolPriority'      => self::POOL_PRIORITY_HIGHEST,
            ];
        }

        return match ($preset) {
            self::RESEARCH => [
                'disregardMagnitu'  => true,
                'useRecipeSnippets' => true,
                'maxContextFloor'   => self::RESEARCH_DEFAULT_MAX_CONTEXT,
                'poolPriority'      => self::POOL_PRIORITY_NEWEST,
            ],
            default => [
                'disregardMagnitu'  => false,
                'useRecipeSnippets' => false,
                'maxContextFloor'   => null,
                'poolPriority'      => self::POOL_PRIORITY_HIGHEST,
            ],
        };
    }
}
