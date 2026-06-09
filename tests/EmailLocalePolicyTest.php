<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Mail\EmailAlternateLocalePolicy;
use Seismo\Core\Mail\EmailInboxTruncationDetector;
use Seismo\Core\Mail\EmailLocaleGuesser;
use Seismo\Core\Mail\EmailMetadata;
use Seismo\Core\Mail\EmailWebViewBodyHydrator;
use Seismo\Core\Mail\EmailWebViewPhraseLexicon;
use Seismo\Core\Mail\EmailWebViewResolution;
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

        self::assertFalse($resolution->hydrateBody);
        self::assertSame(EmailWebViewPhraseLexicon::RANK_LOCALE_ENGLISH, $resolution->localeRank);

        $warnings = [];
        $resolutionHydrate = EmailWebViewUrlExtractor::resolve(
            '',
            'Read the English version ( https://news.example.net/en )',
            EmailAlternateLocalePolicy::preferredLocaleRanks(EmailLocaleGuesser::PROFILE_OTHER),
            [],
            $warnings,
            true
        );

        self::assertTrue($resolutionHydrate->hydrateBody);
    }

    public function testNeedsHostedHydrationRetryForCyrillicInboxWithEnglishLink(): void
    {
        $plain = "Read the English version ( https://news.example.net/en )\n\nСпільна заява";
        $resolution = new \Seismo\Core\Mail\EmailWebViewResolution(
            'https://news.example.net/en',
            \Seismo\Core\Mail\EmailWebViewPhraseLexicon::RANK_LOCALE_ENGLISH,
            true
        );

        self::assertTrue(
            \Seismo\Core\Mail\EmailAlternateLocalePolicy::needsHostedHydrationRetry([], $resolution, $plain)
        );
        self::assertFalse(
            \Seismo\Core\Mail\EmailAlternateLocalePolicy::needsHostedHydrationRetry(
                ['metadata' => json_encode(['body_source' => 'web_view'])],
                $resolution,
                $plain
            )
        );
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

    public function testTruncatedInboxRequestsGenericWebViewHydration(): void
    {
        $url = 'https://press.example.de/newsletter?view=renderNewsletterHtml';
        $plain = 'Sollte der Newsletter nicht korrekt angezeigt werden, klicken Sie bitte hier '
            . '(' . $url . ")\n\nTeaser text.\n\nRead on web not shown with this email.";

        self::assertTrue(EmailInboxTruncationDetector::looksTruncated($plain));

        $resolution = new EmailWebViewResolution($url, null, false);
        self::assertTrue(
            EmailAlternateLocalePolicy::needsTruncatedWebViewHydration([], $resolution, $plain)
        );
        self::assertFalse(
            EmailAlternateLocalePolicy::needsTruncatedWebViewHydration(
                ['metadata' => json_encode([EmailMetadata::KEY_BODY_SOURCE => EmailMetadata::BODY_SOURCE_WEB_VIEW])],
                $resolution,
                $plain
            )
        );
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
