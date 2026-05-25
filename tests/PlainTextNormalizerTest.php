<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Lex\LexPlainText;
use Seismo\Core\PlainTextNormalizer;

final class PlainTextNormalizerTest extends TestCase
{
    public function testCollapsesWhitespaceOnlyLinesAndMultipleBlankLines(): void
    {
        $raw = "Having regard to the Treaty\n"
            . "   \n"
            . "\n"
            . " \n"
            . "\n\n"
            . "Having regard to Regulation (EU) 2024/1263\n"
            . "   \n"
            . "Whereas:\n";

        $normalized = PlainTextNormalizer::forIngest($raw);

        self::assertStringNotContainsString("\n\n\n", $normalized);
        self::assertSame(
            "Having regard to the Treaty\n\n"
            . "Having regard to Regulation (EU) 2024/1263\n\n"
            . "Whereas:",
            $normalized
        );
    }

    public function testLexPlainTextNormalizeDelegatesToIngestHelper(): void
    {
        self::assertSame(
            PlainTextNormalizer::forIngest("Line one\n\n\nLine two"),
            LexPlainText::normalize("Line one\n\n\nLine two")
        );
    }
}
