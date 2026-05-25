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
}
