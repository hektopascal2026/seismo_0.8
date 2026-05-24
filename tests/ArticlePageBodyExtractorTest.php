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
}
