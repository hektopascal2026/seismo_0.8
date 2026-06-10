<?php

declare(strict_types=1);

namespace Seismo\Service\Seismogramm;

use Seismo\Service\GeminiResearcherFlashPricing;

/**
 * Preset-native API meta for Seismogramm generate responses.
 */
final class SeismogrammPipelineMeta
{
    public const META_VERSION = 1;

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public static function enrich(array $meta): array
    {
        $meta = self::normalize($meta);
        $line = self::formatSummaryLine($meta);
        if ($line !== '') {
            $meta['meta_summary_line'] = $line;
        }

        return $meta;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public static function normalize(array $meta): array
    {
        $meta['generation_meta_version'] = self::META_VERSION;
        $meta['pipeline_name'] = 'two_pass';

        if (!isset($meta['selection_strategy']) || !is_string($meta['selection_strategy'])) {
            $meta['selection_strategy'] = self::inferSelectionStrategy($meta);
        }

        if (!isset($meta['summary_strategy'])) {
            $meta['summary_strategy'] = 'monolithic_single_pass';
        }

        return $meta;
    }

    /**
     * @param array<string, mixed> $usage
     * @return array<string, mixed>|null
     */
    public static function buildCostEstimate(array $usage, string $selectionMode): ?array
    {
        $promptTokens = (int)($usage['prompt_tokens'] ?? 0);
        $outputTokens = (int)($usage['output_tokens'] ?? 0);
        if ($promptTokens < 1 && $outputTokens < 1) {
            return null;
        }

        $usd = GeminiResearcherFlashPricing::estimateStandardUsd($promptTokens, $outputTokens);

        $estimate = [
            'pipeline'              => $selectionMode,
            'pricing_tier'          => GeminiResearcherFlashPricing::TIER_STANDARD,
            'model_priced'          => 'gemini-3.5-flash',
            'prompt_tokens'         => $promptTokens,
            'output_tokens'         => $outputTokens,
            'api_calls'             => (int)($usage['api_calls'] ?? 0),
            'estimated_usd'         => round($usd, 6),
            'estimated_usd_display' => GeminiResearcherFlashPricing::formatUsd($usd),
            'disclaimer'            => 'Rough estimate from API usageMetadata; Standard Flash list prices.',
        ];

        if (isset($usage['by_phase']) && is_array($usage['by_phase']) && $usage['by_phase'] !== []) {
            $estimate['by_phase'] = $usage['by_phase'];
        }

        return $estimate;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function formatSummaryLine(array $meta): string
    {
        $parts = [];

        if (isset($meta['entries_sent_to_gemini'])) {
            $parts[] = (int)$meta['entries_sent_to_gemini'] . ' sent to Gemini';
        } elseif (isset($meta['pool_entry_count']) && (int)$meta['pool_entry_count'] > 0) {
            $parts[] = (int)$meta['pool_entry_count'] . ' in pool';
        }

        if (!empty($meta['preset']) && is_string($meta['preset'])) {
            $parts[] = (string)$meta['preset'];
        }

        if (!empty($meta['selection_mode']) && is_string($meta['selection_mode'])) {
            $parts[] = 'sel ' . str_replace('_', ' ', $meta['selection_mode']);
        }

        if (isset($meta['max_context_entries'])) {
            $parts[] = 'cap ' . (int)$meta['max_context_entries'];
        }

        if (!empty($meta['entries_omitted_by_cap'])) {
            $parts[] = (int)$meta['entries_omitted_by_cap'] . ' omitted by cap';
        }

        if (isset($meta['cited_entry_count']) && (int)$meta['cited_entry_count'] > 0) {
            $parts[] = (int)$meta['cited_entry_count'] . ' cited';
        }

        if (!empty($meta['global_fingerprint'])) {
            $parts[] = 'fingerprint';
        }

        if (!empty($meta['context_cache_used'])) {
            $parts[] = 'context cache';
        }

        $recoveryNote = self::batchRecoveryNote($meta);
        if ($recoveryNote !== '') {
            $parts[] = $recoveryNote;
        }

        $rateLimitNote = self::rateLimitRetryNote($meta);
        if ($rateLimitNote !== '') {
            $parts[] = $rateLimitNote;
        }

        return implode(' · ', $parts);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function rateLimitRetryNote(array $meta): string
    {
        if (empty($meta['rate_limit_user_retry'])) {
            return '';
        }

        $cap = (int)($meta['max_context_entries'] ?? $meta['effective_cap'] ?? 0);
        if ($cap < 1) {
            return 'rate-limit retry (reduced pool)';
        }

        return 'rate-limit retry (cap ' . $cap . ')';
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function batchRecoveryNote(array $meta): string
    {
        if (empty($meta['selection_batch_recovered'])) {
            return '';
        }

        $count = (int)($meta['selection_batch_recovered_count'] ?? 0);
        if ($count < 1) {
            return '';
        }

        return $count === 1
            ? '1 tournament batch recovered after retry'
            : $count . ' tournament batches recovered after retry';
    }

    /**
     * @param array<string, mixed> $meta
     */
    private static function inferSelectionStrategy(array $meta): string
    {
        return match ((string)($meta['selection_mode'] ?? 'standard')) {
            'relational' => 'relational_tournament',
            'tournament' => 'tournament_parallel_batches',
            default      => 'global_single_pass',
        };
    }
}
