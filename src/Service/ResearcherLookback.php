<?php

declare(strict_types=1);

namespace Seismo\Service;

/**
 * Lookback window for AI Researcher (module + date range).
 *
 * Feed items use publication date or {@code cached_at} (ingestion), whichever is
 * later in the window — aligned with how operators see fresh items on module timelines.
 */
final class ResearcherLookback
{
    /**
     * SQL fragment + params for feed_items lookback (append after existing WHERE).
     *
     * @return array{sql: string, params: list<string>}
     */
    public static function feedItemsSinceClause(?string $since): array
    {
        if ($since === null || $since === '') {
            return ['sql' => '', 'params' => []];
        }

        return [
            'sql'    => ' AND (fi.published_date >= ? OR fi.cached_at >= ?)',
            'params' => [$since, $since],
        ];
    }

    public static function sinceUnix(?string $since): ?int
    {
        if ($since === null || $since === '') {
            return null;
        }

        return self::instantUnix($since) ?: null;
    }

    /**
     * Unix timestamp for researcher sort tie-break (newer = larger). Feed/calendar
     * use the latest of publication vs ingestion/fetch instants.
     *
     * @param array<string, mixed> $entry Shaped Magnitu row.
     */
    public static function entrySortTimestamp(array $entry): int
    {
        return match ((string)($entry['entry_type'] ?? '')) {
            'feed_item' => max(
                self::instantUnix((string)($entry['published_date'] ?? '')),
                self::instantUnix((string)($entry['cached_at'] ?? '')),
            ),
            'calendar_event' => max(
                self::instantUnix((string)($entry['published_date'] ?? '')),
                self::instantUnix((string)($entry['fetched_at'] ?? '')),
            ),
            default => self::instantUnix((string)($entry['published_date'] ?? '')),
        };
    }

    public static function instantUnix(string $stored): int
    {
        $stored = trim($stored);
        if ($stored === '') {
            return 0;
        }
        $ts = strtotime($stored);

        return $ts !== false ? $ts : 0;
    }

    /**
     * @param array<string, mixed> $entry Shaped Magnitu row.
     */
    public static function entryInWindow(array $entry, ?string $since): bool
    {
        $sinceTs = self::sinceUnix($since);
        if ($sinceTs === null) {
            return true;
        }

        return match ((string)($entry['entry_type'] ?? '')) {
            'feed_item' => self::feedItemInWindow($entry, $sinceTs),
            'email' => self::storedInstantInWindow((string)($entry['published_date'] ?? ''), $sinceTs),
            'lex_item' => self::storedInstantInWindow((string)($entry['published_date'] ?? ''), $sinceTs),
            'calendar_event' => self::calendarEventInWindow($entry, $sinceTs),
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function feedItemInWindow(array $entry, int $sinceTs): bool
    {
        foreach ([
            (string)($entry['published_date'] ?? ''),
            (string)($entry['cached_at'] ?? ''),
        ] as $stored) {
            if (self::storedInstantInWindow($stored, $sinceTs)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function calendarEventInWindow(array $entry, int $sinceTs): bool
    {
        if (self::storedInstantInWindow((string)($entry['published_date'] ?? ''), $sinceTs)) {
            return true;
        }

        return self::storedInstantInWindow((string)($entry['fetched_at'] ?? ''), $sinceTs);
    }

    private static function storedInstantInWindow(string $stored, int $sinceTs): bool
    {
        $ts = self::instantUnix($stored);

        return $ts > 0 && $ts >= $sinceTs;
    }
}
