<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Fetcher\GoogleNewsArticleUrlResolver;

final class GoogleNewsArticleUrlResolverTest extends TestCase
{
    public function testResolveLeavesNormalUrlsUnchanged(): void
    {
        $resolver = new GoogleNewsArticleUrlResolver();
        $url      = 'https://www.nzz.ch/startseite.rss';
        self::assertSame($url, $resolver->resolve($url));
    }

    public function testResolveLeavesNonGoogleUrlsUnchanged(): void
    {
        $resolver = new GoogleNewsArticleUrlResolver();
        $url      = 'https://example.com/article';
        self::assertSame($url, $resolver->resolve($url));
    }
}
