<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Mail\DigestSplitSelectorProber;

final class DigestSplitSelectorProberTest extends TestCase
{
    public function testProbesTypo3DigestTemplate(): void
    {
        $html = file_get_contents(__DIR__ . '/fixtures/zhk_digest_sample.html');
        self::assertNotFalse($html);

        $probe = (new DigestSplitSelectorProber())->probeBest($html);

        self::assertNotNull($probe);
        self::assertSame('typo3_punkt4_combined', $probe['label']);
        self::assertGreaterThanOrEqual(3, $probe['score']);
        self::assertStringContainsString('div.csc-frame-default', $probe['split_rules']['story_selector']);
    }

    public function testProbesMjmlDigestTemplate(): void
    {
        $html = file_get_contents(__DIR__ . '/fixtures/mjml_digest_sample.html');
        self::assertNotFalse($html);

        $probe = (new DigestSplitSelectorProber())->probeBest($html);

        self::assertNotNull($probe);
        self::assertSame('mjml_column_table', $probe['label']);
        self::assertSame('div.mj-column-per-100 table', $probe['split_rules']['story_selector']);
        self::assertGreaterThanOrEqual(2, $probe['score']);
    }
}
