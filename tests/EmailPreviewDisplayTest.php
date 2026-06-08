<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;

final class EmailPreviewDisplayTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/views/helpers.php';
    }

    public function testTrimEmailPreviewStripsTrailingWebViewBoilerplate(): void
    {
        $preview = 'Bald stimmen wir wieder über die Heiratsstrafe ab ... View in browser';
        self::assertSame(
            'Bald stimmen wir wieder über die Heiratsstrafe ab',
            seismo_trim_email_preview_for_webview_link($preview),
        );
    }

    public function testPlainEmailBodyStripsHtmlMarkup(): void
    {
        $email = [
            'text_body' => '<div style="display:flex"><span>Teaser</span><span>noise</span></div>',
        ];
        self::assertSame('Teasernoise', seismo_email_plain_body_for_display($email));
    }

    public function testLinkifyAndFormatParagraphs(): void
    {
        $input = "Hello world!\n\nCheck out https://github.com/oliverfuchs/seismo for details.\nAnother line.";
        $expected = "<p class=\"timeline-entry-paragraph\">Hello world!</p>\n" .
            "<p class=\"timeline-entry-paragraph\">Check out <a href=\"https://github.com/oliverfuchs/seismo\" target=\"_blank\" rel=\"noopener\" class=\"timeline-inline-link\">github.com/oliverfuchs/se…</a> for details.<br />\nAnother line.</p>";
        self::assertSame($expected, seismo_linkify_and_format_paragraphs($input));
    }
}

