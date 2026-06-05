<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

use DOMDocument;
use DOMElement;
use DOMXPath;

final class EmailDigestSplitterService
{
    /**
     * @param string $htmlBody
     * @param string $textBody
     * @param array<string, mixed> $config
     * @return list<array{title: string, html_body: string, text_body: string, link: ?string}>
     */
    public function split(string $htmlBody, string $textBody, array $config): array
    {
        $splitRules = $config['split_rules'] ?? $config;
        if ($splitRules === []) {
            return [];
        }

        $method = trim((string)($splitRules['split_method'] ?? $splitRules['type'] ?? 'html_selector'));
        if ($method === 'html_css' || $method === 'html_selector') {
            $normalizedRules = [
                'story_selector' => $splitRules['story_selector'] ?? $splitRules['selector_story'] ?? '',
                'title_selector' => $splitRules['title_selector'] ?? $splitRules['selector_title'] ?? '',
                'link_selector' => $splitRules['link_selector'] ?? $splitRules['selector_link'] ?? '',
                'body_selector' => $splitRules['body_selector'] ?? $splitRules['selector_body'] ?? '',
            ];
            if (!empty($splitRules['exclude_selectors']) && is_array($splitRules['exclude_selectors'])) {
                $normalizedRules['exclude_selectors'] = $splitRules['exclude_selectors'];
            }
            return $this->splitByHtmlSelector($htmlBody, $normalizedRules);
        }

        if ($method === 'regex' || $method === 'regex_split') {
            $normalizedRules = [
                'split_pattern' => $splitRules['split_pattern'] ?? $splitRules['pattern_split'] ?? '',
                'title_pattern' => $splitRules['title_pattern'] ?? $splitRules['pattern_title'] ?? '',
                'body_pattern' => $splitRules['body_pattern'] ?? $splitRules['pattern_body'] ?? '',
                'link_pattern' => $splitRules['link_pattern'] ?? $splitRules['pattern_link'] ?? '',
            ];
            return $this->splitByRegex($textBody, $htmlBody, $normalizedRules);
        }

        return [];
    }

    /**
     * @param array<string, mixed> $rules
     * @return list<array{title: string, html_body: string, text_body: string, link: ?string}>
     */
    private function splitByHtmlSelector(string $htmlBody, array $rules): array
    {
        $storySelector = trim((string)($rules['story_selector'] ?? ''));
        if ($storySelector === '') {
            return [];
        }

        $prev = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        // Force UTF-8 encoding declaration if not present
        if (stripos($htmlBody, 'encoding=') === false) {
            $htmlBody = '<?xml encoding="UTF-8">' . $htmlBody;
        }
        $loaded = $dom->loadHTML($htmlBody, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$loaded) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        $xQuery = $this->cssToXPath($storySelector);
        try {
            $nodes = @$xpath->query($xQuery);
        } catch (\Throwable $e) {
            $nodes = false;
        }
        if ($nodes === false || $nodes->length === 0) {
            return [];
        }

        $storyNodes = $this->innermostStoryNodes($nodes);

        $titleSelector = trim((string)($rules['title_selector'] ?? ''));
        $linkSelector = trim((string)($rules['link_selector'] ?? ''));
        $bodySelector = trim((string)($rules['body_selector'] ?? ''));

        $stories = [];
        $excludeSelectors = $rules['exclude_selectors'] ?? [];
        if (!is_array($excludeSelectors)) {
            $excludeSelectors = [];
        }

        foreach ($storyNodes as $node) {
            if ($this->nodeMatchesExcludeSelector($node, $excludeSelectors)) {
                continue;
            }

            if (!$this->nodeLooksLikeStory($xpath, $node)) {
                continue;
            }

            // Extract Title
            $title = '';
            if ($titleSelector !== '') {
                try {
                    $titleNodes = @$xpath->query($this->cssToXPath($titleSelector), $node);
                } catch (\Throwable $e) {
                    $titleNodes = false;
                }
                if ($titleNodes !== false && $titleNodes->length > 0) {
                    $title = trim($titleNodes->item(0)->textContent);
                }
            }

            // Extract Link
            $link = null;
            if ($linkSelector !== '') {
                try {
                    $linkNodes = @$xpath->query($this->cssToXPath($linkSelector), $node);
                } catch (\Throwable $e) {
                    $linkNodes = false;
                }
                if ($linkNodes !== false && $linkNodes->length > 0) {
                    $item = $linkNodes->item(0);
                    if ($item instanceof DOMElement) {
                        $link = trim($item->getAttribute('href')) ?: null;
                    }
                }
            } else {
                $linkNodes = $node->getElementsByTagName('a');
                if ($linkNodes->length > 0) {
                    $item = $linkNodes->item(0);
                    if ($item instanceof DOMElement) {
                        $link = trim($item->getAttribute('href')) ?: null;
                    }
                }
            }

            // Extract Body text & HTML
            $storyHtml = $dom->saveHTML($node);
            $storyText = '';
            if ($bodySelector !== '') {
                try {
                    $bodyNodes = @$xpath->query($this->cssToXPath($bodySelector), $node);
                } catch (\Throwable $e) {
                    $bodyNodes = false;
                }
                if ($bodyNodes !== false && $bodyNodes->length > 0) {
                    $storyText = $this->longestNodeText($bodyNodes, $title);
                }
            } else {
                $storyText = trim($node->textContent);
            }

            $storyText = preg_replace('/\s+/', ' ', $storyText);
            $storyText = trim($storyText);

            if ($title === '') {
                $title = mb_substr($storyText, 0, 80);
                if (mb_strlen($storyText) > 80) {
                    $title .= '...';
                }
            }

            if ($title !== '' || $storyText !== '') {
                $stories[] = [
                    'title' => $title,
                    'html_body' => $storyHtml,
                    'text_body' => $storyText,
                    'link' => $link,
                ];
            }
        }

        return $stories;
    }

