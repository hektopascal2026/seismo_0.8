<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Formatter\MarkdownBriefingFormatter;

final class MarkdownBriefingFormatterTest extends TestCase
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

        $xml = MarkdownBriefingFormatter::format(
            $entries,
            [],
            ['since' => '2026-05-01T00:00:00Z'],
            true,
            MarkdownBriefingFormatter::FORMAT_XML,
        );

        self::assertStringContainsString('<id>feed_item:42</id>', $xml);
        self::assertStringContainsString('<title>A &amp; B &lt;test&gt;</title>', $xml);
        self::assertStringContainsString('Body with &quot;quotes&quot; &amp; ampersands.', $xml);
        self::assertStringNotContainsString('[ID:', $xml);
    }

    public function testMarkdownFormatUnchangedForExportStyle(): void
    {
        $entries = [[
            'entry_type' => 'lex_item',
            'entry_id'   => '9',
            'title'      => 'Lex title',
        ]];

        $md = MarkdownBriefingFormatter::format(
            $entries,
            [],
            [],
            true,
            MarkdownBriefingFormatter::FORMAT_MARKDOWN,
        );

        self::assertStringContainsString('[ID: lex_item:9]', $md);
        self::assertStringContainsString('- Lex title', $md);
    }
}
