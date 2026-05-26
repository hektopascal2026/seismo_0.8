<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Formatter\MarkdownBriefingFormatter;

final class MarkdownBriefingFormatterBodyBudgetTest extends TestCase
{
    public function testDynamicEntryBodyMaxCharsScalesDownWithLargePool(): void
    {
        self::assertSame(5000, MarkdownBriefingFormatter::dynamicEntryBodyMaxChars(100));
    }

    public function testDynamicEntryBodyMaxCharsScalesUpWithSmallPool(): void
    {
        self::assertSame(12000, MarkdownBriefingFormatter::dynamicEntryBodyMaxChars(8));
    }

    public function testDynamicEntryBodyMaxCharsUsesDefaultForEmptyPool(): void
    {
        self::assertSame(
            MarkdownBriefingFormatter::ENTRY_BODY_DEFAULT_CHARS,
            MarkdownBriefingFormatter::dynamicEntryBodyMaxChars(0),
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

        $xml = MarkdownBriefingFormatter::format(
            $entries,
            [],
            ['entry_body_max_chars' => 8000],
            true,
            MarkdownBriefingFormatter::FORMAT_XML,
        );

        self::assertStringNotContainsString(str_repeat('y', 8500), $xml);
        self::assertStringContainsString(str_repeat('y', 7900), $xml);
    }
}
