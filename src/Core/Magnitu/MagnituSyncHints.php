<?php

declare(strict_types=1);

namespace Seismo\Core\Magnitu;

use Seismo\Service\ResearcherLookback;

/**
 * Pagination guidance for Magnitu v3 `?action=magnitu_entries` sync clients.
 *
 * Default export order is newest-first (DESC) with a per-family LIMIT. If the
 * client advances `since` to the newest timestamp in a full batch while more
 * rows remain in the window, older rows are skipped permanently. Use
 * `order=asc` and drain until each family returns fewer than `limit` rows.
 */
final class MagnituSyncHints
{
    public static function isAscendingOrder(?string $order): bool
    {
        return strtolower(trim((string)$order)) === 'asc';
    }

    /**
     * @param array<int, array<string, mixed>> $shapedEntries Rows from MagnituController::shape*()
     * @return array{
     *   order: string,
     *   limit_per_family: int,
     *   oldest_published_date: ?string,
     *   newest_published_date: ?string,
     *   recommended_next_since: ?string,
     *   drain_complete: bool,
     *   pagination_note: string
     * }
     */
    public static function forBatch(array $shapedEntries, bool $ascending, int $limitPerFamily): array
    {
        $limitPerFamily = max(1, $limitPerFamily);
        $instants       = [];
        foreach ($shapedEntries as $entry) {
            $ts = ResearcherLookback::entrySortTimestamp($entry);
            if ($ts > 0) {
                $instants[] = $ts;
            }
        }

        $oldest = $instants !== [] ? min($instants) : null;
        $newest = $instants !== [] ? max($instants) : null;
        $count  = count($shapedEntries);

        return [
            'order'                   => $ascending ? 'asc' : 'desc',
            'limit_per_family'        => $limitPerFamily,
            'oldest_published_date'   => self::formatInstant($oldest),
            'newest_published_date'   => self::formatInstant($newest),
            'recommended_next_since'  => $ascending && $newest !== null
                ? self::formatInstant($newest)
                : null,
            'drain_complete'          => $count < $limitPerFamily,
            'pagination_note'         => $ascending
                ? 'Sync-safe: repeat with order=asc per family until drain_complete; advance since to recommended_next_since (entry_id upserts dedupe same-second rows).'
                : 'Newest-first batch only: do not set since to newest_published_date when drain_complete is false or rows in the window may be skipped.',
        ];
    }

    private static function formatInstant(?int $unix): ?string
    {
        if ($unix === null || $unix <= 0) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $unix);
    }
}
