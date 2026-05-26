<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Service\BriefingEntryGatherer;

final class BriefingEntryGathererSortTest extends TestCase
{
    public function testSortByRelevanceDescThenNewest(): void
    {
        $entries = [
            ['entry_type' => 'feed_item', 'entry_id' => 1, 'published_date' => '2026-05-25T10:00:00Z'],
            ['entry_type' => 'feed_item', 'entry_id' => 2, 'published_date' => '2026-05-24T10:00:00Z'],
            ['entry_type' => 'feed_item', 'entry_id' => 3, 'published_date' => '2026-05-26T10:00:00Z'],
        ];
        $scoresByKey = [
            'feed_item:1' => ['relevance_score' => 0.9],
            'feed_item:2' => ['relevance_score' => 0.9],
            'feed_item:3' => ['relevance_score' => 0.7],
        ];

        $gatherer = new BriefingEntryGatherer();
        $gatherer->sortByRelevanceDesc($entries, $scoresByKey);

        self::assertSame(1, $entries[0]['entry_id']);
        self::assertSame(2, $entries[1]['entry_id']);
        self::assertSame(3, $entries[2]['entry_id']);
    }

    public function testDeduplicateFeedItemsByLinkKeepsHigherRelevance(): void
    {
        $entries = [
            [
                'entry_type' => 'feed_item',
                'entry_id'   => 1,
                'link'       => 'https://www.watson.ch/a/123?utm_source=feed-a',
            ],
            [
                'entry_type' => 'feed_item',
                'entry_id'   => 2,
                'link'       => 'https://www.watson.ch/a/123?utm_source=feed-b',
            ],
            [
                'entry_type' => 'email',
                'entry_id'   => 9,
                'link'       => '',
            ],
        ];
        $scoresByKey = [
            'feed_item:1' => ['relevance_score' => 0.4],
            'feed_item:2' => ['relevance_score' => 0.9],
            'email:9'     => ['relevance_score' => 0.5],
        ];

        $gatherer = new BriefingEntryGatherer();
        $deduped  = $gatherer->deduplicateFeedItemsByLink($entries, $scoresByKey);

        self::assertCount(2, $deduped);
        self::assertSame('feed_item', $deduped[0]['entry_type']);
        self::assertSame(2, $deduped[0]['entry_id']);
        self::assertSame('email', $deduped[1]['entry_type']);
    }
}
