<?php

declare(strict_types=1);

namespace Seismo\Core\Fetcher;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use fivefilters\Readability\Configuration;
use fivefilters\Readability\Readability;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Symfony\Component\CssSelector\Exception\ExpressionErrorException;
use Symfony\Component\CssSelector\Exception\ParseException as CssParseException;

/**
 * Article text via Readability (with 0.4 heuristic fallback) and date extraction.
 * Used by {@see ScraperFetchService} for preview and production ingest.
 */
final class ScraperContentExtractor
{
    private const MIN_READABLE_CHARS = 50;

    private static ?CssSelectorConverter $cssSelector = null;

    /**
     * @param string $excludeSelectors One selector per line (CSS or XPath;
     *                                 see Scraper admin). Matched elements are
     *                                 removed before extraction.
     *
     * @return array{title: string, content: string}
     */
    public static function extractReadableContent(string $html, string $excludeSelectors = ''): array
    {
        $dom = self::loadDom($html);
        if ($dom === null) {
            return ['title' => '', 'content' => ''];
        }

        $title = self::titleFromDocument($dom);
        if (trim($excludeSelectors) !== '') {
            self::removeElementsMatchingExcludeSelectors($dom, $excludeSelectors);
        }

        $fromReadability = self::extractViaReadability($dom);
        if ($fromReadability !== null) {
            if ($title === '' && $fromReadability['title'] !== '') {
                $title = $fromReadability['title'];
            }

            if ($title === '') {
                $title = self::fallbackTitleFromDocument($dom);
            }

            return [
                'title'   => $title,
                'content' => $fromReadability['content'],
            ];
        }

        self::removeNoiseElements($dom);
        $content = self::extractLongestBlockHeuristic($dom);

        if ($title === '') {
            $title = self::fallbackTitleFromDocument($dom);
        }

        return [
            'title'   => $title,
            'content' => $content,
        ];
    }

