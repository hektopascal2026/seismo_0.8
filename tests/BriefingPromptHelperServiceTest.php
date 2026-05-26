<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Service\BriefingPromptHelperService;

final class BriefingPromptHelperServiceTest extends TestCase
{
    public function testStripWrappingFencesRemovesMarkdownCodeBlock(): void
    {
        $raw = "```markdown\nLine one\nLine two\n```";
        self::assertSame("Line one\nLine two", BriefingPromptHelperService::stripWrappingFences($raw));
    }

    public function testStripWrappingFencesLeavesPlainTextUntouched(): void
    {
        $raw = "PHASE 1 — AUSWAHL\n- Regeln";
        self::assertSame($raw, BriefingPromptHelperService::stripWrappingFences($raw));
    }
}
