<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Convert sanitized newsletter HTML to plain text with paragraph breaks (Slice 11c).
 */
final class EmailPlainTextExtractor
{
    public static function fromSanitizedHtml(string $html, bool $allowWebviewRedirects = false): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $dom = HtmlParser::parse('<div id="seismo-mail-root">' . $html . '</div>');
        $root = $dom->getElementById('seismo-mail-root');
        if (!$root instanceof DOMElement) {
            return '';
        }

        $text = self::nodeText($root, $allowWebviewRedirects);
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private static function nodeText(DOMNode $node, bool $allowWebviewRedirects = false): string
    {
        if ($node instanceof DOMText) {
            return self::normaliseInline($node->textContent ?? '');
        }

        if (!$node instanceof DOMElement) {
            $out = '';
            foreach ($node->childNodes as $child) {
                $out .= self::nodeText($child, $allowWebviewRedirects);
            }

            return $out;
        }

        $tag = strtolower($node->tagName);
        if ($tag === 'br') {
            return "\n";
        }

        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= self::nodeText($child, $allowWebviewRedirects);
        }
        $inner = trim($inner);

        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));
            if ($inner === '') {
                return '';
            }
            $isAllowedRedirect = false;
            if ($allowWebviewRedirects && EmailTrackingUrl::isRedirectTrackingUrl($href)) {
                if (EmailTrackingUrl::isAllowedWebviewRedirectUrl($href)) {
                    $isAllowedRedirect = true;
                }
            }
            if ($href !== '' && (!EmailTrackingUrl::isRedirectTrackingUrl($href) || $isAllowedRedirect)) {
                $href = EmailTrackingUrl::cleanNewsletterHref($href);

                return $inner . ' (' . $href . ')';
            }

            return $inner;
        }

        if (in_array($tag, self::blockTags(), true)) {
            if ($inner === '') {
                return '';
            }

            return $inner . "\n\n";
        }

        return $inner;
    }

    /** @return list<string> */
    private static function blockTags(): array
    {
        return ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'blockquote', 'tr', 'table'];
    }

    private static function normaliseInline(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return $text;
    }
}