    /**
     * First matching value as UTC `Y-m-d H:i:s`, or null.
     * Selector: CSS (full grammar via symfony/css-selector), raw XPath (`/` or `//`), or legacy forms.
     * Excludes: matched elements are removed from the document before the date node is chosen.
     */
    public static function extractPublishedDate(string $html, string $dateSelector, string $excludeSelectors = ''): ?string
    {
        $dateSelector = trim($dateSelector);
        if ($dateSelector === '') {
            return null;
        }

        $dom = self::loadDom($html);
        if ($dom === null) {
            return null;
        }

        if (trim($excludeSelectors) !== '') {
            self::removeElementsMatchingExcludeSelectors($dom, $excludeSelectors);
        }

        $htmlForFallback = $dom->saveHTML() ?: $html;

        $xpQuery = self::dateSelectorToXPath($dateSelector);
        if ($xpQuery === null) {
            return null;
        }

        $xp = new DOMXPath($dom);
        $list = @$xp->query($xpQuery);
        if ($list === false || !($list instanceof DOMNodeList) || $list->length === 0) {
            // Do not scan the whole document when the selector matched nothing — listing
            // pages often contain many teaser dates and the first wins (wrong article date).
            return null;
        }

        for ($i = 0; $i < $list->length; $i++) {
            $node = $list->item($i);
            if ($node === null) {
                continue;
            }
            $parsed = self::parseDateFromNode($node);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        // Selector matched nodes but none parsed — last resort on this page only.
        return self::tryGermanDateStrings($htmlForFallback);
    }

    /**
     * @return ?array{title: string, content: string}
     */
    private static function extractViaReadability(DOMDocument $dom): ?array
    {
        $html = $dom->saveHTML();
        if ($html === false || trim($html) === '') {
            return null;
        }

        try {
            $config = new Configuration();
            $readability = new Readability($config);
            if (!$readability->parse($html)) {
                return null;
            }
            $contentHtml = $readability->getContent();
            if ($contentHtml === null || trim($contentHtml) === '') {
                return null;
            }
            $content = self::htmlFragmentToPlainText($contentHtml);
            if (mb_strlen($content, 'UTF-8') < self::MIN_READABLE_CHARS) {
                return null;
            }
            $title = trim($readability->getTitle() ?? '');

            return [
                'title'   => $title,
                'content' => $content,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function extractLongestBlockHeuristic(DOMDocument $dom): string
    {
        $best = '';
        $bestLen = 0;
        $tags  = ['article', 'main', 'div', 'section'];
        foreach ($tags as $tag) {
            $els = $dom->getElementsByTagName($tag);
            for ($i = 0; $i < $els->length; $i++) {
                $el = $els->item($i);
                if (!($el instanceof DOMElement)) {
                    continue;
                }
                $t = self::textContent($el);
                $len = mb_strlen($t, 'UTF-8');
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $best = $t;
                }
            }
        }

        if ($bestLen < self::MIN_READABLE_CHARS) {
            $bodies = $dom->getElementsByTagName('body');
            if ($bodies->length > 0) {
                $body = $bodies->item(0);
                if ($body instanceof DOMElement) {
                    $best = self::textContent($body);
                }
            }
        }

        return self::normaliseTextWhitespace($best);
    }

    private static function htmlFragmentToPlainText(string $html): string
    {
        $fragmentDom = self::loadDom($html);
        if ($fragmentDom === null) {
            return self::normaliseTextWhitespace(strip_tags($html));
        }
        $bodies = $fragmentDom->getElementsByTagName('body');
        if ($bodies->length > 0) {
            $body = $bodies->item(0);
            if ($body instanceof DOMElement) {
                return self::textContent($body);
            }
        }

        return self::normaliseTextWhitespace(strip_tags($html));
    }

    private static function loadDom(string $html): ?DOMDocument
    {
        $html = self::normaliseEncodingPrefix($html);
        $prev   = libxml_use_internal_errors(true);
        $dom    = new DOMDocument();
        $loaded = $dom->loadHTML($html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $loaded ? $dom : null;
    }

    private static function cssSelector(): CssSelectorConverter
    {
        return self::$cssSelector ??= new CssSelectorConverter();
    }

    private static function parseDateFromNode(DOMNode $node): ?string
    {
        if ($node instanceof DOMElement) {
            $attrOrder = ['datetime', 'content', 'data-date', 'data-datetime', 'date'];
            foreach ($attrOrder as $attr) {
                if ($node->hasAttribute($attr)) {
                    $p = self::strtotimeToDbUtc($node->getAttribute($attr));
                    if ($p !== null) {
                        return $p;
                    }
                }
            }
        }
        $raw = trim($node->textContent ?? '');
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{1,2}\.\d{1,2}\.\d{2,4}$/u', $raw)) {
            return self::strtotimeToDbUtc(str_replace('.', '-', $raw));
        }

        return self::strtotimeToDbUtc($raw);
    }

    private static function tryGermanDateStrings(string $html): ?string
    {
        if (preg_match_all('#\b(\d{1,2}\.\d{1,2}\.\d{2,4})\b#u', $html, $m)) {
            foreach ($m[1] as $cand) {
                $p = self::strtotimeToDbUtc(str_replace('.', '-', $cand));
                if ($p !== null) {
                    return $p;
                }
            }
        }

        return null;
    }

    private static function strtotimeToDbUtc(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        $dt = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('UTC'));

        return $dt->format('Y-m-d H:i:s');
    }

    /**
     * Remove all elements matched by one selector per line (empty lines and
     * `#` line comments ignored). Reuses the same selector grammar as
     * {@see extractPublishedDate()}. Deeper nodes are removed first so parent/child
     * overlaps remain consistent.
     */
    private static function removeElementsMatchingExcludeSelectors(DOMDocument $dom, string $raw): void
    {
        $raw = trim($raw);
        if ($raw === '') {
            return;
        }
        $lines   = preg_split('/\R/u', $raw) ?: [];
        $capped  = array_slice($lines, 0, 50);
        $toCheck = [];
        foreach ($capped as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (mb_strlen($line) > 500) {
                $line = mb_substr($line, 0, 500);
            }
            $toCheck[] = $line;
        }
        if ($toCheck === []) {
            return;
        }

        $xp     = new DOMXPath($dom);
        $candidates = [];
        foreach ($toCheck as $one) {
            $q = self::dateSelectorToXPath($one);
            if ($q === null) {
                continue;
            }
            $list = @$xp->query($q);
            if ($list === false || !($list instanceof DOMNodeList)) {
                continue;
            }
            for ($i = 0; $i < $list->length; $i++) {
                $n = $list->item($i);
                if ($n instanceof DOMElement) {
                    $candidates[] = $n;
                }
            }
        }
        if ($candidates === []) {
            return;
        }

        usort(
            $candidates,
            static function (DOMElement $a, DOMElement $b): int {
                return self::nodeDepthToRoot($b) <=> self::nodeDepthToRoot($a);
            }
        );
        $seen = [];
        foreach ($candidates as $el) {
            $id = spl_object_id($el);
            if (isset($seen[$id])) {
                continue;
            }
            if ($el->parentNode === null) {
                continue;
            }
            $seen[$id] = true;
            $el->parentNode->removeChild($el);
        }
    }

    private static function nodeDepthToRoot(DOMNode $n): int
    {
        $d = 0;
        for ($c = $n; $c->parentNode !== null; $c = $c->parentNode) {
            ++$d;
        }

        return $d;
    }

    private static function dateSelectorToXPath(string $s): ?string
    {
        if (str_starts_with($s, '/') || str_starts_with($s, '(')) {
            return $s;
        }
        if (str_starts_with($s, '//')) {
            return $s;
        }

        try {
            return self::cssSelector()->toXPath($s, '//');
        } catch (ExpressionErrorException|CssParseException) {
            return self::dateSelectorToXPathLegacy($s);
        }
    }

    /**
     * Limited CSS grammar kept for configs written before symfony/css-selector.
     */
    private static function dateSelectorToXPathLegacy(string $s): ?string
    {
        if (str_starts_with($s, 'meta[')) {
            if (preg_match('/^meta\[property="([^"]+)"\]$/i', $s, $m)) {
                return '//meta[@property=' . self::xpathStringLiteral($m[1]) . ']';
            }
        }
        if (preg_match('/^#([A-Za-z0-9_\-]+)$/', $s, $m)) {
            return '//*[@id=' . self::xpathStringLiteral($m[1]) . ']';
        }
        if (preg_match('/^\.([A-Za-z0-9_\-]+)$/', $s, $m)) {
            $c = $m[1];

            return '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $c . ' ")]';
        }
        if (preg_match('/^([a-z0-9]+)((?:\.[A-Za-z0-9_\-]+)+)$/i', $s, $m)) {
            $tag     = $m[1];
            $classes = array_filter(explode('.', substr($m[2], 1)));
            if ($classes !== []) {
                $predicates = [];
                foreach ($classes as $c) {
                    $predicates[] = 'contains(concat(" ", normalize-space(@class), " "), " ' . $c . ' ")';
                }

                return '//' . $tag . '[' . implode(' and ', $predicates) . ']';
            }
        }
        if (preg_match('/^[a-z0-9]+$/i', $s)) {
            return '//' . $s;
        }
        if (preg_match('/^([a-z0-9]+)\[([a-z0-9\-]+)(?:="([^"]*)")?\]$/i', $s, $m)) {
            $tag = $m[1];
            $a   = $m[2];
            if (isset($m[3]) && $m[3] !== '') {
                return '//' . $tag . '[@' . $a . '=' . self::xpathStringLiteral($m[3]) . ']';
            }

            return '//' . $tag . '[@' . $a . ']';
        }

        return null;
    }

    private static function xpathStringLiteral(string $s): string
    {
        if (!str_contains($s, "'")) {
            return "'" . $s . "'";
        }
        if (!str_contains($s, '"')) {
            return '"' . $s . '"';
        }
        $parts = explode("'", $s);
        $bits  = [];
        foreach ($parts as $i => $p) {
            if ($i > 0) {
                $bits[] = '"\'"';
            }
            if ($p !== '' || $i === 0) {
                $bits[] = "'" . $p . "'";
            }
        }

        return 'concat(' . implode(', ', $bits) . ')';
    }

    private static function normaliseEncodingPrefix(string $html): string
    {
        if (str_starts_with($html, '<?xml')) {
            return $html;
        }

        return '<?xml encoding="UTF-8">' . $html;
    }

    private static function titleFromDocument(DOMDocument $dom): string
    {
        // 1. Try Social / Meta tags first (og:title, twitter:title, etc.)
        $metaTitle = self::titleFromMetaTags($dom);
        if ($metaTitle !== null && $metaTitle !== '') {
            return $metaTitle;
        }

        // 2. Try the first <h1> tag that is not within a noise element (header, nav, footer, aside, script, style)
        $h1s = $dom->getElementsByTagName('h1');
        for ($i = 0; $i < $h1s->length; $i++) {
            $h1 = $h1s->item($i);
            if ($h1 instanceof DOMElement && !self::isWithinNoiseElement($h1)) {
                $t = trim($h1->textContent ?? '');
                if ($t !== '') {
                    return html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }

        return '';
    }

    private static function fallbackTitleFromDocument(DOMDocument $dom): string
    {
        $titles = $dom->getElementsByTagName('title');
        if ($titles->length > 0) {
            $t = trim($titles->item(0)->textContent ?? '');

            return html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return '';
    }

    private static function titleFromMetaTags(DOMDocument $dom): ?string
    {
        $xp = new DOMXPath($dom);
        $metaQueries = [
            '//meta[@property="og:title"]/@content',
            '//meta[@name="twitter:title"]/@content',
            '//meta[@property="twitter:title"]/@content',
            '//meta[@name="title"]/@content',
        ];
        foreach ($metaQueries as $query) {
            $nodes = $xp->query($query);
            if ($nodes !== false && $nodes->length > 0) {
                $val = trim($nodes->item(0)->nodeValue ?? '');
                if ($val !== '') {
                    return html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }
        return null;
    }

    private static function isWithinNoiseElement(DOMNode $node): bool
    {
        $parent = $node->parentNode;
        while ($parent !== null) {
            if ($parent instanceof DOMElement) {
                $name = strtolower($parent->nodeName);
                if (in_array($name, ['header', 'nav', 'footer', 'aside', 'script', 'style'])) {
                    return true;
                }
            }
            $parent = $parent->parentNode;
        }

        return false;
    }

    private static function removeNoiseElements(DOMDocument $dom): void
    {
        $removeTags = ['script', 'style', 'nav', 'header', 'footer', 'aside', 'noscript', 'iframe'];
        foreach ($removeTags as $tag) {
            $els = $dom->getElementsByTagName($tag);
            $toRemove = [];
            for ($i = 0; $i < $els->length; $i++) {
                $n = $els->item($i);
                if ($n?->parentNode !== null) {
                    $toRemove[] = $n;
                }
            }
            foreach ($toRemove as $n) {
                $n->parentNode?->removeChild($n);
            }
        }
    }

    private static function textContent(DOMElement $el): string
    {
        $t = $el->textContent ?? '';
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return self::normaliseTextWhitespace($t);
    }

    private static function normaliseTextWhitespace(string $text): string
    {
        $text = preg_replace("/[\h\x{00A0}]+/u", ' ', $text) ?? $text;
        $text = preg_replace('/\R{3,}/u', "\n\n", $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
