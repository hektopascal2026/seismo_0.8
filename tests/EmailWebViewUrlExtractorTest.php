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

        $expected = 'https://europarl.europa.eu/news/en/press-room/20260513IPR43308/'
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

        $expected = 'https://admin.ch/de/newnsb/TDa4boIj7yF-wQiV_ccp4';
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
            'https://baden-wuerttemberg.de/de/newsletter/taeglich/example',
            EmailWebViewUrlExtractor::fromHtml($html)
        );

        $plain = 'Falls Sie Ihre E-Mail nicht oder nur teilweise sehen, klicken Sie hier '
            . '[https://www.baden-wuerttemberg.de/de/newsletter/taeglich/example]' . "\n\nNewsletter body.";

        self::assertSame(
            'https://baden-wuerttemberg.de/de/newsletter/taeglich/example',
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

    public function testPlainAlternateLocaleFindsUrlAfterNormalization(): void
    {
        $url = 'https://news.example.net/en-edition';
        $plain = "Read the English version of our Newsletter here (click)\n"
            . "  ( {$url} )\n\n"
            . "Body with extra   spaces and Problème text.";

        self::assertSame($url, EmailWebViewUrlExtractor::fromPlainText($plain));
    }

    public function testVorarlbergAngleBracketWebViewLink(): void
    {
        $url = 'https://presse.vorarlberg.at/land/dist/vlk-69098.html';
        $plain = "Zur vollständigen Meldung<{$url}>\n\nLand Vorarlberg — Kurzfassung.";

        self::assertTrue(EmailWebViewPhraseLexicon::textLooksLikeWebView('Zur vollständigen Meldung'));
        self::assertSame($url, EmailWebViewUrlExtractor::fromPlainText($plain));
    }

    public function testBmwkSchlaglichterWebViewFromHtmlFixture(): void
    {
        $html = file_get_contents(__DIR__ . '/fixtures/mail/bmwk_schlaglichter_webview.html');
        self::assertIsString($html);

        $expected = 'https://bundeswirtschaftsministerium.de/Redaktion/DE/Publikationen/'
            . 'Schlaglichter-der-Wirtschaftspolitik/2026/schlaglichter-der-wirtschaftspolitik-2026-06.html'
            . '?view=renderNewsletterHtml';
        self::assertSame($expected, EmailWebViewUrlExtractor::fromHtml($html));
    }

    public function testBmwkSchlaglichterWebViewPhraseAndUrl(): void
    {
        $url = 'https://bundeswirtschaftsministerium.de/Redaktion/DE/Publikationen/'
            . 'Schlaglichter-der-Wirtschaftspolitik/2026/schlaglichter-der-wirtschaftspolitik-2026-06.html'
            . '?view=renderNewsletterHtml';
        $plain = 'Sollte der Newsletter nicht korrekt angezeigt werden, klicken Sie bitte hier '
            . '(' . $url . ")\n\n26.05.2026 PUBLIKATION\n\n"
            . "Read on web not shown with this email.";

        self::assertTrue(EmailWebViewPhraseLexicon::textLooksLikeWebView(
            'Sollte der Newsletter nicht korrekt angezeigt werden, klicken Sie bitte hier'
        ));
        self::assertSame($url, EmailWebViewUrlExtractor::fromPlainText($plain));
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

    public function testAustrianParliamentHeadlineLinkIsWebViewUrl(): void
    {
        $expected = 'https://parlament.gv.at/aktuelles/pk/jahr_2026/pk0488';
        $plain = "Parlamentskorrespondenz Nr. 488 vom 28.5.2026\n"
            . "Sportausschuss: Kontrollen und Prävention im Mittelpunkt der NADA-Arbeit\n"
            . "Sportausschuss: Kontrollen und Prävention im Mittelpunkt der NADA-Arbeit ({$expected})\n"
            . "Nationale Anti-Doping-Agentur führte 2025 über 3.000 Dopingkontrollen durch";

        self::assertSame($expected, EmailWebViewUrlExtractor::fromPlainText($plain));
    }

    public function testSwissParliamentHeadlineLinkIsWebViewUrl(): void
    {
        $expected = 'https://parlament.ch/de/services/news/Seiten/2026/20260601043044096194158159026_bsd009.aspx';
        $plain = "Neue Treffer für Trefferliste News\n"
            . "Die Bundesversammlung — Das Schweizer Parlament\n"
            . "Räte streiten über freien Handel, neue AKWs und teure Rüstungsgüter ({$expected})\n"
            . "Montag, 1. Juni 2026";

        self::assertSame($expected, EmailWebViewUrlExtractor::fromPlainText($plain));
    }

    public function testMailchimpWebViewRedirectAllowed(): void
    {
        $html = '<a href="https://mailchi.mp/efta/discover-the-new-efta-free-trade-dashboard">Discover the new EFTA Free Trade Dashboard</a>';
        $plain = "Discover the new EFTA Free Trade Dashboard ( https://mailchi.mp/efta/discover-the-new-efta-free-trade-dashboard )";

        // When resolution preferred locales are loaded, or resolve is run:
        $resolution = EmailWebViewUrlExtractor::resolve($html, $plain, \Seismo\Core\Mail\EmailAlternateLocalePolicy::englishFirstRanks(), ['efta']);
        self::assertSame('https://mailchi.mp/efta/discover-the-new-efta-free-trade-dashboard', $resolution->url);
    }

    public function testFiltersOutMailtoRedirectUrls(): void
    {
        $html = '<p>Send ideas <a href="https://dmp.politico.eu/?email=seismofetcher@gmail.com&amp;destination=mailto:jdetsch@politico.com" target="_blank">here</a> | '
            . '<a href="https://dmp.politico.eu/?email=seismofetcher@gmail.com&amp;destination=https://x.com/JackDetsch" target="_blank">@JackDetsch</a> | '
            . '<a href="https://dmp.politico.eu/?email=seismofetcher@gmail.com&amp;destination=https://www.politico.eu/newsletter/global-security/asian-allies-tiptoe-around-china-as-us-influence-wanes/">View in your browser</a></p>';
        
        $plain = "Send ideas here ( https://dmp.politico.eu/?email=seismofetcher@gmail.com&amp;destination=mailto:jdetsch@politico.com ) | "
            . "@JackDetsch ( https://dmp.politico.eu/?email=seismofetcher@gmail.com&amp;destination=https://x.com/JackDetsch ) | "
            . "View in your browser ( https://dmp.politico.eu/?email=seismofetcher@gmail.com&amp;destination=https://www.politico.eu/newsletter/global-security/asian-allies-tiptoe-around-china-as-us-influence-wanes/ )";

        $url = EmailWebViewUrlExtractor::fromHtml($html);
        self::assertSame('https://dmp.politico.eu/?destination=https%3A%2F%2Fwww.politico.eu%2Fnewsletter%2Fglobal-security%2Fasian-allies-tiptoe-around-china-as-us-influence-wanes%2F&email=seismofetcher%40gmail.com', $url);

        $urlPlain = EmailWebViewUrlExtractor::fromPlainText($plain);
        self::assertSame('https://dmp.politico.eu/?destination=https%3A%2F%2Fwww.politico.eu%2Fnewsletter%2Fglobal-security%2Fasian-allies-tiptoe-around-china-as-us-influence-wanes%2F&email=seismofetcher%40gmail.com', $urlPlain);
    }

    public function testViewEmailInYourBrowserIsRecognized(): void
    {
        $html = '<p><a href="https://us12.campaign-archive.com/?e=8c86d464a8&u=7404e6dcdc8018f49c82e941d&id=021536b4d9">View email in your browser</a></p>';
        $plain = "View email in your browser ( https://us12.campaign-archive.com/?e=8c86d464a8&u=7404e6dcdc8018f49c82e941d&id=021536b4d9 )";
        
        $url = EmailWebViewUrlExtractor::fromHtml($html);
        self::assertSame('https://us12.campaign-archive.com/?e=8c86d464a8&u=7404e6dcdc8018f49c82e941d&id=021536b4d9', $url);
        
        $urlPlain = EmailWebViewUrlExtractor::fromPlainText($plain);
        self::assertSame('https://us12.campaign-archive.com/?e=8c86d464a8&u=7404e6dcdc8018f49c82e941d&id=021536b4d9', $urlPlain);
    }
}
