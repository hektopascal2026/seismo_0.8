<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Service\ResearcherPromptHelperService;

final class ResearcherPromptHelperServiceTest extends TestCase
{
    public function testStripWrappingFencesRemovesMarkdownCodeBlock(): void
    {
        $raw = "```markdown\nLine one\nLine two\n```";
        self::assertSame("Line one\nLine two", ResearcherPromptHelperService::stripWrappingFences($raw));
    }

    public function testStripWrappingFencesLeavesPlainTextUntouched(): void
    {
        $raw = "PHASE 1 — AUSWAHL\n- Regeln";
        self::assertSame($raw, ResearcherPromptHelperService::stripWrappingFences($raw));
    }
}
