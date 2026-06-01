<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Seismo\Repository\EntryRepository;

final class TimelineDeduplicationTest extends TestCase
{
    public function testDeduplicateTimelineItemsByLinkPrefersFeedItemOverEmail(): void
    {
        $repo   = new EntryRepository(new \PDO('sqlite::memory:'));
        $method = new ReflectionMethod(EntryRepository::class, 'deduplicateTimelineItemsByLink');

        $url = 'https://parlament.ch/de/services/news/Seiten/2026/20260601_bsd009.aspx';

        $items = [
            [
                'entry_type' => 'email',
                'entry_id'   => 1,
                'data'       => [
                    'subject'   => 'SDA News',
                    'html_body' => '',
                    'text_body' => 'Newsletter link: ' . $url,
                    'metadata'  => null,
                ],
                'score' => ['relevance_score' => 0.9],
            ],
            [
                'entry_type' => 'feed_item',
                'entry_id'   => 42,
                'data'       => [
                    'link' => $url,
                ],
                'score' => ['relevance_score' => 0.5],
            ]
        ];

        $deduped = $method->invoke($repo, $items);

        // Should keep only the feed_item and discard the email, even though the email has a higher score.
        self::assertCount(1, $deduped);
        self::assertSame('feed_item', $deduped[0]['entry_type']);
        self::assertSame(42, $deduped[0]['entry_id']);
    }

    public function testDeduplicateTimelineItemsByLinkSameTypePrefersHigherScore(): void
    {
        $repo   = new EntryRepository(new \PDO('sqlite::memory:'));
        $method = new ReflectionMethod(EntryRepository::class, 'deduplicateTimelineItemsByLink');

        $url = 'https://example.com/article';

        $items = [
            [
                'entry_type' => 'feed_item',
                'entry_id'   => 10,
                'data'       => [
                    'link' => $url,
                ],
                'score' => ['relevance_score' => 0.4],
            ],
            [
                'entry_type' => 'feed_item',
                'entry_id'   => 11,
                'data'       => [
                    'link' => $url,
                ],
                'score' => ['relevance_score' => 0.8],
            ]
        ];

        $deduped = $method->invoke($repo, $items);

        self::assertCount(1, $deduped);
        self::assertSame(11, $deduped[0]['entry_id']);
    }
}
