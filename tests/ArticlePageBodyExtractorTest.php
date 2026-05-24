<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Fetcher\ArticlePageBodyExtractor;

final class ArticlePageBodyExtractorTest extends TestCase
{
    public function testExtractJsonLdArticleBodyFromNewsArticle(): void
    {
        $html = <<<'HTML'
        <!DOCTYPE html>
        <html><head>
        <script type="application/ld+json">
        {
            "@context": "https://schema.org",
            "@type": "NewsArticle",
            "headline": "Example",
            "articleBody": "<p>Structured body with enough plain text to qualify as a real article excerpt for hydration tests.</p>"
        }
        </script>
        </head><body></body></html>
        HTML;

        $body = ArticlePageBodyExtractor::extractJsonLdArticleBody($html);
        self::assertStringContainsString('Structured body', $body);
    }

    public function testExtractJsonLdArticleBodyFromGraph(): void
    {
        $html = <<<'HTML'
        <!DOCTYPE html>
        <html><head>
        <script type="application/ld+json">
        {
            "@context": "https://schema.org",
            "@graph": [
                {"@type": "WebSite", "name": "Example"},
                {
                    "@type": "NewsArticle",
                    "articleBody": "Graph article body with sufficient length to beat short teaser metadata on the page."
                }
            ]
        }
        </script>
        </head><body></body></html>
        HTML;

        $body = ArticlePageBodyExtractor::extractJsonLdArticleBody($html);
        self::assertStringContainsString('Graph article body', $body);
    }

    public function testPickBestCandidatePrefersLongestPlainText(): void
    {
        $short = str_repeat('Short teaser. ', 4);
        $long  = trim(str_repeat('Full structured paragraph. ', 12));

        $best = ArticlePageBodyExtractor::pickBestCandidate([
            ['source' => 'og_description', 'content' => $short],
            ['source' => 'json_ld', 'content' => $long],
            ['source' => 'readability', 'content' => $short . ' extra'],
        ]);

        self::assertSame($long, $best);
    }

    public function testPickBestCandidateUsesSourcePriorityOnTie(): void
    {
        $text = trim(str_repeat('Equal length candidate text. ', 6));

        $best = ArticlePageBodyExtractor::pickBestCandidate([
            ['source' => 'meta_description', 'content' => $text],
            ['source' => 'json_ld', 'content' => $text],
            ['source' => 'readability', 'content' => $text],
        ]);

        self::assertSame($text, $best);
    }

    public function testExtractBestArticleBodyPrefersJsonLdOverReadabilityWhenLonger(): void
    {
        $html = <<<'HTML'
        <!DOCTYPE html>
        <html><head>
        <title>Page</title>
        <meta property="og:description" content="Tiny teaser.">
        <script type="application/ld+json">
        {
            "@type": "NewsArticle",
            "articleBody": "<p>JSON-LD carries the full public SEO body with enough detail for monitoring and scoring without relying on DOM heuristics alone.</p><p>Second paragraph adds more context.</p>"
        }
        </script>
        </head><body>
        <nav><p>Navigation noise that readability might accidentally prefer when article markup is weak on the page.</p></nav>
        <article><p>Readability fallback paragraph only.</p></article>
        </body></html>
        HTML;

        $best = ArticlePageBodyExtractor::extractBestArticleBody($html);
        self::assertStringContainsString('JSON-LD carries the full public SEO body', $best);
    }

    public function testExtractBestArticleBodyFallsBackToReadabilityWhenJsonLdMissing(): void
    {
        $html = <<<'HTML'
        <!DOCTYPE html>
        <html><head><title>Page</title></head><body>
        <article>
        <h1>Headline</h1>
        <p>Readable article paragraph with enough substance to serve as the best available body when structured data is absent from the page.</p>
        </article>
        </body></html>
        HTML;

        $best = ArticlePageBodyExtractor::extractBestArticleBody($html);
        self::assertStringContainsString('Readable article paragraph', $best);
    }

    public function testPickBestCandidateIgnoresTooShortCandidates(): void
    {
        $best = ArticlePageBodyExtractor::pickBestCandidate([
            ['source' => 'json_ld', 'content' => 'Too short'],
            ['source' => 'og_description', 'content' => str_repeat('Acceptable teaser length. ', 5)],
        ]);

        self::assertStringContainsString('Acceptable teaser length', $best);
    }

    public function testLooksLikeConsentWallDetectsGolemInterstitial(): void
    {
        $html = <<<'HTML'
        <!DOCTYPE html>
        <html><head>
        <title>Golem</title>
        <meta name="robots" content="noindex">
        <link href="//cmp-cdn.golem.de" rel="dns-prefetch">
        </head><body>
        <h2>Cookies zustimmen</h2>
        <p>Besuchen Sie Golem.de wie gewohnt mit Werbung und Tracking,
            indem Sie der Nutzung aller Cookies zustimmen.
            Details zum Tracking finden Sie im Privacy Center.</p>
        </body></html>
        HTML;

        self::assertTrue(ArticlePageBodyExtractor::looksLikeConsentWall(
            $html,
            'https://www.golem.de/sonstiges/zustimmung/auswahl.html?from=https%3A%2F%2Fwww.golem.de%2Fnews%2Fexample.html'
        ));
    }

