<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Mail\EmailAlternateLocalePolicy;
use Seismo\Core\Mail\EmailLocaleGuesser;
use Seismo\Core\Mail\EmailWebViewBodyHydrator;
use Seismo\Core\Mail\EmailWebViewPhraseLexicon;
use Seismo\Core\Mail\EmailWebViewUrlExtractor;

final class EmailLocalePolicyTest extends TestCase
{
    public function testGermanInboxPrefersGermanAlternateLink(): void
    {
        $plain = "Medienmitteilung des Bundesrats\n"
            . "Der Bundesrat hat heute beschlossen.\n"
            . "Newsletter auf Deutsch ( https://news.example.net/de )\n"
            . "Read the English version ( https://news.example.net/en )";

        $profile = EmailLocaleGuesser::profileForEmail('Medienmitteilung', $plain);
        self::assertSame(EmailLocaleGuesser::PROFILE_GERMAN, $profile);

        $ranks = EmailAlternateLocalePolicy::preferredLocaleRanks($profile);
        self::assertSame(
            'https://news.example.net/de',
            EmailWebViewUrlExtractor::resolve('', $plain, $ranks)->url
        );
    }

    public function testNonEnglishInboxPrefersGermanThenEnglish(): void
    {
        $plain = "Спільна заява\n"
            . "Read the English version ( https://news.example.net/en )\n"
            . "Newsletter auf Deutsch ( https://news.example.net/de )";

        $profile = EmailLocaleGuesser::profileForEmail('Newsletter', $plain);
        self::assertSame(EmailLocaleGuesser::PROFILE_OTHER, $profile);

        $ranks = EmailAlternateLocalePolicy::preferredLocaleRanks($profile);
        self::assertSame(
            'https://news.example.net/de',
            EmailWebViewUrlExtractor::resolve('', $plain, $ranks)->url
        );
    }

    public function testEnglishAlternateMarksHydration(): void
    {
        $resolution = EmailWebViewUrlExtractor::resolve(
            '',
            'Read the English version ( https://news.example.net/en )',
            EmailAlternateLocalePolicy::preferredLocaleRanks(EmailLocaleGuesser::PROFILE_OTHER)
        );

        self::assertTrue($resolution->hydrateBody);
        self::assertSame(EmailWebViewPhraseLexicon::RANK_LOCALE_ENGLISH, $resolution->localeRank);
    }

    public function testGenericWebViewDoesNotHydrate(): void
    {
        $resolution = EmailWebViewUrlExtractor::resolve(
            '<a href="https://news.example.net/view">View in browser</a>',
            '',
            EmailAlternateLocalePolicy::preferredLocaleRanks(EmailLocaleGuesser::PROFILE_OTHER)
        );

        self::assertFalse($resolution->hydrateBody);
        self::assertNull($resolution->localeRank);
    }

    public function testParsesBrevoStyleRedirectPage(): void
    {
        $html = <<<'HTML'
        <html><head>
        <meta http-equiv="refresh" content="0.0;https://rm.coe.int/newsletter-22-05/48802be22f">
        </head><body>
        <script>top.location='https:\/\/rm.coe.int\/newsletter-22-05\/48802be22f'</script>
        </body></html>
        HTML;

        self::assertSame(
            'https://rm.coe.int/newsletter-22-05/48802be22f',
            EmailWebViewBodyHydrator::parseHtmlRedirectTarget($html)
        );
    }
}
