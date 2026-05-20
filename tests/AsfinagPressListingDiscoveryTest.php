<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Fetcher\AsfinagPressListingDiscovery;

final class AsfinagPressListingDiscoveryTest extends TestCase
{
    private const FIXTURE_HTML = <<<'HTML'
<div id="vue-container-press"
     data-culture="de-DE"
     data-currentcontentkey="9e797728-85cb-498d-bd9b-26ad683869f8"
     data-presssearchurl="/umbraco/api/pressapi/SearchPressItems">
HTML;

    public function testRecognisesPressemeldungenListing(): void
    {
        $url = 'https://www.asfinag.at/ueber-uns/presse/pressemeldungen/';

        self::assertTrue(AsfinagPressListingDiscovery::isListingPage($url, self::FIXTURE_HTML));
    }

    public function testParsesListingMetaFromDataAttributes(): void
    {
        $meta = AsfinagPressListingDiscovery::parseListingMeta(self::FIXTURE_HTML);

        self::assertIsArray($meta);
        self::assertSame('9e797728-85cb-498d-bd9b-26ad683869f8', $meta['contentKey']);
        self::assertSame('de-DE', $meta['culture']);
        self::assertSame('/umbraco/api/pressapi/SearchPressItems', $meta['apiPath']);
    }

    public function testReturnsEmptyForUnrelatedHost(): void
    {
        self::assertFalse(
            AsfinagPressListingDiscovery::isListingPage('https://example.com/press/', self::FIXTURE_HTML)
        );
    }
}
