<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Core\MagnituScoreBands;

final class MagnituScoreBandsTest extends TestCase
{
    public function testHighlightsTierUsesAlertThreshold(): void
    {
        $this->assertTrue(MagnituScoreBands::passesHighlightsTier(0.60, 0.60));
        $this->assertFalse(MagnituScoreBands::passesHighlightsTier(0.59, 0.60));
    }

    public function testImportantBelowThresholdBand(): void
    {
        $this->assertTrue(MagnituScoreBands::passesImportantBelowThreshold(0.55, 0.60));
        $this->assertFalse(MagnituScoreBands::passesImportantBelowThreshold(0.60, 0.60));
        $this->assertFalse(MagnituScoreBands::passesImportantBelowThreshold(0.50, 0.60));
    }

    public function testResearcherPoolWithoutExtension(): void
    {
        $this->assertTrue(MagnituScoreBands::passesResearcherPool(0.80, 0.60, false));
        $this->assertFalse(MagnituScoreBands::passesResearcherPool(0.55, 0.60, false));
    }

    public function testResearcherPoolWithImportantExtension(): void
    {
        $this->assertTrue(MagnituScoreBands::passesResearcherPool(0.55, 0.60, true));
        $this->assertFalse(MagnituScoreBands::passesResearcherPool(0.40, 0.60, true));
    }

    public function testBadgeCssClassBands(): void
    {
        $this->assertSame('magnitu-badge-investigation', MagnituScoreBands::badgeCssClass(0.80));
        $this->assertSame('magnitu-badge-important', MagnituScoreBands::badgeCssClass(0.60));
        $this->assertSame('magnitu-badge-background', MagnituScoreBands::badgeCssClass(0.40));
    }
}
