<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Mail\EmailMetadata;
use Seismo\Core\Mail\EmailWebViewPhraseLexicon;
use Seismo\Core\Mail\EmailWebViewUrlExtractor;

final class EmailWebViewUrlExtractorTest extends TestCase
{
    public function testExtractsWebViewLinkFromHtmlFixture(): void
    {
        $html = file_get_contents(__DIR__ . '/fixtures/mail/webview_link.html');
        self::assertIsString($html);

        $url = EmailWebViewUrlExtractor::fromHtml($html);
        self::assertSame('https://news.example.org/archive/2026/digest-42', $url);
    }

    public function testExtractsFromPlainTextLine(): void
    {
        $plain = "E-Mail im Browser ansehen: https://press.example.eu/mail/abc123\n\nHeadline text";

        self::assertSame(
            'https://press.example.eu/mail/abc123',
            EmailWebViewUrlExtractor::fromPlainText($plain)
        );
    }

    public function testMetadataMergeRoundTrip(): void
    {
        $row = EmailMetadata::mergeWebViewUrl([], 'https://ep.europa.eu/mail/view/1');

        self::assertSame(
            'https://ep.europa.eu/mail/view/1',
            EmailMetadata::webViewUrlFromMetadata($row['metadata'] ?? null)
        );
    }

    public function testEuroparlPressHeadlineLinkIsWebViewUrl(): void
    {
        $html = file_get_contents(__DIR__ . '/fixtures/mail/europarl_press_headline_link.html');
        self::assertIsString($html);

        $expected = 'https://www.europarl.europa.eu/news/en/press-room/20260513IPR43308/'
            . 'slovakia-meps-demand-action-to-protect-eu-values-and-the-eu-budget';
        self::assertSame($expected, EmailWebViewUrlExtractor::fromHtml($html));

        $plain = "Press service European Parliament\nPress release 20-05-2026\n"
            . "Slovakia: MEPs demand action to protect EU values and the EU budget\n"
            . $expected . "\n"
            . 'In a resolution adopted on Wednesday.';

        self::assertSame($expected, EmailWebViewUrlExtractor::fromPlainText($plain));
    }

    public function testNewsServiceBundHeadlineLinkIsWebViewUrl(): void
    {
        $html = file_get_contents(__DIR__ . '/fixtures/mail/news_service_bund_headline_link.html');
        self::assertIsString($html);

        $expected = 'https://www.admin.ch/de/newnsb/TDa4boIj7yF-wQiV_ccp4';
        self::assertSame($expected, EmailWebViewUrlExtractor::fromHtml($html));

        $plain = "News Service Bund www.news.admin.ch\n"
            . "Medienmitteilung | 20.05.2026\n"
            . "Handelsabkommen: Bundesrat will Landwirtschaft gezielt unterstützen\n"
            . $expected . "\n"
            . 'Bern, 20.05.2026 - summary text.';

        self::assertSame($expected, EmailWebViewUrlExtractor::fromPlainText($plain));
    }

    public function testBadenWuerttembergGermanHierLinkUsesParentContext(): void
    {
        $html = file_get_contents(__DIR__ . '/fixtures/mail/baden_wuerttemberg_webview.html');
        self::assertIsString($html);

        self::assertSame(
            'https://www.baden-wuerttemberg.de/de/newsletter/taeglich/example',
            EmailWebViewUrlExtractor::fromHtml($html)
        );

        $plain = 'Falls Sie Ihre E-Mail nicht oder nur teilweise sehen, klicken Sie hier '
            . '[https://www.baden-wuerttemberg.de/de/newsletter/taeglich/example]' . "\n\nNewsletter body.";

        self::assertSame(
            'https://www.baden-wuerttemberg.de/de/newsletter/taeglich/example',
            EmailWebViewUrlExtractor::fromPlainText($plain)
        );
    }

    public function testLexiconCoversBoilerplateAlignedGermanPhrases(): void
    {
        self::assertTrue(EmailWebViewPhraseLexicon::textLooksLikeWebView(
            'Probleme mit der Anzeige dieser E-Mail? Klicken Sie hier.'
        ));
        self::assertTrue(EmailWebViewPhraseLexicon::textLooksLikeWebView(
            'Si vous ne voyez pas cet e-mail, cliquez ici pour la version en ligne.'
        ));
        self::assertTrue(EmailWebViewPhraseLexicon::textLooksLikeWebView(
            "If you can't read this e-mail in your inbox, view it in your browser."
        ));
        self::assertGreaterThan(120, count(EmailWebViewPhraseLexicon::allPhrases()));
    }

    public function testFrenchAccentNormalization(): void
    {
        self::assertTrue(EmailWebViewPhraseLexicon::textLooksLikeWebView(
            'Problème d\'affichage — voir la version en ligne'
        ));
    }

    public function testPrefersEnglishVersionLinkOverDefaultWebView(): void
    {
        $html = file_get_contents(__DIR__ . '/fixtures/mail/coe_ukraine_english_version.html');
        self::assertIsString($html);

        $english = 'https://track.example.net/mk/cl/f/sh/english-edition';
        self::assertSame($english, EmailWebViewUrlExtractor::fromHtml($html));

        $plain = "If you cannot read this email, please click here "
            . "( https://track.example.net/mk/mr/sh/default-locale )\n\n"
            . "Read the English version of our Newsletter here (click) "
            . "( {$english} )\n\n"
            . "Спільна заява";

        self::assertSame($english, EmailWebViewUrlExtractor::fromPlainText($plain));
        self::assertTrue(EmailWebViewPhraseLexicon::textLooksLikeAlternateLocaleVersion(
            'Read the English version of our Newsletter here (click)'
        ));
    }

    public function testEnglishFirstRanksPreferEnglishOverGerman(): void
    {
        $html = <<<'HTML'
        <html><body>
        <p>Newsletter auf Deutsch <a href="https://news.example.net/de">hier</a></p>
        <p>Read the English version <a href="https://news.example.net/en">here</a></p>
        </body></html>
        HTML;

        self::assertSame('https://news.example.net/en', EmailWebViewUrlExtractor::fromHtml($html));

        $plain = "Newsletter auf Deutsch ( https://news.example.net/de )\n"
            . "Read the English version ( https://news.example.net/en )";

        self::assertSame('https://news.example.net/en', EmailWebViewUrlExtractor::fromPlainText($plain));
    }

    public function testUsesGermanAlternateWhenOnlyLabelledLocaleEdition(): void
    {
        $plain = "Falls die E-Mail nicht angezeigt wird, im Browser ansehen "
            . "( https://news.example.net/default )\n"
            . "Newsletter auf Deutsch ( https://news.example.net/de-edition )";

        self::assertSame('https://news.example.net/de-edition', EmailWebViewUrlExtractor::fromPlainText($plain));
    }

    public function testPlainWebViewLineSurvivesWhenHtmlSnapshotUsedAfterStrip(): void
    {
        $html = '<a href="https://news.example.org/digest/99">View in browser</a>';
        $plainWithLink = "View in browser https://news.example.org/digest/99\n\nStory body";
        $plainStripped   = "Story body";

        self::assertSame(
            'https://news.example.org/digest/99',
            EmailWebViewUrlExtractor::fromHtml($html)
        );
        self::assertNull(EmailWebViewUrlExtractor::fromPlainText($plainStripped));
        self::assertSame(
            'https://news.example.org/digest/99',
            EmailWebViewUrlExtractor::fromPlainText($plainWithLink)
        );
    }
}
