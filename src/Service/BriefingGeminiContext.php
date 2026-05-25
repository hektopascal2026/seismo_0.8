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

    /** Above this entry count, use two-pass + batched selection when needed. */
    public const AUTO_TWO_PASS_MIN_ENTRIES = 48;

    /** Run batched selection when the pool exceeds this many entries. */
    public const BATCHED_SELECTION_MIN_ENTRIES = 40;

    /** After HTTP 429 — smaller cap and always batched selection. */
    public const RATE_LIMIT_FALLBACK_MAX_ENTRIES = 50;

    public const RATE_LIMIT_FALLBACK_BATCH_SIZE = 20;

    public const RATE_LIMIT_BATCHED_SELECTION_MIN_ENTRIES = 12;

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
