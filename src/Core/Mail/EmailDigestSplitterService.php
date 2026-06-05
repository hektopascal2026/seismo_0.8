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
        $splitRules = $config['split_rules'] ?? [];
        if ($splitRules === []) {
            return [];
        }

        $method = trim((string)($splitRules['split_method'] ?? 'html_selector'));
        if ($method === 'html_selector') {
            return $this->splitByHtmlSelector($htmlBody, $splitRules);
        }

        if ($method === 'regex_split') {
            return $this->splitByRegex($textBody, $htmlBody, $splitRules);
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
        $nodes = $xpath->query($xQuery);
        if ($nodes === false || $nodes->length === 0) {
            return [];
        }

        $titleSelector = trim((string)($rules['title_selector'] ?? ''));
        $linkSelector = trim((string)($rules['link_selector'] ?? ''));
        $bodySelector = trim((string)($rules['body_selector'] ?? ''));

        $stories = [];
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            // Extract Title
            $title = '';
            if ($titleSelector !== '') {
                $titleNodes = $xpath->query($this->cssToXPath($titleSelector), $node);
                if ($titleNodes !== false && $titleNodes->length > 0) {
                    $title = trim($titleNodes->item(0)->textContent);
                }
            }

            // Extract Link
            $link = null;
            if ($linkSelector !== '') {
                $linkNodes = $xpath->query($this->cssToXPath($linkSelector), $node);
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
                $bodyNodes = $xpath->query($this->cssToXPath($bodySelector), $node);
                if ($bodyNodes !== false && $bodyNodes->length > 0) {
                    $storyText = trim($bodyNodes->item(0)->textContent);
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
     * Minimal CSS selector to XPath converter. Supports tags, classes, and IDs.
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
                if (preg_match('/^([a-zA-Z0-9*-]*)\.([a-zA-Z0-9_-]+)$/', $subPart, $m)) {
                    $tag = $m[1] !== '' ? $m[1] : '*';
                    $class = $m[2];
                    $subXPaths[] = "descendant-or-self::{$tag}[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]";
                } elseif (preg_match('/^([a-zA-Z0-9*-]*)#([a-zA-Z0-9_-]+)$/', $subPart, $m)) {
                    $tag = $m[1] !== '' ? $m[1] : '*';
                    $id = $m[2];
                    $subXPaths[] = "descendant-or-self::{$tag}[@id='{$id}']";
                } else {
                    $subXPaths[] = "descendant-or-self::{$subPart}";
                }
            }
            $xpathParts[] = './/' . implode('//', $subXPaths);
        }

        return implode(' | ', $xpathParts);
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
