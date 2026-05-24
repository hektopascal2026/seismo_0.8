<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Fetcher\ScraperContentExtractor;

final class ScraperContentExtractorTest extends TestCase
{
    public function testExtractPublishedDateFromCompoundCssSelector(): void
    {
        $html = <<<'HTML'
        <!DOCTYPE html>
        <html><body>
        <nav><time datetime="2019-01-01">old</time></nav>
        <article><time datetime="2024-06-15T10:00:00Z">published</time></article>
        </body></html>
        HTML;

        $date = ScraperContentExtractor::extractPublishedDate($html, 'article time[datetime]');
        self::assertSame('2024-06-15 10:00:00', $date);
    }

    public function testExtractPublishedDateFromLegacyClassSelector(): void
    {
        $html = <<<'HTML'
        <!DOCTYPE html>
        <html><body><p class="date">15.03.2024</p></body></html>
        HTML;

        $date = ScraperContentExtractor::extractPublishedDate($html, '.date');
        self::assertNotNull($date);
        self::assertMatchesRegularExpression('/^2024-03-15/', (string)$date);
    }

    public function testExtractReadableContentPrefersArticleOverNavNoise(): void
    {
        $html = <<<'HTML'
        <!DOCTYPE html>
        <html><head><title>Page title</title></head><body>
        <nav><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam.</p></nav>
        <article>
        <h1>Real headline</h1>
        <p>This is the substantive press release body with enough detail to pass readability thresholds and represent the actual article content users care about on the timeline.</p>
        <p>Second paragraph adds context for monitoring and export consumers without filler navigation text.</p>
        </article>
        </body></html>
        HTML;

        $out = ScraperContentExtractor::extractReadableContent($html);
        self::assertStringContainsString('substantive press release', $out['content']);
        self::assertStringNotContainsString('eiusmod tempor', $out['content']);
    }

    public function testExcludeSelectorsStripBeforeExtraction(): void
    {
        $html = <<<'HTML'
        <!DOCTYPE html>
        <html><body>
        <div class="chrome">Chrome noise that should never appear in extracted article text for scraper tests.</div>
        <article><p>Kept body text for the scraper extraction pipeline validation case.</p></article>
        </body></html>
        HTML;

        $out = ScraperContentExtractor::extractReadableContent($html, ".chrome\n# comment");
        self::assertStringContainsString('Kept body text', $out['content']);
        self::assertStringNotContainsString('Chrome noise', $out['content']);
    }
}
