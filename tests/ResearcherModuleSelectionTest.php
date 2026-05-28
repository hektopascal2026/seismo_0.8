<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Service\ResearcherEntryGatherer;
use Seismo\Service\ResearcherGeminiContext;
use Seismo\Service\ResearcherModuleGuard;
use Seismo\Service\ResearcherSourceSelection;

final class ResearcherModuleSelectionTest extends TestCase
{
    public function testFilterByModuleSelectionExcludesMediaWhenMediaDisabled(): void
    {
        $selection = ResearcherSourceSelection::forModules(true, false, false, false, true, false);
        $gatherer  = new ResearcherEntryGatherer();

        $entries = [
            [
                'entry_type'      => 'feed_item',
                'entry_id'        => 1,
                'source_type'     => 'rss',
                'source_category' => 'news',
            ],
            [
                'entry_type'      => 'feed_item',
                'entry_id'        => 2,
                'source_type'     => 'rss',
                'source_category' => 'media',
            ],
            [
                'entry_type'  => 'lex_item',
                'entry_id'    => 3,
                'source_type' => 'lex_de',
            ],
        ];

        $filtered = $gatherer->filterByModuleSelection($entries, $selection);

        self::assertCount(2, $filtered);
        self::assertSame(1, $filtered[0]['entry_id']);
        self::assertSame(3, $filtered[1]['entry_id']);
        self::assertSame('lex_de', $filtered[1]['source_type']);
    }

    public function testStratifiedCapNeverIncludesDeselectedMediaBucket(): void
    {
        $selection = ResearcherSourceSelection::forModules(true, false, false, false, false, false);
        $gatherer  = new ResearcherEntryGatherer();

        $feeds = [];
        for ($i = 1; $i <= 60; $i++) {
            $feeds[] = [
                'entry_type'      => 'feed_item',
                'entry_id'        => $i,
                'source_type'     => 'rss',
                'source_category' => 'news',
                'published_date'  => '2026-05-24',
            ];
        }

        $media = [];
        for ($i = 1; $i <= 60; $i++) {
            $media[] = [
                'entry_type'      => 'feed_item',
                'entry_id'        => 1000 + $i,
                'source_type'     => 'rss',
                'source_category' => 'media',
                'published_date'  => '2026-05-24',
            ];
        }

        $entries = array_merge($feeds, $media);
        $scores  = [];
        foreach ($entries as $row) {
            $key = $row['entry_type'] . ':' . $row['entry_id'];
            $scores[$key] = ['relevance_score' => 0.9];
        }

        $capped = ResearcherGeminiContext::capEntryListStratified(
            $entries,
            100,
            $scores,
            $gatherer,
            $selection,
        );

        self::assertCount(60, $capped['entries']);
        foreach ($capped['entries'] as $row) {
            self::assertNotSame(
                'media',
                strtolower((string)($row['source_category'] ?? '')),
                'Media rows must not reach Gemini when Media module is off',
            );
            self::assertSame('feeds', $gatherer->moduleBucketForEntry($row, $selection));
        }
    }

    public function testStratifiedCapSingleBucketFallbackStillExcludesDeselectedRows(): void
    {
        $selection = ResearcherSourceSelection::forModules(false, false, false, false, true, false);
        $gatherer  = new ResearcherEntryGatherer();

        $lex = [];
        for ($i = 1; $i <= 80; $i++) {
            $lex[] = [
                'entry_type'  => 'lex_item',
                'entry_id'    => $i,
                'source_type' => 'lex_de',
                'published_date' => '2026-05-24',
            ];
        }

        $sneakyMedia = [
            'entry_type'      => 'feed_item',
            'entry_id'        => 999,
            'source_type'     => 'rss',
            'source_category' => 'media',
            'published_date'  => '2026-05-24',
        ];

        $entries = array_merge($lex, [$sneakyMedia]);
        $scores  = [];
        foreach ($entries as $row) {
            $key = $row['entry_type'] . ':' . $row['entry_id'];
            $scores[$key] = ['relevance_score' => 0.99];
        }

        $capped = ResearcherGeminiContext::capEntryListStratified(
            $entries,
            100,
            $scores,
            $gatherer,
            $selection,
        );

        self::assertCount(80, $capped['entries']);
        foreach ($capped['entries'] as $row) {
            self::assertSame('lex_item', $row['entry_type']);
        }

        $guard  = new ResearcherModuleGuard($gatherer);
        $sealed = $guard->sealGeminiContext($capped['entries'], $scores, [], $selection);
        self::assertCount(80, $sealed['entries']);
        self::assertSame(80, count($guard->extractXmlEntryIds($sealed['markdown'])));
    }

    public function testOnlyLexEnabledExcludesAllNonLexTypes(): void
    {
        $selection = ResearcherSourceSelection::forModules(false, false, false, false, true, false);
        $gatherer  = new ResearcherEntryGatherer();
        $guard     = new ResearcherModuleGuard($gatherer);

        $mixed = [
            ['entry_type' => 'lex_item', 'entry_id' => 1, 'source_type' => 'lex_de', 'title' => 'Law'],
            ['entry_type' => 'feed_item', 'entry_id' => 2, 'source_type' => 'rss', 'source_category' => 'news', 'title' => 'News'],
            ['entry_type' => 'feed_item', 'entry_id' => 3, 'source_type' => 'rss', 'source_category' => 'media', 'title' => 'Media'],
            ['entry_type' => 'email', 'entry_id' => 4, 'source_type' => 'email', 'title' => 'Mail'],
            ['entry_type' => 'calendar_event', 'entry_id' => 5, 'source_type' => 'parliament_ch', 'title' => 'Leg'],
            ['entry_type' => 'feed_item', 'entry_id' => 6, 'source_type' => 'scraper', 'source_category' => 'scraper', 'title' => 'Scrape'],
        ];

        $sealed = $guard->sealGeminiContext($mixed, [], [], $selection);

        self::assertCount(1, $sealed['entries']);
        self::assertSame('lex_item', $sealed['entries'][0]['entry_type']);
        self::assertSame(['lex_item:1'], $guard->extractXmlEntryIds($sealed['markdown']));
    }
}
