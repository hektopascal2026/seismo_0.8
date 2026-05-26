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
}
