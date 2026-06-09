<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Fetcher\RssFetchService;

final class RssFetchServiceSanitizationTest extends TestCase
{
    public function testSanitizeXmlValid(): void
    {
        $input = '<a><![CDATA[hello]]></a>';
        $this->assertSame($input, RssFetchService::sanitizeXml($input));
    }

    public function testSanitizeXmlUnclosedCdataAtEnd(): void
    {
        $input = '<a><![CDATA[hello';
        $expected = '<a><![CDATA[hello]]>';
        $this->assertSame($expected, RssFetchService::sanitizeXml($input));
    }

    public function testSanitizeXmlUnclosedCdataWithElementTags(): void
    {
        $input = '<description><![CDATA[hello</description>';
        $expected = '<description><![CDATA[hello]]></description>';
        $this->assertSame($expected, RssFetchService::sanitizeXml($input));
    }

    public function testSanitizeXmlNestedOrMissingClose(): void
    {
        $input = '<a><![CDATA[hello <![CDATA[world]]></a>';
        $expected = '<a><![CDATA[hello ]]><![CDATA[world]]></a>';
        $this->assertSame($expected, RssFetchService::sanitizeXml($input));
    }

    public function testSanitizeXmlInvalidControlCharacters(): void
    {
        $input = "<a><![CDATA[hello\x08world]]></a>";
        $expected = "<a><![CDATA[helloworld]]></a>";
        $this->assertSame($expected, RssFetchService::sanitizeXml($input));
    }

    public function testSanitizeXmlEncodingConversion(): void
    {
        // ISO-8859-1 conversion (ä in ISO-8859-1 is \xe4, in UTF-8 it is \xc3\xa4)
        $input = "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?><title><![CDATA[t\xe4st]]></title>";
        $expected = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><title><![CDATA[t\xc3\xa4st]]></title>";
        $this->assertSame($expected, RssFetchService::sanitizeXml($input));
    }
}
