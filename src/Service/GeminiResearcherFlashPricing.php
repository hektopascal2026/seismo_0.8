<?php

declare(strict_types=1);

namespace Seismo\Service;

/**
 * Gemini 3.5 Flash list prices (USD per 1M tokens), Standard synchronous API tier.
 *
 * @see https://ai.google.dev/gemini-api/docs/pricing
 */
final class GeminiResearcherFlashPricing
{
    public const TIER_STANDARD = 'standard';

    /** Paid tier, Standard tab — input tokens. */
    public const STANDARD_INPUT_USD_PER_M = 1.50;

    /** Paid tier, Standard tab — output incl. thinking tokens. */
    public const STANDARD_OUTPUT_USD_PER_M = 9.00;

    public static function isFlashModel(string $model): bool
    {
        $normalized = strtolower(trim($model));

        return str_contains($normalized, 'flash');
    }

    public static function estimateStandardUsd(int $promptTokens, int $outputTokens): float
    {
        if ($promptTokens < 0 || $outputTokens < 0) {
            return 0.0;
        }

        return ($promptTokens / 1_000_000) * self::STANDARD_INPUT_USD_PER_M
            + ($outputTokens / 1_000_000) * self::STANDARD_OUTPUT_USD_PER_M;
    }

    public static function formatUsd(float $amount): string
    {
        if ($amount < 0.0001) {
            return '$0.00';
        }
        if ($amount < 0.01) {
            return '$' . number_format($amount, 4, '.', '');
        }
        if ($amount < 1.0) {
            return '$' . number_format($amount, 3, '.', '');
        }

        return '$' . number_format($amount, 2, '.', '');
    }
}
