<?php

declare(strict_types=1);

namespace Seismo\Service;

use Seismo\Repository\SystemConfigRepository;

/**
 * Limits and batching for AI Briefing Builder → Gemini (RPM/TPM safety).
 */
final class BriefingGeminiContext
{
    public const CONFIG_KEY_MAX_ENTRIES = 'briefing:max_context_entries';

    public const CONFIG_KEY_BATCH_SIZE = 'briefing:selection_batch_size';

    public const DEFAULT_MAX_ENTRIES = 100;

    public const DEFAULT_BATCH_SIZE = 35;

    /** Pause between batched selection API calls (seconds). */
    public const BATCH_PAUSE_SECONDS = 5;

    /** Briefing Builder always uses skinny two-pass when entry count is at least this. */
    public const AUTO_TWO_PASS_MIN_ENTRIES = 1;

    /**
     * Batched selection disabled for normal runs (global pass-1 sees full capped pool).
     * Kept high so only explicit rate-limit fallback paths may batch if lowered in config.
     */
    public const BATCHED_SELECTION_MIN_ENTRIES = 10_000;

    /** After HTTP 429 — smaller cap and always batched selection. */
    public const RATE_LIMIT_FALLBACK_MAX_ENTRIES = 50;

    public const RATE_LIMIT_FALLBACK_BATCH_SIZE = 20;

    public const RATE_LIMIT_BATCHED_SELECTION_MIN_ENTRIES = 10_000;

    /** Wait before automatic retry after rate limit. */
    public const RATE_LIMIT_RETRY_PAUSE_SECONDS = 12;

    public const RATE_LIMIT_BATCH_PAUSE_SECONDS = 8;

    public function __construct(
        private readonly SystemConfigRepository $config,
    ) {
    }

    public function maxContextEntries(): int
    {
        return self::clampIntConfig(
            $this->config->get(self::CONFIG_KEY_MAX_ENTRIES),
            self::DEFAULT_MAX_ENTRIES,
            20,
            300,
        );
    }

    public function selectionBatchSize(): int
    {
        return self::clampIntConfig(
            $this->config->get(self::CONFIG_KEY_BATCH_SIZE),
            self::DEFAULT_BATCH_SIZE,
            15,
            80,
        );
    }

