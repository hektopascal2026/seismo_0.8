<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Lex\LexEurLexContentFetcher;

final class LexEurLexContentFetcherTest extends TestCase
{
    public function testParseEurLexUrlExtractsCelexAndLanguage(): void
    {
        $parsed = LexEurLexContentFetcher::parseEurLexUrl(
            'https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:52026DC0262',
        );

        self::assertNotNull($parsed);
        self::assertSame('52026DC0262', $parsed['celex']);
        self::assertSame('EN', $parsed['path_lang']);
    }

    public function testParseEurLexUrlReturnsNullForNonEurLexHost(): void
    {
        self::assertNull(LexEurLexContentFetcher::parseEurLexUrl('https://example.com/doc'));
    }

    public function testIsAwsWafChallengePageDetectsBotWall(): void
    {
        $html = <<<'HTML'
        <html><body>
        <script>window.awsWafCookieDomainList = [];</script>
        <script>AwsWafIntegration.getToken();</script>
        <noscript>verify that you're not a robot</noscript>
        </body></html>
        HTML;

        self::assertTrue(LexEurLexContentFetcher::isAwsWafChallengePage($html));
    }

    public function testPlainTextFromHtmlRejectsWafChallenge(): void
    {
        $fetcher = new LexEurLexContentFetcher();
        $html = <<<'HTML'
        <!DOCTYPE html><html><body>
        <script>window.awsWafCookieDomainList = [];</script>
        <noscript>JavaScript is disabled. verify that you're not a robot.</noscript>
        </body></html>
        HTML;

        self::assertNull($fetcher->plainTextFromHtml($html));
    }

    public function testPlainTextFromHtmlExtractsCellarContentWrapper(): void
    {
        $fetcher = new LexEurLexContentFetcher();
        $html = <<<'HTML'
        <!DOCTYPE html>
        <html><body>
        <nav>Skip me</nav>
        <div class="contentWrapper">
          <div class="content"><p>EUROPEAN COMMISSION</p><p>COM(2026) 262 final</p></div>
        </div>
        </body></html>
        HTML;

        $plain = $fetcher->plainTextFromHtml($html);
        self::assertNotNull($plain);
        self::assertStringContainsString('EUROPEAN COMMISSION', $plain);
        self::assertStringContainsString('COM(2026) 262 final', $plain);
        self::assertStringNotContainsString('Skip me', $plain);
    }

    public function testStripCoverPageFromPlainTextRemovesCommissionLetterhead(): void
    {
        $plain = LexEurLexContentFetcher::stripCoverPageFromPlainText(
            "EUROPEAN COMMISSION\nBrussels, 22.5.2026\nCOM(2026) 262 final\n\n"
            . "THE COUNCIL OF THE EUROPEAN UNION,\n\n"
            . "Having regard to the Treaty on the Functioning of the European Union,\n\n"
            . "Whereas:\n\n(1)First recital text.",
        );

        self::assertStringStartsWith('Having regard to the Treaty', $plain);
        self::assertStringNotContainsString('EUROPEAN COMMISSION', $plain);
        self::assertStringNotContainsString('COM(2026) 262 final', $plain);
        self::assertStringNotContainsString('THE COUNCIL OF THE EUROPEAN UNION', $plain);
    }

    public function testPlainTextFromHtmlStripsCellarComCoverPage(): void
    {
        $fetcher = new LexEurLexContentFetcher();
        $html = <<<'HTML'
        <!DOCTYPE html>
        <html><body>
        <div class="contentWrapper">
          <div class="content">
            <p class="Normal">EUROPEAN COMMISSION</p>
            <p class="Rfrenceinstitutionnelle">COM(2026) 262 final</p>
            <p class="Institutionquiagit">THE COUNCIL OF THE EUROPEAN UNION,</p>
            <p class="Normal">Having regard to the Treaty on the Functioning of the European Union,</p>
            <p class="Normal">Whereas:</p>
            <li class="li ManualConsidrant">(1)First recital text.</li>
          </div>
        </div>
        </body></html>
        HTML;

        $plain = $fetcher->plainTextFromHtml($html);
        self::assertNotNull($plain);
        self::assertStringStartsWith('Having regard to the Treaty', $plain);
        self::assertStringNotContainsString('EUROPEAN COMMISSION', $plain);
    }
}
