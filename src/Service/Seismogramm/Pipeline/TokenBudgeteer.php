<?php

declare(strict_types=1);

namespace Seismo\Service\Seismogramm\Pipeline;

final class TokenBudgeteer
{
    public const DEFAULT_MODEL = 'gemini-3.5-flash';
    public const BRIEFING_SUMMARY_OUTPUT_CAP = 65536;
    public const OUTPUT_TOKEN_FLOOR = 2048;
    public const OUTPUT_TOKENS_PER_ITEM = 4500;
    public const SUMMARY_FIXED_OVERHEAD_TOKENS = 1536;

    public const SELECTION_OUTPUT_TOKENS_BASE = 128;
    public const SELECTION_OUTPUT_TOKENS_PER_ITEM = 120;
    public const SELECTION_REASONING_TOKEN_HEADROOM = 256;

    public const MODEL_HARD_OUTPUT_CAP = 65536;

    public static function usesGemini35Family(string $model): bool
    {
        return preg_match('/gemini-3[.-]5/i', $model) === 1;
    }

    public static function resolveOutputTokenBudget(
        int $itemCount,
        int $configuredMax,
        string $model = self::DEFAULT_MODEL
    ): int {
        $configuredMax = max(256, min(self::MODEL_HARD_OUTPUT_CAP, $configuredMax));
        $scaled = self::SUMMARY_FIXED_OVERHEAD_TOKENS + max(1, $itemCount) * self::OUTPUT_TOKENS_PER_ITEM;

        return min(
            self::BRIEFING_SUMMARY_OUTPUT_CAP,
            $configuredMax,
            max(self::OUTPUT_TOKEN_FLOOR, $scaled),
        );
    }

    public static function resolveSelectionPassTokenBudget(
        int $itemCount,
        int $configuredMax,
        string $model = self::DEFAULT_MODEL
    ): int {
        $configuredMax = max(512, min(self::MODEL_HARD_OUTPUT_CAP, $configuredMax));
        $visible       = self::SELECTION_OUTPUT_TOKENS_BASE
            + max(1, $itemCount) * self::SELECTION_OUTPUT_TOKENS_PER_ITEM
            + self::SELECTION_REASONING_TOKEN_HEADROOM;

        return min($configuredMax, max(512, $visible));
    }

    public static function resolveTournamentBatchSelectionTokenBudget(
        int $selectionTarget,
        int $configuredMax,
        string $model = self::DEFAULT_MODEL
    ): int {
        $configuredMax = max(512, min(self::MODEL_HARD_OUTPUT_CAP, $configuredMax));
        $visible       = self::SELECTION_OUTPUT_TOKENS_BASE
            + max(1, $selectionTarget) * self::SELECTION_OUTPUT_TOKENS_PER_ITEM
            + 2048;

        return min($configuredMax, max(1024, $visible));
    }

    /**
     * Applies Gemini 3.5 thinking levels (no temperature — matches legacy Researcher payloads).
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function applyGemini35Thinking(array $config, string $phase, string $model): array
    {
        if (!self::usesGemini35Family($model)) {
            return $config;
        }

        $level = $phase === 'selection' ? 'LOW' : 'MINIMAL';
        $config['thinkingConfig'] = ['thinkingLevel' => $level];

        return $config;
    }
}
