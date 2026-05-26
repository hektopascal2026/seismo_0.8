<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Core\Fetcher\ArticleLinkNormalizer;

final class ArticleLinkNormalizerTest extends TestCase
{
    public function testStableFeedGuidDiffersWhenUrlsDifferAfterPrefix255(): void
    {
        $prefix = 'https://example.com/' . str_repeat('a', 240) . '/';
        $a      = $prefix . 'article-one';
        $b      = $prefix . 'article-two';

        self::assertNotSame(
            ArticleLinkNormalizer::stableFeedGuid($a),
            ArticleLinkNormalizer::stableFeedGuid($b),
        );
    }

    public function testStableFeedGuidMatchesForNormalizedEquivalentUrls(): void
    {
        $a = 'https://www.example.com/path/?utm_source=x';
        $b = 'https://example.com/path';

        self::assertSame(
            ArticleLinkNormalizer::stableFeedGuid($a),
            ArticleLinkNormalizer::stableFeedGuid($b),
        );
    }
}
