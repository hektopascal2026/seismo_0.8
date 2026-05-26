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
}
