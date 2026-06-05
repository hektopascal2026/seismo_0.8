<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Service\ResearcherGlobalFingerprint;

final class ResearcherGlobalFingerprintTest extends TestCase
{
    public function testBuildXmlIncludesEntries(): void
    {
        $xml = ResearcherGlobalFingerprint::buildXml([
            [
                'entry_type' => 'calendar_event',
                'entry_id'   => '11416',
                'title'      => 'Bundesrat session',
            ],
            [
                'entry_type' => 'feed_item',
                'entry_id'   => '42',
                'title'      => 'Industry news headline',
            ],
        ]);

        self::assertStringContainsString('<global_fingerprint>', $xml);
        self::assertStringContainsString('<id>calendar_event:11416</id>', $xml);
        self::assertStringContainsString('<module>leg</module>', $xml);
        self::assertStringContainsString('<id>feed_item:42</id>', $xml);
    }

    public function testBuildXmlEmptyForNoEntries(): void
    {
        self::assertSame('', ResearcherGlobalFingerprint::buildXml([]));
    }
}
