<?php

declare(strict_types=1);

namespace Seismo\Util;

/**
 * Back-compat facade for the built-in Swissmem directory watchlist.
 */
final class SwissmemMatcher
{
    private static ?WatchlistMatcher $matcher = null;

    private static function matcher(): WatchlistMatcher
    {
        if (self::$matcher === null) {
            self::$matcher = WatchlistMatcher::fromBuiltInSwissmemFile();
        }

        return self::$matcher;
    }

    /**
     * @return array<int, string>
     */
    public static function getTerms(): array
    {
        return self::matcher()->terms();
    }

    public static function getRegexPattern(): string
    {
        return self::matcher()->regexPattern();
    }

    public static function matches(string $text): bool
    {
        return self::matcher()->matches($text);
    }

    /**
     * @param array<string, mixed> $item
     */
    public static function matchesTimelineItem(array $item): bool
    {
        return self::matcher()->matchesTimelineItem($item);
    }

    /**
     * @param array<string, mixed> $entry
     */
    public static function matchesShapedEntry(array $entry): bool
    {
        return self::matcher()->matchesShapedEntry($entry);
    }
}
