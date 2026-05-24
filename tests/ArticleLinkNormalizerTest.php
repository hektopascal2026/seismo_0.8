<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Fetcher\ArticleLinkNormalizer;

final class ArticleLinkNormalizerTest extends TestCase
{
    public function testStripsFragmentAndTrackingParams(): void
    {
        $a = 'https://www.watson.ch/schweiz/123?utm_source=rss#section';
        $b = 'https://watson.ch/schweiz/123';

        self::assertSame(ArticleLinkNormalizer::normalize($b), ArticleLinkNormalizer::normalize($a));
    }

    public function testTrailingSlashAndWwwAreEquivalent(): void
    {
        $a = 'https://www.example.com/news/story/';
        $b = 'https://example.com/news/story';

        self::assertSame(ArticleLinkNormalizer::normalize($a), ArticleLinkNormalizer::normalize($b));
    }

    public function testEmptyUrlReturnsEmpty(): void
    {
        self::assertSame('', ArticleLinkNormalizer::normalize(''));
    }
}
