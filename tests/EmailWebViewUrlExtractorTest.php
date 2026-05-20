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
