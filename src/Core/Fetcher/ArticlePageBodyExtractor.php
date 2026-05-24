<?php

declare(strict_types=1);

namespace Seismo\Core\Fetcher;

/**
 * Collect article body text from public publisher HTML using several
 * machine-readable layers, then return the strongest candidate.
 *
 * Used by {@see RssArticleHydrator} for Media / thin-RSS hydration.
 */
final class ArticlePageBodyExtractor
{
    private const MIN_CANDIDATE_PLAIN = 50;

    /** Tie-break when two candidates have equal plain-text length. */
    private const SOURCE_PRIORITY = [
        'json_ld'          => 4,
        'readability'      => 3,
        'og_description'   => 2,
        'meta_description' => 1,
    ];

    private const ARTICLE_JSON_LD_TYPES = [
        'NewsArticle',
        'Article',
        'BlogPosting',
        'ReportageNewsArticle',
        'AnalysisNewsArticle',
        'OpinionNewsArticle',
        'ReviewNewsArticle',
        'WebPage',
    ];

    /**
     * @param string $excludeSelectors Passed through to {@see ScraperContentExtractor}.
     */
    public static function extractBestArticleBody(string $html, string $excludeSelectors = ''): string
    {
        $candidates = [];

        $jsonLd = self::extractJsonLdArticleBody($html);
        if ($jsonLd !== '') {
            $candidates[] = ['source' => 'json_ld', 'content' => $jsonLd];
        }

        $read = ScraperContentExtractor::extractReadableContent($html, $excludeSelectors);
        $readable = trim($read['content'] ?? '');
        if ($readable !== '') {
            $candidates[] = ['source' => 'readability', 'content' => $readable];
        }

        $og = self::extractMetaContent($html, 'property', 'og:description');
        if ($og !== '') {
            $candidates[] = ['source' => 'og_description', 'content' => $og];
        }

        $meta = self::extractMetaContent($html, 'name', 'description');
        if ($meta !== '') {
            $candidates[] = ['source' => 'meta_description', 'content' => $meta];
        }

        return self::pickBestCandidate($candidates);
    }

    /**
     * @param list<array{source: string, content: string}> $candidates
     */
    public static function pickBestCandidate(array $candidates): string
    {
        $best     = '';
        $bestLen  = 0;
        $bestPri  = 0;

        foreach ($candidates as $candidate) {
            $content = trim($candidate['content'] ?? '');
            if ($content === '') {
                continue;
            }

            $plainLen = mb_strlen(self::toPlainText($content), 'UTF-8');
            if ($plainLen < self::MIN_CANDIDATE_PLAIN) {
                continue;
            }

            $priority = self::SOURCE_PRIORITY[$candidate['source'] ?? ''] ?? 0;
            if ($plainLen > $bestLen || ($plainLen === $bestLen && $priority > $bestPri)) {
                $best    = $content;
                $bestLen = $plainLen;
                $bestPri = $priority;
            }
        }

        return $best;
    }

    public static function extractJsonLdArticleBody(string $html): string
    {
        if (!preg_match_all(
            '#<script[^>]+type\s*=\s*(["\'])application/ld\+json\1[^>]*>(.*?)</script>#is',
            $html,
            $matches
        )) {
            return '';
        }

        $best    = '';
        $bestLen = 0;

        foreach ($matches[2] as $rawJson) {
            $rawJson = trim(html_entity_decode($rawJson, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($rawJson === '') {
                continue;
            }

            try {
                $data = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            self::walkJsonLd($data, static function (array $node) use (&$best, &$bestLen): void {
                if (!self::isArticleJsonLdNode($node)) {
                    return;
                }

                foreach (['articleBody', 'text'] as $field) {
                    if (!isset($node[$field]) || !is_string($node[$field])) {
                        continue;
                    }
                    $body = trim($node[$field]);
                    if ($body === '') {
                        continue;
                    }
                    $len = mb_strlen(self::toPlainText($body), 'UTF-8');
                    if ($len > $bestLen) {
                        $best    = $body;
                        $bestLen = $len;
                    }
                }
            });
        }

        return $best;
    }

    private static function walkJsonLd(mixed $node, callable $visit): void
    {
        if (!is_array($node)) {
            return;
        }

        if (array_is_list($node)) {
            foreach ($node as $item) {
                self::walkJsonLd($item, $visit);
            }

            return;
        }

        if (isset($node['@graph']) && is_array($node['@graph'])) {
            self::walkJsonLd($node['@graph'], $visit);
        }

        if (isset($node['@type']) && is_array($node)) {
            $visit($node);
        }

        foreach (['mainEntity', 'mainEntityOfPage', 'hasPart', 'associatedMedia'] as $nestedKey) {
            if (!isset($node[$nestedKey])) {
                continue;
            }
            self::walkJsonLd($node[$nestedKey], $visit);
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function isArticleJsonLdNode(array $node): bool
    {
        $type = $node['@type'] ?? null;
        if ($type === null) {
            return isset($node['articleBody']) || isset($node['text']);
        }

        $types = is_array($type) ? $type : [$type];
        foreach ($types as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $local = preg_replace('#^https?://(www\.)?schema\.org/#i', '', $candidate) ?? $candidate;
            if (in_array($local, self::ARTICLE_JSON_LD_TYPES, true)) {
                if ($local === 'WebPage' && !isset($node['articleBody']) && !isset($node['text'])) {
                    continue;
                }

                return true;
            }
        }

        return isset($node['articleBody']) || isset($node['text']);
    }

    private static function extractMetaContent(string $html, string $attr, string $name): string
    {
        $pattern = '#<meta[^>]+' . preg_quote($attr, '#') . '\s*=\s*(["\'])'
            . preg_quote($name, '#') . '\1[^>]+content\s*=\s*(["\'])(.*?)\2#is';
        if (!preg_match($pattern, $html, $m)) {
            $pattern = '#<meta[^>]+content\s*=\s*(["\'])(.*?)\1[^>]+' . preg_quote($attr, '#')
                . '\s*=\s*(["\'])' . preg_quote($name, '#') . '\3#is';
            if (!preg_match($pattern, $html, $m)) {
                return '';
            }

            return trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return trim(html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public static function toPlainText(string $html): string
    {
        if ($html === '') {
            return '';
        }

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
