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

/**
 * Readability-lite + date extraction — ported from 0.4 seismo_scraper.php behaviour
 * (see project docs / consolidation notes). Used by {@see ScraperFetchService} for
 * preview and production ingest.
 */
final class ScraperContentExtractor
{
    /**
     * @param string $excludeSelectors One selector per line (same limited CSS / XPath
     *                                 grammar as the date field). Matched elements are
     *                                 removed from the document before heuristics and
     *                                 built-in noise stripping.
     *
     * @return array{title: string, content: string}
     */
    public static function extractReadableContent(string $html, string $excludeSelectors = ''): array
    {
        $html = self::normaliseEncodingPrefix($html);
        $prev  = libxml_use_internal_errors(true);
        $dom   = new DOMDocument();
        $loaded = $dom->loadHTML($html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            return ['title' => '', 'content' => ''];
        }

        $title = self::titleFromDocument($dom);
        if (trim($excludeSelectors) !== '') {
            self::removeElementsMatchingExcludeSelectors($dom, $excludeSelectors);
        }
        self::removeNoiseElements($dom);

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
                if ($len > 200 && $best === $t) {
                    // Keep scanning for longest; 0.4 “stop early” = optional perf only.
                }
            }
        }

        if ($bestLen < 50) {
            $bodies = $dom->getElementsByTagName('body');
            if ($bodies->length > 0) {
                $body = $bodies->item(0);
                if ($body instanceof DOMElement) {
                    $best = self::textContent($body);
                }
            }
        }

        $content = self::normaliseTextWhitespace($best);

        return [
            'title'   => $title,
            'content' => $content,
        ];
    }

    /**
     * First matching value as UTC `Y-m-d H:i:s`, or null.
     * Selector: limited CSS, raw XPath (starts with `/`), or `//...`.
     * Excludes: matched elements are removed from the document before the date node is chosen.
     */
    public static function extractPublishedDate(string $html, string $dateSelector, string $excludeSelectors = ''): ?string
    {
        $dateSelector = trim($dateSelector);
        if ($dateSelector === '') {
            return null;
        }

        $html = self::normaliseEncodingPrefix($html);
        $prev  = libxml_use_internal_errors(true);
        $dom   = new DOMDocument();
        $loaded = $dom->loadHTML($html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
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
     * `#` line comments ignored). Reuses the same limited selector grammar as
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
        $titles = $dom->getElementsByTagName('title');
        if ($titles->length > 0) {
            $t = trim($titles->item(0)->textContent ?? '');

            return html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return '';
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
