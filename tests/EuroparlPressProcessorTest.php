<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Mail\Processor\EuroparlPressProcessor;

final class EuroparlPressProcessorTest extends TestCase
{
    public function testExtractsHeadlineAndStripsDigestChrome(): void
    {
        $row = [
            'subject'   => 'EP TODAY - Wednesday 20 May',
            'text_body' => "[1] scribo-webmail-logo [22]\n"
                . "Press service\n"
                . "European Parliament\n"
                . "20-05-2026 Plenary session EP TODAY [2]\n"
                . "Wednesday 20 May\n"
                . "Breaking down barriers to the single market*\n"
                . "This session's key debate, on the single market, starts at 15:00.",
        ];

        $out = (new EuroparlPressProcessor())->process($row);

        self::assertSame('Breaking down barriers to the single market*', $out['derived_title']);
        self::assertStringContainsString('key debate', (string)($out['text_body'] ?? ''));
        self::assertStringNotContainsString('scribo-webmail', (string)($out['text_body'] ?? ''));
        self::assertStringNotContainsString('Press service', (string)($out['text_body'] ?? ''));
    }

    public function testLeavesUnrelatedSubjectsUntouched(): void
    {
        $row = [
            'subject'   => 'Council adopts new rules',
            'text_body' => "Short update on energy policy.\nMore detail in annex.",
        ];

        $out = (new EuroparlPressProcessor())->process($row);

        self::assertArrayNotHasKey('derived_title', $out);
        self::assertStringContainsString('energy policy', (string)($out['text_body'] ?? ''));
    }
}
