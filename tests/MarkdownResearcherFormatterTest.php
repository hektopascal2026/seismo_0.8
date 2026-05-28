<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Formatter\MarkdownResearcherFormatter;

final class MarkdownResearcherFormatterTest extends TestCase
{
    public function testXmlFormatIncludesIdAndEscapesSpecialCharacters(): void
    {
        $entries = [[
            'entry_type'      => 'feed_item',
            'entry_id'        => '42',
            'title'           => 'A & B <test>',
            'link'            => 'https://example.com/x',
            'published_date'  => '2026-05-01',
            'source_name'     => 'Example',
            'source_type'     => 'feeds',
            'source_category' => 'news',
            'content'         => 'Body with "quotes" & ampersands.',
        ]];

        $xml = MarkdownResearcherFormatter::format(
            $entries,
            [],
            ['since' => '2026-05-01T00:00:00Z'],
            true,
            MarkdownResearcherFormatter::FORMAT_XML,
        );

        self::assertStringContainsString('<id>feed_item:42</id>', $xml);
        self::assertStringContainsString('<title>A &amp; B &lt;test&gt;</title>', $xml);
        self::assertStringContainsString('Body with &quot;quotes&quot; &amp; ampersands.', $xml);
        self::assertStringNotContainsString('[ID:', $xml);
    }

    public function testXmlTruncatesFeedBodyAtEntryBodyMaxChars(): void
    {
        $longBody = str_repeat('x', MarkdownResearcherFormatter::ENTRY_BODY_MAX_CHARS + 500);
        $entries  = [[
            'entry_type' => 'feed_item',
            'entry_id'   => 1,
            'title'      => 'Long article',
            'content'    => $longBody,
        ]];

        $xml = MarkdownResearcherFormatter::format(
            $entries,
            [],
            [],
            true,
            MarkdownResearcherFormatter::FORMAT_XML,
        );

        self::assertSame(2000, MarkdownResearcherFormatter::ENTRY_BODY_MAX_CHARS);
        self::assertStringNotContainsString(str_repeat('x', 2100), $xml);
        if (preg_match('/<content>(.*)<\/content>/s', $xml, $m) === 1) {
            $bodyLen = mb_strlen(html_entity_decode($m[1], ENT_XML1, 'UTF-8'), 'UTF-8');
            self::assertLessThanOrEqual(MarkdownResearcherFormatter::ENTRY_BODY_MAX_CHARS + 1, $bodyLen);
            self::assertGreaterThan(1900, $bodyLen);
        } else {
            self::fail('Expected <content> in XML output');
        }
    }

    public function testSanitizeLinkUrlEncodesParenthesesForMarkdown(): void
    {
        $method = new ReflectionMethod(MarkdownResearcherFormatter::class, 'sanitizeLinkUrl');

        $raw = 'https://en.wikipedia.org/wiki/Economy_of_Switzerland_(2020)';
        $out = $method->invoke(null, $raw);

        self::assertStringContainsString('%28', $out);
        self::assertStringContainsString('%29', $out);
        self::assertStringNotContainsString('(2020)', $out);
    }

    public function testMarkdownFormatUnchangedForExportStyle(): void
    {
        $entries = [[
            'entry_type' => 'lex_item',
            'entry_id'   => '9',
            'title'      => 'Lex title',
        ]];

        $md = MarkdownResearcherFormatter::format(
            $entries,
            [],
            [],
            true,
            MarkdownResearcherFormatter::FORMAT_MARKDOWN,
        );

        self::assertStringContainsString('[ID: lex_item:9]', $md);
        self::assertStringContainsString('- Lex title', $md);
    }

    public function testXmlLexDeIncludesJurisdictionAndSubstantiveBody(): void
    {
        $bgblHeader = "Bundesgesetzblatt\nAusgegeben zu Bonn\n";
        $operative = "Auf Grund des § 1 Abs. 2 des Gesetzes verordnet die Bundesregierung:\n§ 1 Geltungsbereich\nDiese Verordnung gilt für grenzüberschreitende Lieferketten.";
        $entries = [[
            'entry_type'      => 'lex_item',
            'entry_id'        => 7,
            'title'           => 'BGBl Verordnung',
            'source_type'     => 'lex_de',
            'source_category' => 'Gesetz',
            'content'         => $bgblHeader . $operative,
            'description'     => 'Kurzbeschreibung',
        ]];

        $xml = MarkdownResearcherFormatter::format(
            $entries,
            [],
            [],
            true,
            MarkdownResearcherFormatter::FORMAT_XML,
        );

        self::assertStringContainsString('<jurisdiction>DE</jurisdiction>', $xml);
        self::assertStringContainsString('§ 1 Geltungsbereich', $xml);
        self::assertStringNotContainsString('Ausgegeben zu Bonn', $xml);
    }

    public function testXmlLexFrStripsJorfBoilerplateInContent(): void
    {
        $content = "Assemblée nationale et le Sénat ont adopté\n"
            . "promulgue la loi dont la teneur suit :\n"
            . "Article 1er\nLes entreprises étrangères doivent déclarer leurs filiales "
            . "dans l'Union européenne conformément au présent article.";
        $entries = [[
            'entry_type'  => 'lex_item',
            'entry_id'    => 8,
            'title'       => 'Loi test',
            'source_type' => 'lex_fr',
            'content'     => $content,
        ]];

        $xml = MarkdownResearcherFormatter::format(
            $entries,
            [],
            [],
            true,
            MarkdownResearcherFormatter::FORMAT_XML,
        );

        self::assertStringContainsString('<jurisdiction>FR</jurisdiction>', $xml);
        self::assertStringContainsString('Article 1er', $xml);
        self::assertStringNotContainsString('promulgue la loi dont la teneur suit', $xml);
    }

    public function testExtractRecipeSnippets(): void
    {
        $content = "The Federal Council discussed a new carbon tax on Wednesday. "
            . "This measure is intended to incentivize renewable energy sources. "
            . "Several Swissmem member companies, including ABB and Bystronic, "
            . "welcomed the decision but called for transitional subsidies. "
            . "Meanwhile, the general public remains split on the policy's long-term effects.";

        // Match case-insensitive unigrams and n-grams
        $keywords = ['carbon tax', 'ABB', 'subsidies', 'nonexistent'];

        $snippet = MarkdownResearcherFormatter::extractRecipeSnippets($content, $keywords, 250);

        // Verify that matching keyword snippets are extracted and merged/separated
        self::assertStringContainsString('carbon tax', $snippet);
        self::assertStringContainsString('ABB', $snippet);
        self::assertStringContainsString('subsidies', $snippet);
        self::assertStringNotContainsString("policy's long-term effects", $snippet);

        // Verify fallback when no keywords match
        $fallback = MarkdownResearcherFormatter::extractRecipeSnippets($content, ['unmatched_keyword'], 50);
        self::assertSame(50, strlen($fallback));
        self::assertStringStartsWith("The Federal Council discussed a new carbon tax on ", $fallback);
    }
}
