<?php

declare(strict_types=1);

namespace Seismo\Service;

/**
 * Lookback window for AI Briefing Builder (module + date range).
 *
 * Feed items use publication date or {@code cached_at} (ingestion), whichever is
 * later in the window — aligned with how operators see fresh items on module timelines.
 */
final class BriefingLookback
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
        $ts = strtotime($since);

        return $ts !== false ? $ts : null;
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
        $stored = trim($stored);
        if ($stored === '') {
            return false;
        }
        $ts = strtotime($stored);

        return $ts !== false && $ts >= $sinceTs;
    }
}
