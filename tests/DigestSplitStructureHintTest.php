<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Mail\DigestSplitStructureHint;

final class DigestSplitStructureHintTest extends TestCase
{
    public function testFindsRepeatedStoryWrappers(): void
    {
        $html = <<<'HTML'
<html><body>
<div class="masthead">Newsletter</div>
<div class="story-card"><h2><a href="/a">Alpha</a></h2><p>One</p></div>
<div class="story-card"><h2><a href="/b">Beta</a></h2><p>Two</p></div>
<div class="story-card"><h2><a href="/c">Gamma</a></h2><p>Three</p></div>
<div class="footer">Unsubscribe</div>
</body></html>
HTML;

        $hint = new DigestSplitStructureHint();
        $candidates = $hint->candidatesFromHtml($html);

        self::assertNotEmpty($candidates);
        self::assertSame('div.story-card', $candidates[0]['selector']);
        self::assertSame(3, $candidates[0]['count']);
    }

    public function testDetectsTypo3InSamples(): void
    {
        $html = file_get_contents(__DIR__ . '/fixtures/zhk_digest_sample.html');
        self::assertNotFalse($html);

        $hint = new DigestSplitStructureHint();
        self::assertTrue($hint->detectTypo3InSamples([['subject' => 'ZHK', 'html_body' => $html]]));
    }

    public function testFormatForPromptIncludesTypo3Template(): void
    {
        $html = file_get_contents(__DIR__ . '/fixtures/zhk_digest_sample.html');
        self::assertNotFalse($html);

        $hint = new DigestSplitStructureHint();
        $block = $hint->formatForPrompt([
            ['subject' => 'Test', 'html_body' => $html],
        ]);

        self::assertStringContainsString('div.csc-frame-default', $block);
        self::assertStringContainsString('TYPO3/punkt4', $block);
    }

    public function testSamplesHaveHtml(): void
    {
        $hint = new DigestSplitStructureHint();
        self::assertTrue($hint->samplesHaveHtml([['subject' => 'x', 'html_body' => '<p>a</p>']]));
        self::assertFalse($hint->samplesHaveHtml([['subject' => 'x', 'text_body' => 'plain']]));
    }
}