    /**
     * @return list<DOMElement>
     */
    private function innermostStoryNodes(\DOMNodeList $nodes): array
    {
        /** @var list<DOMElement> $elements */
        $elements = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        $innermost = [];
        foreach ($elements as $node) {
            $hasMatchingDescendant = false;
            foreach ($elements as $other) {
                if ($other !== $node && $this->isDescendantOf($other, $node)) {
                    $hasMatchingDescendant = true;
                    break;
                }
            }
            if (!$hasMatchingDescendant) {
                $innermost[] = $node;
            }
        }

        return $innermost;
    }

    private function isDescendantOf(DOMElement $node, DOMElement $ancestor): bool
    {
        $parent = $node->parentNode;
        while ($parent instanceof DOMElement) {
            if ($parent === $ancestor) {
                return true;
            }
            $parent = $parent->parentNode;
        }

        return false;
    }

    private function longestNodeText(\DOMNodeList $nodes, string $title): string
    {
        $best = '';
        foreach ($nodes as $bodyNode) {
            $text = trim((string)$bodyNode->textContent);
            if ($text === '' || $text === $title) {
                continue;
            }
            if (mb_strlen($text) > mb_strlen($best)) {
                $best = $text;
            }
        }

        if ($best !== '') {
            return $best;
        }

        $first = $nodes->item(0);

        return $first !== null ? trim((string)$first->textContent) : '';
    }

    private function nodeLooksLikeStory(DOMXPath $xpath, DOMElement $node): bool
    {
        foreach (['h1', 'h2', 'h3', 'h4'] as $headingTag) {
            $headings = @$xpath->query('.//' . $headingTag, $node);
            if ($headings !== false && $headings->length > 0) {
                return true;
            }
        }

        $anchors = @$xpath->query('.//a', $node);
        if ($anchors !== false) {
            foreach ($anchors as $anchor) {
                if (!$anchor instanceof DOMElement) {
                    continue;
                }
                $text = trim($anchor->textContent);
                if ($text === '' || strcasecmp($text, 'Mehr') === 0 || mb_strlen($text) < 8) {
                    continue;
                }

                return true;
            }
        }

        return mb_strlen(trim($node->textContent)) >= 40;
    }

    /**
     * @param list<string> $excludeSelectors
     */
    private function nodeMatchesExcludeSelector(DOMElement $node, array $excludeSelectors): bool
    {
        foreach ($excludeSelectors as $selector) {
            if ($this->elementMatchesSimpleSelector($node, trim((string)$selector))) {
                return true;
            }
        }

        return false;
    }

    private function elementMatchesSimpleSelector(DOMElement $el, string $css): bool
    {
        if ($css === '') {
            return false;
        }

        if (preg_match('/^([a-zA-Z0-9*-]*)\.([a-zA-Z0-9_-]+)$/', $css, $m)) {
            $tag = $m[1];
            $class = $m[2];
            if ($tag !== '' && $tag !== '*' && strtolower($el->tagName) !== strtolower($tag)) {
                return false;
            }

            return $this->elementHasClass($el, $class);
        }

        if (preg_match('/^([a-zA-Z0-9*-]*)#([a-zA-Z0-9_-]+)$/', $css, $m)) {
            $tag = $m[1];
            $id = $m[2];
            if ($tag !== '' && $tag !== '*' && strtolower($el->tagName) !== strtolower($tag)) {
                return false;
            }

            return $el->getAttribute('id') === $id;
        }

        if ($css[0] === '.') {
            return $this->elementHasClass($el, substr($css, 1));
        }

        if ($css[0] === '#') {
            return $el->getAttribute('id') === substr($css, 1);
        }

        return strtolower($el->tagName) === strtolower($css);
    }

