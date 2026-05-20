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

    public function testStripsSinglePressReleaseChromeBeforeHeadline(): void
    {
        $headline = 'Slovakia: MEPs demand action to protect EU values and the EU budget';
        $row = [
            'subject'   => $headline,
            'text_body' => "[1] scribo-webmail-logo [33]\n"
                . "Press service **\n"
                . "European Parliament **\n"
                . "Available in *\n"
                . "[2] scribo-webmail-es [34]\n"
                . "[3] scribo-webmail-cs [35]\n"
                . "Press release\n"
                . "20-05-2026\n"
                . "Plenary session\n"
                . "LIBE\n"
                . "CONT\n"
                . $headline . "\n"
                . 'In a resolution adopted on Wednesday with 347 votes for, 165 against and 25 abstentions.',
        ];

        $out = (new EuroparlPressProcessor())->process($row);

        self::assertSame($headline, $out['derived_title']);
        $body = (string)($out['text_body'] ?? '');
        self::assertStringStartsWith($headline, $body);
        self::assertStringContainsString('resolution adopted', $body);
        self::assertStringNotContainsString('scribo-webmail', $body);
        self::assertStringNotContainsString('Press service', $body);
        self::assertStringNotContainsString('Available in', $body);
        self::assertStringNotContainsString('LIBE', $body);
        self::assertStringNotContainsString('CONT', $body);
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
