<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Core\Fetcher\ScraperFetchService;

final class ScraperArticlePathTest extends TestCase
{
    public function testRootListingAcceptsFirstLevelArticlePaths(): void
    {
        $svc    = new ScraperFetchService();
        $method = new ReflectionMethod(ScraperFetchService::class, 'hasArticleSlugBeyondListing');

        self::assertTrue($method->invoke($svc, 'https://example.com/', 'https://example.com/news/story-1'));
        self::assertFalse($method->invoke($svc, 'https://example.com/', 'https://example.com/'));
    }

    public function testScrapeHostsMatchAllowsWwwMismatch(): void
    {
        $svc    = new ScraperFetchService();
        $method = new ReflectionMethod(ScraperFetchService::class, 'scrapeHostsMatch');

        self::assertTrue($method->invoke($svc, 'example.com', 'www.example.com'));
        self::assertTrue($method->invoke($svc, 'www.example.com', 'example.com'));
        self::assertFalse($method->invoke($svc, 'example.com', 'other.com'));
    }

    public function testIsListingPaginationUrlFiltersCorrectly(): void
    {
        $svc    = new ScraperFetchService();
        $method = new ReflectionMethod(ScraperFetchService::class, 'isListingPaginationUrl');

        // Traditional query pagination
        self::assertTrue($method->invoke($svc, 'https://example.com/news?page=2'));
        self::assertTrue($method->invoke($svc, 'https://example.com/news?limit=10&page=3'));

        // Path-based pagination
        self::assertTrue($method->invoke($svc, 'https://example.com/news/p2'));
        self::assertTrue($method->invoke($svc, 'https://example.com/news/p12'));
        self::assertTrue($method->invoke($svc, 'https://example.com/news/page/5'));
        self::assertTrue($method->invoke($svc, 'https://example.com/news/seite-3'));
        self::assertTrue($method->invoke($svc, 'https://example.com/news/p/4'));

        // Category/tag index pages
        self::assertTrue($method->invoke($svc, 'https://example.com/themen/kategorie/nachhaltigkeit'));
        self::assertTrue($method->invoke($svc, 'https://example.com/news/category/politics'));
        self::assertTrue($method->invoke($svc, 'https://example.com/tags/news'));

        // Real articles (should not be filtered)
        self::assertFalse($method->invoke($svc, 'https://example.com/news/story-1'));
        self::assertFalse($method->invoke($svc, 'https://example.com/themen/sustainable-strategy-2026'));
        self::assertFalse($method->invoke($svc, 'https://example.com/category/politics/new-policy-announcement'));
    }
}
