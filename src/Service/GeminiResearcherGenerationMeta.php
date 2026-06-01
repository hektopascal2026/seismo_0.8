<?php

declare(strict_types=1);

namespace Seismo\Service;

/**
 * Normalizes AI Researcher generation meta for API responses (success and failure).
 */
final class GeminiResearcherGenerationMeta
{
    public const META_VERSION = 1;

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $pipeline pool_entry_count, item_count, selection_target, context_entry_count
     * @return array<string, mixed>
     */
    public static function normalize(
        array $meta,
        GeminiResearcherGenerationOptions $options,
        array $pipeline = [],
    ): array {
        $poolCount = (int)($pipeline['pool_entry_count'] ?? $meta['pool_entry_count'] ?? $meta['context_entry_count'] ?? $meta['entries_sent_to_gemini'] ?? 0);
        $itemCount = (int)($pipeline['item_count'] ?? $meta['item_count'] ?? 0);
        $selectionTarget = (int)($pipeline['selection_target'] ?? $meta['selection_target'] ?? 0);
        if ($selectionTarget < 1 && $itemCount > 0) {
            $selectionTarget = min($itemCount, max(1, $poolCount));
        }

        $selectionStrategy = self::inferSelectionStrategy($meta, $options, $poolCount);
        $summaryStrategy   = self::inferSummaryStrategy($meta, $options, $itemCount);

        $selectionModel = trim((string)($meta['selection_model'] ?? $meta['model'] ?? ''));
        $summaryModel   = trim((string)($meta['summary_model'] ?? $meta['model'] ?? ''));

        $defaults = [
            'generation_meta_version'   => self::META_VERSION,
            'pipeline'                  => 'two_pass',
            'tournament_mode'           => $options->tournamentMode,
            'pro_selection_mode'        => $options->proSelectionMode,
            'pool_entry_count'          => $poolCount,
            'item_count'                => $itemCount,
            'selection_target'          => $selectionTarget,
            'selection_strategy'        => $selectionStrategy,
            'summary_strategy'          => $summaryStrategy,
            'selection_model'           => $selectionModel !== '' ? $selectionModel : null,
            'summary_model'             => $summaryModel !== '' ? $summaryModel : null,
            'model'                     => $summaryModel !== '' ? $summaryModel : ($meta['model'] ?? null),
            'dual_model_selection'      => $options->proSelectionMode
                && $selectionModel !== ''
                && $summaryModel !== ''
                && $selectionModel !== $summaryModel,
            'skinny_global_selection'   => $selectionStrategy === 'global_single_pass',
            'tournament_selection'      => str_starts_with($selectionStrategy, 'tournament_'),
            'selection_parallel'        => (bool)($meta['selection_parallel'] ?? false),
            'selection_batches'         => isset($meta['selection_batches']) ? (int)$meta['selection_batches'] : null,
            'selection_batch_size'      => isset($meta['selection_batch_size']) ? (int)$meta['selection_batch_size'] : null,
            'selection_championship'    => (bool)($meta['selection_championship'] ?? false),
            'selected_entry_keys'       => self::normalizeStringList($meta['selected_entry_keys'] ?? null),
            'selection_keys_count'      => self::countKeys($meta['selected_entry_keys'] ?? null),
            'summary_batches'           => isset($meta['summary_batches']) ? (int)$meta['summary_batches'] : null,
            'summary_batch_size'        => isset($meta['summary_batch_size']) ? (int)$meta['summary_batch_size'] : null,
            'batched_summary'           => (bool)($meta['batched_summary'] ?? false),
            'summary_proactive_batch'   => (bool)($meta['summary_proactive_batch'] ?? false),
            'summary_batch_retry_attempted' => (bool)($meta['summary_batch_retry_attempted'] ?? false),
            'rate_limit_fallback'       => (bool)($meta['rate_limit_fallback'] ?? false),
            'generation_failed'         => (bool)($meta['generation_failed'] ?? false),
        ];

        $merged = array_merge($defaults, $meta);

        if ($merged['selection_keys_count'] === 0 && is_array($merged['selected_entry_keys'])) {
            $merged['selection_keys_count'] = count($merged['selected_entry_keys']);
        }

        if ($merged['selection_model'] === null && isset($merged['model'])) {
            $merged['selection_model'] = $merged['model'];
        }
        if ($merged['summary_model'] === null && isset($merged['model'])) {
            $merged['summary_model'] = $merged['model'];
        }

        $merged['dual_model_selection'] = $options->proSelectionMode
            && is_string($merged['selection_model'])
            && is_string($merged['summary_model'])
            && $merged['selection_model'] !== $merged['summary_model'];

        $merged['meta_summary_line'] = self::formatSummaryLine($merged);
        $merged['cost_estimate']       = self::buildCostEstimate($merged);

        return $merged;
    }

