<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Formatter\MarkdownResearcherFormatter;

final class MarkdownResearcherFormatterBodyBudgetTest extends TestCase
{
    public function testDynamicEntryBodyMaxCharsScalesDownWithLargePool(): void
    {
        self::assertSame(5000, MarkdownResearcherFormatter::dynamicEntryBodyMaxChars(100));
    }

    public function testDynamicEntryBodyMaxCharsScalesUpWithSmallPool(): void
    {
        self::assertSame(12000, MarkdownResearcherFormatter::dynamicEntryBodyMaxChars(8));
    }

    public function testDynamicEntryBodyMaxCharsUsesDefaultForEmptyPool(): void
    {
        self::assertSame(
            MarkdownResearcherFormatter::ENTRY_BODY_DEFAULT_CHARS,
            MarkdownResearcherFormatter::dynamicEntryBodyMaxChars(0),
        );
    }

    public function testResolveEntryBodyMaxCharsHonoursMetaBelowAbsoluteCap(): void
    {
        $entries = [[
            'entry_type' => 'feed_item',
            'entry_id'   => 1,
            'title'      => 'T',
            'content'    => str_repeat('y', 9000),
        ]];

        $xml = MarkdownResearcherFormatter::format(
            $entries,
            [],
            ['entry_body_max_chars' => 8000],
            true,
            MarkdownResearcherFormatter::FORMAT_XML,
        );

        self::assertStringNotContainsString(str_repeat('y', 8500), $xml);
        self::assertStringContainsString(str_repeat('y', 7900), $xml);
    }
}