    /** Smaller entry cap used when retrying after Gemini HTTP 429. */
    public function rateLimitFallbackMaxEntries(): int
    {
        $normal = $this->maxContextEntries();

        return max(20, min(self::RATE_LIMIT_FALLBACK_MAX_ENTRIES, (int)floor($normal / 2)));
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return array{entries: list<array<string, mixed>>, truncated: int}
     */
    public function capEntries(array $entries): array
    {
        return self::capEntryList($entries, $this->maxContextEntries());
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @return array{entries: list<array<string, mixed>>, truncated: int, stratified: bool}
     */
    public function capEntriesForModules(
        array $entries,
        array $scoresByKey,
        BriefingEntryGatherer $gatherer,
        BriefingSourceSelection $selection,
    ): array {
        return self::capEntryListStratified(
            $entries,
            $this->maxContextEntries(),
            $scoresByKey,
            $gatherer,
            $selection,
        );
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<list<array<string, mixed>>>
     */
    public function chunkEntries(array $entries): array
    {
        return self::chunkEntryList($entries, $this->selectionBatchSize());
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return array{entries: list<array<string, mixed>>, truncated: int}
     */
    public static function capEntryList(array $entries, int $max): array
    {
        if (count($entries) <= $max) {
            return ['entries' => $entries, 'truncated' => 0];
        }

        return [
            'entries'   => array_slice($entries, 0, $max),
            'truncated' => count($entries) - $max,
        ];
    }

    /**
     * Cap with a fair share per enabled nav module so Lex/Leg rows are not dropped
     * when Feeds/Media dominate relevance sort order (e.g. Legal Researcher prompts).
     *
     * @param list<array<string, mixed>> $entries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @return array{entries: list<array<string, mixed>>, truncated: int, stratified: bool}
     */
    public static function capEntryListStratified(
        array $entries,
        int $max,
        array $scoresByKey,
        BriefingEntryGatherer $gatherer,
        BriefingSourceSelection $selection,
    ): array {
        $entries = $gatherer->filterByModuleSelection($entries, $selection);
        if ($entries === []) {
            return ['entries' => [], 'truncated' => 0, 'stratified' => false];
        }

        if (count($entries) <= $max) {
            return ['entries' => $entries, 'truncated' => 0, 'stratified' => false];
        }

        /** @var array<string, list<array<string, mixed>>> $buckets */
        $buckets = [];
        foreach ($entries as $entry) {
            $bucket = $gatherer->moduleBucketForEntry($entry, $selection);
            if ($bucket === null) {
                continue;
            }
            $buckets[$bucket][] = $entry;
        }

        /** @var list<array<string, mixed>> $eligible */
        $eligible = [];
        foreach ($buckets as $bucketEntries) {
            foreach ($bucketEntries as $entry) {
                $eligible[] = $entry;
            }
        }

        if ($eligible === []) {
            return ['entries' => [], 'truncated' => count($entries), 'stratified' => false];
        }

        if (count($buckets) <= 1) {
            $plain = self::capEntryList($eligible, $max);

            return [
                'entries'    => $plain['entries'],
                'truncated'  => count($entries) - count($plain['entries']),
                'stratified' => false,
            ];
        }

        foreach ($buckets as &$bucketEntries) {
            usort($bucketEntries, static function (array $a, array $b) use ($scoresByKey): int {
                $ka = ($a['entry_type'] ?? '') . ':' . ($a['entry_id'] ?? '');
                $kb = ($b['entry_type'] ?? '') . ':' . ($b['entry_id'] ?? '');
                $sa = (float)($scoresByKey[$ka]['relevance_score'] ?? 0);
                $sb = (float)($scoresByKey[$kb]['relevance_score'] ?? 0);
                if ($sa !== $sb) {
                    return $sb <=> $sa;
                }

                return BriefingLookback::entrySortTimestamp($b) <=> BriefingLookback::entrySortTimestamp($a);
            });
        }
        unset($bucketEntries);

        $bucketKeys = array_keys($buckets);
        $bucketCount = count($bucketKeys);
        $baseQuota   = intdiv($max, $bucketCount);
        if ($baseQuota < 1) {
            $baseQuota = 1;
        }

        /** @var list<array<string, mixed>> $picked */
        $picked = [];
        /** @var array<string, int> $cursor */
        $cursor = array_fill_keys($bucketKeys, 0);

        foreach ($bucketKeys as $bucket) {
            $take = min(count($buckets[$bucket]), $baseQuota);
            for ($i = 0; $i < $take; $i++) {
                $picked[] = $buckets[$bucket][$cursor[$bucket]++];
            }
        }

        if (count($picked) < $max) {
            $remaining = [];
            foreach ($bucketKeys as $bucket) {
                $list = $buckets[$bucket];
                for ($i = $cursor[$bucket], $n = count($list); $i < $n; $i++) {
                    $remaining[] = $list[$i];
                }
            }
            usort($remaining, static function (array $a, array $b) use ($scoresByKey): int {
                $ka = ($a['entry_type'] ?? '') . ':' . ($a['entry_id'] ?? '');
                $kb = ($b['entry_type'] ?? '') . ':' . ($b['entry_id'] ?? '');
                $sa = (float)($scoresByKey[$ka]['relevance_score'] ?? 0);
                $sb = (float)($scoresByKey[$kb]['relevance_score'] ?? 0);
                if ($sa !== $sb) {
                    return $sb <=> $sa;
                }

                return BriefingLookback::entrySortTimestamp($b) <=> BriefingLookback::entrySortTimestamp($a);
            });
            $need = $max - count($picked);
            if ($need > 0 && $remaining !== []) {
                $picked = array_merge($picked, array_slice($remaining, 0, $need));
            }
        }

        usort($picked, static function (array $a, array $b) use ($scoresByKey): int {
            $ka = ($a['entry_type'] ?? '') . ':' . ($a['entry_id'] ?? '');
            $kb = ($b['entry_type'] ?? '') . ':' . ($b['entry_id'] ?? '');
            $sa = (float)($scoresByKey[$ka]['relevance_score'] ?? 0);
            $sb = (float)($scoresByKey[$kb]['relevance_score'] ?? 0);
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }

            return BriefingLookback::entrySortTimestamp($b) <=> BriefingLookback::entrySortTimestamp($a);
        });

        return [
            'entries'    => $picked,
            'truncated'  => count($entries) - count($picked),
            'stratified' => true,
        ];
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<list<array<string, mixed>>>
     */
    public static function chunkEntryList(array $entries, int $batchSize): array
    {
        if ($entries === [] || count($entries) <= $batchSize) {
            return $entries === [] ? [] : [$entries];
        }

        return array_values(array_chunk($entries, $batchSize));
    }

    private static function clampIntConfig(mixed $raw, int $default, int $min, int $max): int
    {
        $s = trim((string)($raw ?? ''));
        if ($s === '' || !ctype_digit($s)) {
            return $default;
        }

        return max($min, min($max, (int)$s));
    }
}
