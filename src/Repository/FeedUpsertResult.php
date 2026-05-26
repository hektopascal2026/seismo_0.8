<?php

declare(strict_types=1);

namespace Seismo\Repository;

/** Outcome of {@see FeedItemRepository::upsertFeedItems()} — inserted vs silently skipped rows. */
final class FeedUpsertResult
{
    public function __construct(
        public readonly int $inserted,
        public readonly int $skipped,
    ) {
    }
}