    public function testLooksLikeConsentWallFalseForNormalArticle(): void
    {
        $html = <<<'HTML'
        <!DOCTYPE html>
        <html><head>
        <title>Example headline - Publisher</title>
        <meta name="robots" content="max-image-preview:large">
        <meta property="og:description" content="A normal article teaser.">
        </head><body><article><p>Article body with enough text to be useful for monitoring.</p></article></body></html>
        HTML;

        self::assertFalse(ArticlePageBodyExtractor::looksLikeConsentWall(
            $html,
            'https://example.com/news/story.html'
        ));
    }

    public function testLooksLikeConsentBodyDetectsBoilerplateSnippet(): void
    {
        $plain = 'Cookies zustimmen Besuchen Sie Golem.de wie gewohnt mit Werbung und Tracking, '
            . 'indem Sie der Nutzung aller Cookies zustimmen. Details zum Tracking finden Sie im Privacy Center.';

        self::assertTrue(ArticlePageBodyExtractor::looksLikeConsentBody($plain));
    }

    public function testExtractPaywalledPublisherPreviewFromTamediaEmbeddedJson(): void
    {
        $html = <<<'HTML'
        <!DOCTYPE html><html><head></head><body>
        <script type="application/json">{"title":"«Ist das direkte Demokratie, wenn wir mit dem Revolver an der Stirn entscheiden müssen?»","titleHeader":"Interview zu EU-Verträgen","lead":"Frontalangriff: Urs Wietlisbach wirft dem Bundesrat vor, die Folgen des EU-Pakets zu verharmlosen."}</script>
        <script id="summary-list-data" type="application/json">[{"type":"textBlockArray","items":[{"type":"htmlTextItem","htmlText":"Wietlisbach wirft dem Bundesrat vor, die Folgen der EU-Verträge zu verharmlosen."}]},{"type":"textBlockArray","items":[{"type":"htmlTextItem","htmlText":"Mit der Kompassinitiative verlangt er ein Ständemehr."}]}]</script>
        </body></html>
        HTML;

        $preview = ArticlePageBodyExtractor::extractPaywalledPublisherPreview($html);
        self::assertStringContainsString('Interview zu EU-Verträgen', $preview);
        self::assertStringContainsString('Revolver', $preview);
        self::assertStringContainsString('Frontalangriff:', $preview);
        self::assertStringContainsString('In Kürze:', $preview);
        self::assertStringContainsString('Ständemehr', $preview);
        self::assertGreaterThanOrEqual(
            ArticlePageBodyExtractor::PAYWALL_PREVIEW_MIN_PLAIN,
            mb_strlen(ArticlePageBodyExtractor::toPlainText($preview), 'UTF-8')
        );
    }

    public function testExtractTamediaSummaryListData(): void
    {
        $html = <<<'HTML'
        <script id="summary-list-data" type="application/json">[{"type":"textBlockArray","items":[{"type":"htmlTextItem","htmlText":"First point."}]},{"type":"textBlockArray","items":[{"type":"htmlTextItem","htmlText":"Second point."}]}]</script>
        HTML;

        $summary = ArticlePageBodyExtractor::extractTamediaSummaryListData($html);
        self::assertStringContainsString('In Kürze:', $summary);
        self::assertStringContainsString('First point.', $summary);
        self::assertStringContainsString('Second point.', $summary);
    }

    public function testLooksLikePaywallBodyDetectsTamediaLoginChrome(): void
    {
        $plain = 'Hallo, Login Sie haben kein aktives Abo Unterstützen Sie Qualitätsjournalismus und erhalten Sie Zugriff auf alle Inhalte. Abo abschliessen';

        self::assertTrue(ArticlePageBodyExtractor::looksLikePaywallBody($plain));
    }

    public function testIsPaywalledPublisherHostForTagesanzeiger(): void
    {
        self::assertTrue(ArticlePageBodyExtractor::isPaywalledPublisherHost('www.tagesanzeiger.ch'));
        self::assertFalse(ArticlePageBodyExtractor::isPaywalledPublisherHost('www.20min.ch'));
    }

    public function testExcludeSelectorsForHostNormalisesWww(): void
    {
        self::assertSame(
            ArticlePageBodyExtractor::excludeSelectorsForHost('golem.de'),
            ArticlePageBodyExtractor::excludeSelectorsForHost('www.golem.de')
        );
        self::assertNotSame('', ArticlePageBodyExtractor::excludeSelectorsForHost('www.golem.de'));
    }
}
