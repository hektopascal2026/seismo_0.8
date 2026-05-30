<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Mail\EmailIngestNormalizer;

final class EmailIngestNormalizerTest extends TestCase
{
    public function testNormalizesPurePlainTextLegitimately(): void
    {
        $row = [
            'html_body' => '',
            'text_body' => "This is legitimate plain text.\nWe compare a < b and x > y here.",
        ];

        $normalized = EmailIngestNormalizer::normalizeBodies($row);
        self::assertSame('', $normalized['html_body'] ?? '');
        self::assertStringContainsString('a < b', $normalized['text_body']);
    }

    public function testPromotesPlainHtmlToHtmlBodyAndCleansIt(): void
    {
        $row = [
            'html_body' => '',
            'text_body' => "<div id=\"pl-newsletter-header\"><p>Real content</p></div>",
        ];

        $normalized = EmailIngestNormalizer::normalizeBodies($row);
        self::assertStringContainsString('Real content', $normalized['text_body']);
        self::assertStringNotContainsString('<div', $normalized['text_body']);
        self::assertStringContainsString('<div', $normalized['html_body']);
    }

    public function testPromotesEscapedPlainHtmlToHtmlBodyAndCleansIt(): void
    {
        $row = [
            'html_body' => '',
            'text_body' => "&lt;div id=&quot;pl-newsletter-header&quot;&gt;&lt;p&gt;Real content&lt;/p&gt;&lt;/div&gt;",
        ];

        $normalized = EmailIngestNormalizer::normalizeBodies($row);
        self::assertStringContainsString('Real content', $normalized['text_body']);
        self::assertStringNotContainsString('&lt;div', $normalized['text_body']);
        self::assertStringContainsString('&lt;div', $normalized['html_body']);
    }
}