    private function elementHasClass(DOMElement $el, string $class): bool
    {
        $classAttr = ' ' . preg_replace('/\s+/', ' ', trim($el->getAttribute('class'))) . ' ';

        return str_contains($classAttr, ' ' . $class . ' ');
    }

    /**
     * CSS selector to XPath. Supports tags, classes, IDs, space descendants, and [attr*="…"].
     */
    private function cssToXPath(string $css): string
    {
        $css = trim($css);
        if ($css === '') {
            return '';
        }

        $parts = explode(',', $css);
        $xpathParts = [];
        foreach ($parts as $part) {
            $part = trim($part);
            $subParts = preg_split('/\s+/', $part);
            $subXPaths = [];
            foreach ($subParts as $subPart) {
                $subPart = trim($subPart);
                if ($subPart === '') {
                    continue;
                }
                $converted = $this->cssTokenToXPath($subPart);
                if ($converted !== '') {
                    $subXPaths[] = $converted;
                }
            }
            if ($subXPaths !== []) {
                $xpathParts[] = './/' . implode('//', $subXPaths);
            }
        }

        return implode(' | ', $xpathParts);
    }

    private function cssTokenToXPath(string $token): string
    {
        if (preg_match('/^([a-zA-Z0-9*-]*)\.([a-zA-Z0-9_-]+)$/', $token, $m)) {
            $tag = $m[1] !== '' ? $m[1] : '*';

            return "descendant-or-self::{$tag}[contains(concat(' ', normalize-space(@class), ' '), ' {$m[2]} ')]";
        }

        if (preg_match('/^([a-zA-Z0-9*-]*)#([a-zA-Z0-9_-]+)$/', $token, $m)) {
            $tag = $m[1] !== '' ? $m[1] : '*';

            return "descendant-or-self::{$tag}[@id='{$m[2]}']";
        }

        if (preg_match('/^\.([a-zA-Z0-9_-]+)$/', $token, $m)) {
            return "descendant-or-self::*[contains(concat(' ', normalize-space(@class), ' '), ' {$m[1]} ')]";
        }

        if (preg_match('/^#([a-zA-Z0-9_-]+)$/', $token, $m)) {
            return "descendant-or-self::*[@id='{$m[1]}']";
        }

        if (preg_match('/^([a-zA-Z0-9*-]+)\[([^=\]]+)(\*="([^"]+)"|"([^"]+)")?\]$/', $token, $m)) {
            $tag = $m[1] !== '' ? $m[1] : '*';
            $attr = $m[2];
            $contains = $m[4] !== '' ? $m[4] : ($m[5] !== '' ? $m[5] : '');
            if ($contains === '') {
                return "descendant-or-self::{$tag}[@{$attr}]";
            }

            return "descendant-or-self::{$tag}[contains(@{$attr}, '{$contains}')]";
        }

        if (preg_match('/^[a-zA-Z0-9*-]+$/', $token)) {
            return "descendant-or-self::{$token}";
        }

        return '';
    }

    /**
     * @param array<string, mixed> $rules
     * @return list<array{title: string, html_body: string, text_body: string, link: ?string}>
     */
    private function splitByRegex(string $textBody, string $htmlBody, array $rules): array
    {
        $splitPattern = trim((string)($rules['split_pattern'] ?? ''));
        if ($splitPattern === '') {
            return [];
        }

        $parts = preg_split($splitPattern, $textBody);
        if ($parts === false || count($parts) <= 1) {
            return [];
        }

        $titlePattern = trim((string)($rules['title_pattern'] ?? ''));
        $linkPattern = trim((string)($rules['link_pattern'] ?? ''));

        $stories = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $title = '';
            if ($titlePattern !== '') {
                if (preg_match($titlePattern, $part, $matches)) {
                    $title = trim($matches[1] ?? $matches[0] ?? '');
                }
            }

            $link = null;
            if ($linkPattern !== '') {
                if (preg_match($linkPattern, $part, $matches)) {
                    $link = trim($matches[1] ?? $matches[0] ?? '') ?: null;
                }
            }

            if ($title === '') {
                $title = mb_substr($part, 0, 80);
                if (mb_strlen($part) > 80) {
                    $title .= '...';
                }
            }

            $stories[] = [
                'title' => $title,
                'html_body' => $part,
                'text_body' => $part,
                'link' => $link,
            ];
        }

        return $stories;
    }
}
