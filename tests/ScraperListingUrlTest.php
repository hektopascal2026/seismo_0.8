<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Core\Fetcher\ScraperListingUrl;

final class ScraperListingUrlTest extends TestCase
{
    public function testNormalizeStripsTrailingSlashOnPath(): void
    {
        self::assertSame(
            'https://www.interpol.int/News-and-Events',
            ScraperListingUrl::normalize('https://www.interpol.int/News-and-Events/')
        );
    }

    public function testNormalizeLeavesUrlWithoutSlashUnchanged(): void
    {
        self::assertSame(
            'https://www.interpol.int/News-and-Events',
            ScraperListingUrl::normalize('https://www.interpol.int/News-and-Events')
        );
    }

    public function testNormalizeAsfinagPressemeldungen(): void
    {
        self::assertSame(
            'https://www.asfinag.at/ueber-uns/presse/pressemeldungen',
            ScraperListingUrl::normalize('https://www.asfinag.at/ueber-uns/presse/pressemeldungen/')
        );
    }

    public function testEquivalent(): void
    {
        self::assertTrue(ScraperListingUrl::equivalent(
            'https://www.interpol.int/News-and-Events/',
            'https://www.interpol.int/News-and-Events'
        ));
    }
}
