<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Core\Mail\EmailHtmlSanitizer;

final class EmailHtmlSanitizerTest extends TestCase
{
    public function testLargeHtmlUsesStripTagsFallback(): void
    {
        $html = '<p>' . str_repeat('x', 160_000) . '</p>';
        $out  = EmailHtmlSanitizer::sanitize($html);

        self::assertStringNotContainsString('<p>', $out);
        self::assertStringContainsString('xxx', $out);
    }

    public function testStripsStyleScriptAndHeadWithContents(): void
    {
        $html = '<html><head><title>Test</title><style>body { color: red; }</style></head><body><h1>Hello</h1><script>console.log("hello");</script><style>p { margin: 0; }</style><p>World</p></body></html>';
        $out  = EmailHtmlSanitizer::sanitize($html);

        self::assertStringNotContainsString('color: red', $out);
        self::assertStringNotContainsString('console.log', $out);
        self::assertStringNotContainsString('margin: 0', $out);
        self::assertStringContainsString('Hello', $out);
        self::assertStringContainsString('World', $out);
    }

    public function testStripsStyleScriptAndHeadWithContentsLargeFallback(): void
    {
        $largeString = str_repeat('x', 160_000);
        $html = '<html><head><style>body { color: red; }</style></head><body>' . $largeString . '<script>console.log("hello");</script><style>p { margin: 0; }</style></body></html>';
        $out  = EmailHtmlSanitizer::sanitize($html);

        self::assertStringNotContainsString('color: red', $out);
        self::assertStringNotContainsString('console.log', $out);
        self::assertStringNotContainsString('margin: 0', $out);
        self::assertStringContainsString('xxx', $out);
    }
}
