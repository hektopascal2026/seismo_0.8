<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Fetcher\RssFeedItemFilter;

final class RssFeedItemFilterTest extends TestCase
{
    public function testSkipsGolemAdvertisementTitles(): void
    {
        $feed = 'https://rss.golem.de/rss.php?feed=RSS2.0';

        self::assertTrue(RssFeedItemFilter::shouldSkip(
            $feed,
            'Anzeige: IT-Jobs in DevOps, Administration und Security'
        ));
        self::assertTrue(RssFeedItemFilter::shouldSkip(
            $feed,
            'Anzeige: Höhenverstellbarer Schreibtisch für unter 65 Euro bei Amazon'
        ));
    }

    public function testKeepsNormalGolemArticles(): void
    {
        $feed = 'https://rss.golem.de/rss.php?feed=RSS2.0';

        self::assertFalse(RssFeedItemFilter::shouldSkip(
            $feed,
            'Maintal: Widerstand gegen Rechenzentrum in Deutschland'
        ));
        self::assertFalse(RssFeedItemFilter::shouldSkip(
            $feed,
            '(g+) Systemverständnis statt KI-Romantik: Warum Legacy-Modernisierung mit KI oft scheitert'
        ));
    }

    public function testDoesNotSkipAnzeigeOnOtherFeeds(): void
    {
        self::assertFalse(RssFeedItemFilter::shouldSkip(
            'https://www.nzz.ch/startseite.rss',
            'Anzeige: Something from another outlet'
        ));
    }
}
