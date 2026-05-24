<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Fetcher\ArticlePageBodyExtractor;
use Seismo\Core\Fetcher\RssArticleHydrator;

final class RssArticleHydratorTest extends TestCase
{
    public function testNeedsHydrationWhenPlainBodyBelowThreshold(): void
    {
        $hydrator = new RssArticleHydrator();
        self::assertTrue($hydrator->needsHydration([
            'title'       => 'Headline',
            'content'     => '<p>Short</p>',
            'description' => '',
        ]));
    }

    public function testNeedsHydrationFalseWhenBodyIsLong(): void
    {
        $hydrator = new RssArticleHydrator();
        $long = str_repeat('Word ', 120);
        self::assertFalse($hydrator->needsHydration([
            'title'   => 'Headline',
            'content' => $long,
        ]));
    }

    public function testHydrateThinItemsDisabledReturnsUnchanged(): void
    {
        $hydrator = new RssArticleHydrator();
        $items = [
            ['title' => 'A', 'link' => 'https://example.com/a', 'content' => 'x'],
        ];
        self::assertSame($items, $hydrator->hydrateThinItems($items, false));
    }

    public function testNeedsHydrationTrueForTamediaRssTeaserOnly(): void
    {
        $hydrator = new RssArticleHydrator();
        $teaser   = 'Frontalangriff: Urs Wietlisbach wirft dem Bundesrat vor, die Folgen des EU-Pakets zu verharmlosen.';
        self::assertTrue($hydrator->needsHydration([
            'title'       => 'Kompassinitiative',
            'link'        => 'https://www.tagesanzeiger.ch/kompassinitiative-urs-wietlisbach-greift-bundesrat-an-998823266029',
            'content'     => '',
            'description' => $teaser,
        ]));
    }

    public function testHydrateThinItemsSkipsRowsThatAlreadyHaveBody(): void
    {
        $hydrator = new RssArticleHydrator();
        $long = str_repeat('Article text. ', 80);
        $items = [
            ['title' => 'A', 'link' => 'https://example.com/a', 'content' => $long],
            ['title' => 'B', 'link' => 'https://example.com/b', 'content' => 'tiny'],
        ];
        $out = $hydrator->hydrateThinItems($items, true, 0);
        self::assertSame($long, $out[0]['content']);
        self::assertSame('tiny', $out[1]['content']);
    }
}
