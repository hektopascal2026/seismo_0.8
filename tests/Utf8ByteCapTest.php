<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Util\Utf8ByteCap;

final class Utf8ByteCapTest extends TestCase
{
    public function testTruncateDoesNotSplitMultibyteCharacter(): void
    {
        $umlaut = 'ä';
        $text = str_repeat('a', 10) . $umlaut;
        $maxBytes = strlen($text) - 1;

        $cut = Utf8ByteCap::truncate($text, $maxBytes);

        self::assertTrue(mb_check_encoding($cut, 'UTF-8'));
        self::assertStringEndsWith('a', $cut);
        self::assertStringNotContainsString($umlaut, $cut);
    }

    public function testTruncateAppendsSuffixWhenOverLimit(): void
    {
        $text = str_repeat('ö', 20);
        $maxBytes = 10;

        $cut = Utf8ByteCap::truncate($text, $maxBytes, '…');

        self::assertTrue(mb_check_encoding($cut, 'UTF-8'));
        self::assertStringEndsWith('…', $cut);
        self::assertLessThanOrEqual($maxBytes + strlen('…'), strlen($cut));
    }

    public function testTruncateReturnsUnchangedWhenWithinLimit(): void
    {
        $text = 'Hello, Zürich';

        self::assertSame($text, Utf8ByteCap::truncate($text, 1024));
    }
}