    /**
     * Rough USD estimate from {@see GeminiResearcherService} usage totals (Flash Standard rates).
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>|null
     */
    public static function buildCostEstimate(array $meta): ?array
    {
        $usage = $meta['gemini_usage'] ?? null;
        if (!is_array($usage)) {
            return null;
        }

        $promptTokens = (int)($usage['prompt_tokens'] ?? 0);
        $outputTokens = (int)($usage['output_tokens'] ?? 0);
        if ($promptTokens < 1 && $outputTokens < 1) {
            return null;
        }

        $flashPrompt = (int)($usage['flash_prompt_tokens'] ?? $promptTokens);
        $flashOutput = (int)($usage['flash_output_tokens'] ?? $outputTokens);
        $proPrompt   = (int)($usage['pro_prompt_tokens'] ?? 0);
        $proOutput   = (int)($usage['pro_output_tokens'] ?? 0);

        $usd = GeminiResearcherFlashPricing::estimateStandardUsd($flashPrompt, $flashOutput);
        $pipelineLabel = !empty($meta['tournament_mode']) ? 'tournament' : 'standard';

        $estimate = [
            'pipeline'              => $pipelineLabel,
            'pricing_tier'          => GeminiResearcherFlashPricing::TIER_STANDARD,
            'model_priced'          => 'gemini-3.5-flash',
            'input_usd_per_m'       => GeminiResearcherFlashPricing::STANDARD_INPUT_USD_PER_M,
            'output_usd_per_m'      => GeminiResearcherFlashPricing::STANDARD_OUTPUT_USD_PER_M,
            'prompt_tokens'         => $promptTokens,
            'output_tokens'         => $outputTokens,
            'flash_prompt_tokens'   => $flashPrompt,
            'flash_output_tokens'   => $flashOutput,
            'api_calls'             => (int)($usage['api_calls'] ?? 0),
            'estimated_usd'         => round($usd, 6),
            'estimated_usd_display' => GeminiResearcherFlashPricing::formatUsd($usd),
            'disclaimer'            => 'Rough estimate from API usageMetadata; Standard (not Batch) Flash list prices.',
            'spend_console_url'     => 'https://aistudio.google.com/spend?project=gen-lang-client-0854484393',
        ];

        if ($proPrompt > 0 || $proOutput > 0) {
            $estimate['pro_prompt_tokens'] = $proPrompt;
            $estimate['pro_output_tokens'] = $proOutput;
            $estimate['pro_tokens_excluded'] = true;
            $estimate['disclaimer'] .= ' Pro selection tokens are shown but not priced here.';
        }

        if (isset($usage['by_phase']) && is_array($usage['by_phase'])) {
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

        if (isset($meta['max_context_entries'])) {
            $parts[] = 'cap ' . (int)$meta['max_context_entries'];
        }
        if (!empty($meta['entries_omitted_by_cap'])) {
            $parts[] = (int)$meta['entries_omitted_by_cap'] . ' omitted by cap';
        }
        if (isset($meta['selection_target'])) {
            $parts[] = (int)$meta['selection_target'] . ' picks requested';
        }
        if (isset($meta['selection_keys_count']) && (int)$meta['selection_keys_count'] > 0) {
            $parts[] = (int)$meta['selection_keys_count'] . ' selected';
        }

        if (!empty($meta['selection_strategy'])) {
            $parts[] = 'sel: ' . self::humanizeStrategy((string)$meta['selection_strategy']);
        }
        if (!empty($meta['summary_strategy'])) {
            $parts[] = 'sum: ' . self::humanizeStrategy((string)$meta['summary_strategy']);
        }

        if (!empty($meta['tournament_mode'])) {
            $parts[] = 'tournament';
        }
        if (!empty($meta['pro_selection_mode'])) {
            $parts[] = 'Pro sel';
        }
        if (!empty($meta['dual_model_selection']) && !empty($meta['selection_model'])) {
            $parts[] = 'sel model ' . (string)$meta['selection_model'];
        }
        if (!empty($meta['generation_failed'])) {
            $parts[] = 'failed';
        }

        return implode(' · ', $parts);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private static function inferSelectionStrategy(
        array $meta,
        GeminiResearcherGenerationOptions $options,
        int $poolCount,
    ): string {
        if (isset($meta['selection_strategy']) && is_string($meta['selection_strategy']) && $meta['selection_strategy'] !== '') {
            return $meta['selection_strategy'];
        }

        if (!empty($meta['tournament_selection']) || ($options->tournamentMode && $poolCount >= 2)) {
            if (!empty($meta['selection_parallel'])) {
                return 'tournament_parallel_batches';
            }

            return 'tournament_batches';
        }

        if (!empty($meta['skinny_global_selection'])) {
            return 'global_single_pass';
        }

        if (!empty($meta['batched_selection'])) {
            return 'rate_limit_batched_selection';
        }

        if ($options->tournamentMode) {
            return 'tournament_batches';
        }

        return 'global_single_pass';
    }

    /**
     * @param array<string, mixed> $meta
     */
    private static function inferSummaryStrategy(
        array $meta,
        GeminiResearcherGenerationOptions $options,
        int $itemCount,
    ): string {
        if (isset($meta['summary_strategy']) && is_string($meta['summary_strategy']) && $meta['summary_strategy'] !== '') {
            return $meta['summary_strategy'];
        }

        if (!empty($meta['summary_proactive_batch'])) {
            return 'proactive_batched';
        }

        if (!empty($meta['summary_batch_retry_attempted'])) {
            return 'reactive_batched';
        }

        if (!empty($meta['batched_summary'])) {
            return 'batched';
        }

        $proactiveMin = $options->tournamentMode
            ? GeminiResearcherService::PROACTIVE_SUMMARY_BATCH_MIN_ITEMS_TOURNAMENT
            : GeminiResearcherService::PROACTIVE_SUMMARY_BATCH_MIN_ITEMS;

        if ($itemCount >= $proactiveMin) {
            return 'proactive_batched_planned';
        }

        return 'monolithic_single_pass';
    }

    private static function humanizeStrategy(string $strategy): string
    {
        return str_replace('_', ' ', $strategy);
    }

    /**
     * @return list<string>|null
     */
    private static function normalizeStringList(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $list = [];
        foreach ($raw as $item) {
            if (is_string($item) && trim($item) !== '') {
                $list[] = trim($item);
            }
        }

        return $list === [] ? null : $list;
    }

    private static function countKeys(mixed $raw): int
    {
        if (!is_array($raw)) {
            return 0;
        }

        return count(array_filter($raw, static fn(mixed $v): bool => is_string($v) && trim($v) !== ''));
    }
}
