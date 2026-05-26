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
        $method->setAccessible(true);

        self::assertTrue($method->invoke($svc, 'https://example.com/', 'https://example.com/news/story-1'));
        self::assertFalse($method->invoke($svc, 'https://example.com/', 'https://example.com/'));
    }
}
