<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Lex\LexFedlexContentFetcher;
use Seismo\Plugin\LexFedlex\LexFedlexPlugin;

final class LexFedlexPluginTest extends TestCase
{
    public function testComposeFedlexDescriptionAmendmentWithDates(): void
    {
        $desc = LexFedlexPlugin::composeFedlexDescription(
            'Verordnung des BAKOM über Fernmeldedienste',
            'Bundesamt für Kommunikation',
            'Verordnung des BAKOM vom 9. Dezember 1997 über Fernmeldedienste',
            '2026-05-06',
            '2026-07-01',
            '2026-05-26',
            'de',
        );

        self::assertNotNull($desc);
        self::assertStringNotContainsString("Änderung\n", $desc);
        self::assertStringNotContainsString("\nÄnderung", $desc);
        self::assertSame(
            'Verordnung / Änderung',
            LexFedlexPlugin::documentTypePillLabel('Amtsverordnung', true, 'de'),
        );
        self::assertStringContainsString('Beschlossen am: 06.05.2026', $desc);
        self::assertStringContainsString('Inkrafttreten: 01.07.2026', $desc);
        self::assertStringContainsString('Bundesamt für Kommunikation', $desc);
        self::assertStringContainsString('Verordnung des BAKOM vom 9. Dezember 1997', $desc);
    }

    public function testComposeFedlexDescriptionHidesRedundantTaxonomy(): void
    {
        $title = 'Bundesgesetz über die Erfindungspatente';
        $desc = LexFedlexPlugin::composeFedlexDescription(
            $title,
            'Eidgenössisches Institut für Geistiges Eigentum',
            $title,
            '',
            '2027-01-01',
            '2026-05-21',
            'de',
        );

        self::assertNotNull($desc);
        self::assertStringContainsString('Inkrafttreten: 01.01.2027', $desc);
        self::assertStringNotContainsString($title . ' — ' . $title, $desc);
    }

    public function testPlainTextFromAkomaXmlExtractsPreambleAndLevels(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<akomaNtoso xmlns="http://docs.oasis-open.org/legaldocml/ns/akn/3.0">
<act><preface><p><docTitle>Testverordnung</docTitle></p><p>Änderung vom 6. Mai 2026</p></preface>
<preamble><p>Die Bundesversammlung,</p><p>beschliesst:</p></preamble>
<body><level eId="lvl_I"><num>I</num><p>Art. 5 wird wie folgt geändert:</p></level></body>
</act>
</akomaNtoso>
XML;

        $fetcher = new LexFedlexContentFetcher();
        $plain = $fetcher->plainTextFromAkomaXml($xml);

        self::assertNotNull($plain);
        self::assertStringContainsString('Änderung vom 6. Mai 2026', $plain);
        self::assertStringContainsString('Die Bundesversammlung', $plain);
        self::assertStringContainsString('Art. 5 wird wie folgt geändert', $plain);
    }

    public function testParseFedlexType(): void
    {
        self::assertSame('Verordnung', LexFedlexPlugin::parseFedlexType('https://fedlex.data.admin.ch/vocabulary/resource-type/1'));
        self::assertSame('Völkerrechtlicher Vertrag', LexFedlexPlugin::parseFedlexType('https://fedlex.data.admin.ch/vocabulary/resource-type/11'));
        self::assertSame('Bundesgesetz', LexFedlexPlugin::parseFedlexType('https://fedlex.data.admin.ch/vocabulary/resource-type/21'));
        self::assertSame('Other', LexFedlexPlugin::parseFedlexType('https://fedlex.data.admin.ch/vocabulary/resource-type/999'));
    }
}
