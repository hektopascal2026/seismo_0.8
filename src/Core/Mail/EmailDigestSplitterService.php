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
            if (!empty($splitRules['exclude_titles']) && is_array($splitRules['exclude_titles'])) {
                $normalizedRules['exclude_titles'] = $splitRules['exclude_titles'];
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
            if (!empty($splitRules['exclude_titles']) && is_array($splitRules['exclude_titles'])) {
                $normalizedRules['exclude_titles'] = $splitRules['exclude_titles'];
            }

            return $this->applyExcludeTitles($this->splitByRegex($textBody, $htmlBody, $normalizedRules), $normalizedRules);
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

        $storyNodes = $this->groupIntoStoryWrappers($this->innermostStoryNodes($nodes));

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

            $title = $this->extractBestTitle($xpath, $node, $titleSelector);
            $link = $this->extractBestLink($xpath, $node, $linkSelector);

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

            $storyText = EmailBodyDisplay::collapseForStorage($storyText);

            if ($title === '') {
                $collapsedTitle = preg_replace('/\s+/', ' ', $storyText) ?? '';
                $title = mb_substr(trim($collapsedTitle), 0, 80);
                if (mb_strlen(trim($collapsedTitle)) > 80) {
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

        return $this->applyExcludeTitles(
            $this->deduplicateStories($this->mergeAdjacentFragments($stories)),
            $rules,
        );
    }

    /**
     * @param list<array{title: string, html_body: string, text_body: string, link: ?string}> $stories
     * @param array<string, mixed> $rules
     * @return list<array{title: string, html_body: string, text_body: string, link: ?string}>
     */
    private function applyExcludeTitles(array $stories, array $rules): array
    {
        $excludeTitles = $rules['exclude_titles'] ?? [];
        if (!is_array($excludeTitles) || $excludeTitles === []) {
            return $stories;
        }

        $normalizedExcludes = [];
        foreach ($excludeTitles as $title) {
            $norm = $this->normalizeTitleToken((string)$title);
            if ($norm !== '') {
                $normalizedExcludes[] = $norm;
            }
        }
        if ($normalizedExcludes === []) {
            return $stories;
        }

        $out = [];
        foreach ($stories as $story) {
            $norm = $this->normalizeTitleToken((string)($story['title'] ?? ''));
            if ($norm !== '' && in_array($norm, $normalizedExcludes, true)) {
                continue;
            }
            $out[] = $story;
        }

        return $out;
    }

    private function normalizeTitleToken(string $title): string
    {
        $title = preg_replace('/\s+/u', ' ', trim($title)) ?? trim($title);

        return mb_strtolower($title);
    }

    /**
     * @param list<DOMElement> $nodes
     * @return list<DOMElement>
     */
    private function groupIntoStoryWrappers(array $nodes): array
    {
        $wrappers = [];
        $seen = [];

        foreach ($nodes as $node) {
            $wrapper = $this->resolveStoryWrapper($node);
            $id = spl_object_id($wrapper);
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $wrappers[] = $wrapper;
            }
        }

        return $wrappers;
    }

    private function resolveStoryWrapper(DOMElement $node): DOMElement
    {
        $current = $node;

        while ($current instanceof DOMElement) {
            if (strtolower($current->tagName) === 'table') {
                return $current;
            }

            if ($this->elementHasClass($current, 'csc-frame-default')) {
                return $current;
            }

            if ($this->tableContainsHeadlineAndBody($current)) {
                return $current;
            }

            $parent = $current->parentNode;
            $current = $parent instanceof DOMElement ? $parent : null;
        }

        return $node;
    }

    private function tableContainsHeadlineAndBody(DOMElement $table): bool
    {
        if (strtolower($table->tagName) !== 'table') {
            return false;
        }

        $rows = $table->childNodes;
        $directRows = [];
        foreach ($rows as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'tr') {
                $directRows[] = $child;
            }
        }

        if ($directRows === []) {
            foreach ($table->getElementsByTagName('tbody') as $tbody) {
                if (!$tbody instanceof DOMElement) {
                    continue;
                }
                foreach ($tbody->childNodes as $child) {
                    if ($child instanceof DOMElement && strtolower($child->tagName) === 'tr') {
                        $directRows[] = $child;
                    }
                }
                if ($directRows !== []) {
                    break;
                }
            }
        }

        if (count($directRows) < 2 || count($directRows) > 4) {
            return false;
        }

        $hasHeadline = false;
        $hasBody = false;

        foreach ($directRows as $row) {
            $rowText = trim($row->textContent);
            if ($rowText === '' || $this->isCtaLinkText($rowText)) {
                continue;
            }

            foreach ($row->getElementsByTagName('a') as $link) {
                if (!$link instanceof DOMElement) {
                    continue;
                }
                $linkText = trim($link->textContent);
                if ($linkText === '' || $this->isCtaLinkText($linkText)) {
                    continue;
                }

                $style = strtolower($link->getAttribute('style'));
                if (
                    str_contains($style, 'font-weight:bold')
                    || str_contains($style, 'font-weight: bold')
                    || mb_strlen($linkText) >= 12
                ) {
                    $hasHeadline = true;
                    break;
                }
            }

            if (mb_strlen($rowText) >= 40) {
                $hasBody = true;
            }
        }

        return $hasHeadline && $hasBody;
    }

    private function extractBestTitle(DOMXPath $xpath, DOMElement $node, string $titleSelector): string
    {
        foreach (['h1', 'h2', 'h3', 'h4'] as $tag) {
            $headings = $node->getElementsByTagName($tag);
            if ($headings->length > 0) {
                $text = trim((string)$headings->item(0)->textContent);
                if ($text !== '' && !$this->isCtaLinkText($text)) {
                    return $text;
                }
            }
        }

        if ($titleSelector === '') {
            return '';
        }

        try {
            $titleNodes = @$xpath->query($this->cssToXPath($titleSelector), $node);
        } catch (\Throwable $e) {
            $titleNodes = false;
        }

        if ($titleNodes === false || $titleNodes->length === 0) {
            return '';
        }

        $best = '';
        foreach ($titleNodes as $candidate) {
            $text = trim((string)$candidate->textContent);
            if ($text === '' || $this->isCtaLinkText($text)) {
                continue;
            }

            if ($candidate instanceof DOMElement && strtolower($candidate->tagName) === 'a') {
                $style = strtolower($candidate->getAttribute('style'));
                if (
                    str_contains($style, 'font-weight:bold')
                    || str_contains($style, 'font-weight: bold')
                ) {
                    return $text;
                }
            }

            if (mb_strlen($text) > mb_strlen($best)) {
                $best = $text;
            }
        }

        return $best;
    }

    private function extractBestLink(DOMXPath $xpath, DOMElement $node, string $linkSelector): ?string
    {
        $candidates = [];

        if ($linkSelector !== '') {
            try {
                $linkNodes = @$xpath->query($this->cssToXPath($linkSelector), $node);
            } catch (\Throwable $e) {
                $linkNodes = false;
            }
            if ($linkNodes !== false) {
                foreach ($linkNodes as $item) {
                    if ($item instanceof DOMElement) {
                        $candidates[] = $item;
                    }
                }
            }
        } else {
            foreach ($node->getElementsByTagName('a') as $item) {
                if ($item instanceof DOMElement) {
                    $candidates[] = $item;
                }
            }
        }

        $bestHref = null;
        $bestScore = -1;
        foreach ($candidates as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            if ($href === '') {
                continue;
            }

            $text = trim($anchor->textContent);
            if ($this->isCtaLinkText($text)) {
                continue;
            }

            $score = mb_strlen($text);
            $style = strtolower($anchor->getAttribute('style'));
            if (
                str_contains($style, 'font-weight:bold')
                || str_contains($style, 'font-weight: bold')
            ) {
                $score += 100;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestHref = $href;
            }
        }

        if ($bestHref !== null) {
            return $bestHref;
        }

        foreach ($candidates as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            if ($href !== '') {
                return $href;
            }
        }

        return null;
    }

    /**
     * @param list<array{title: string, html_body: string, text_body: string, link: ?string}> $stories
     * @return list<array{title: string, html_body: string, text_body: string, link: ?string}>
     */
    private function mergeAdjacentFragments(array $stories): array
    {
        if (count($stories) < 2) {
            return $stories;
        }

        $merged = [];
        $index = 0;
        while ($index < count($stories)) {
            $current = $stories[$index];
            $next = $stories[$index + 1] ?? null;

            if ($next !== null && $this->shouldMergeFragments($current, $next)) {
                $merged[] = $this->combineFragments($current, $next);
                $index += 2;
                continue;
            }

            $merged[] = $current;
            ++$index;
        }

        return $merged;
    }

    /**
     * @param array{title: string, html_body: string, text_body: string, link: ?string} $first
     * @param array{title: string, html_body: string, text_body: string, link: ?string} $second
     */
    private function shouldMergeFragments(array $first, array $second): bool
    {
        return $this->isLikelyTitleFragment($first) && $this->isLikelyBodyFragment($second);
    }

    /**
     * @param array{title: string, html_body: string, text_body: string, link: ?string} $story
     */
    private function isLikelyTitleFragment(array $story): bool
    {
        $title = trim($story['title']);
        $body = trim($story['text_body']);
        if ($title === '') {
            return false;
        }

        if ($body === '' || $body === $title) {
            return true;
        }

        return mb_strlen($body) <= mb_strlen($title) + 20;
    }

    /**
     * @param array{title: string, html_body: string, text_body: string, link: ?string} $story
     */
    private function isLikelyBodyFragment(array $story): bool
    {
        $title = trim($story['title']);
        $body = trim($story['text_body']);
        if (mb_strlen($body) < 40) {
            return false;
        }

        if ($this->isCtaLinkText($title)) {
            return true;
        }

        if ($title === '' || mb_strlen($title) < 12) {
            return true;
        }

        return mb_strlen($body) > mb_strlen($title) * 2 && mb_strlen($title) <= 8;
    }

    /**
     * @param array{title: string, html_body: string, text_body: string, link: ?string} $first
     * @param array{title: string, html_body: string, text_body: string, link: ?string} $second
     * @return array{title: string, html_body: string, text_body: string, link: ?string}
     */
    private function combineFragments(array $first, array $second): array
    {
        $title = trim($first['title']);
        $body = trim($second['text_body']);
        if ($body === '' || $body === $title) {
            $body = trim($first['text_body']);
        }

        return [
            'title' => $title,
            'html_body' => $first['html_body'] . $second['html_body'],
            'text_body' => $body,
            'link' => $first['link'] ?? $second['link'],
        ];
    }

    private function isCtaLinkText(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));

        return in_array($normalized, ['mehr', 'more', 'read more', 'weiterlesen', 'read link →', 'read link ->'], true);
    }

    /**
     * @param list<array{title: string, html_body: string, text_body: string, link: ?string}> $stories
     * @return list<array{title: string, html_body: string, text_body: string, link: ?string}>
     */
    private function deduplicateStories(array $stories): array
    {
        $unique = [];
        $seen = [];

        foreach ($stories as $story) {
            $key = $this->storyFingerprint($story);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $story;
        }

        return $unique;
    }

    /**
     * @param array{title: string, html_body: string, text_body: string, link: ?string} $story
     */
    private function storyFingerprint(array $story): string
    {
        $link = $this->normalizeStoryLink((string)($story['link'] ?? ''));
        if ($link !== '') {
            return 'link:' . $link;
        }

        $title = $this->normalizeStoryText((string)($story['title'] ?? ''));
        $body = $this->normalizeStoryText((string)($story['text_body'] ?? ''));

        return 'text:' . $title . '|' . mb_substr($body, 0, 160);
    }

    private function normalizeStoryLink(string $link): string
    {
        return rtrim(trim(mb_strtolower($link)), '/');
    }

    private function normalizeStoryText(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';

        return mb_strtolower($text);
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
        $elements = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        // Keep only elements that do not contain any other matched elements (innermost matching nodes)
        $filtered = [];
        foreach ($elements as $node) {
            $hasMatchingDescendant = false;
            foreach ($elements as $other) {
                if ($other !== $node && $this->isDescendantOf($other, $node)) {
                    $hasMatchingDescendant = true;
                    break;
                }
            }
            if (!$hasMatchingDescendant) {
                $filtered[] = $node;
            }
        }

        $parts = [];
        foreach ($filtered as $bodyNode) {
            $text = trim((string)$bodyNode->textContent);
            if ($text === '' || $text === $title) {
                continue;
            }
            $parts[] = $text;
        }

        if ($parts !== []) {
            return implode("\n\n", $parts);
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
