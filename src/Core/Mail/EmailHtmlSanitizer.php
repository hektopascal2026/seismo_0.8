<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

use DOMDocument;
use DOMElement;
use DOMNode;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitize newsletter HTML before plain-text extraction (Slice 11c).
 */
final class EmailHtmlSanitizer
{
    /** Above this byte size, skip HTMLSanitizer (CPU/memory) and use strip_tags. */
    private const PURIFY_MAX_BYTES = 150_000;

    private static ?HtmlSanitizer $sanitizer = null;

    public static function sanitize(string $html, bool $allowWebviewRedirects = false): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = preg_replace('/<head\b[^>]*>.*?<\/head>/is', '', $html) ?? $html;

        if (strlen($html) > self::PURIFY_MAX_BYTES) {
            return self::normalizeAnchorHrefs(strip_tags($html), $allowWebviewRedirects);
        }

        try {
            $clean = self::sanitizer()->sanitize($html);
        } catch (\Throwable $e) {
            error_log('Seismo EmailHtmlSanitizer: ' . $e->getMessage());

            return '';
        }

        $clean = trim($clean);
        if ($clean === '') {
            return '';
        }

        return self::normalizeAnchorHrefs($clean, $allowWebviewRedirects);
    }

    private static function sanitizer(): HtmlSanitizer
    {
        if (self::$sanitizer !== null) {
            return self::$sanitizer;
        }

        $config = (new HtmlSanitizerConfig())
            ->allowElement('p', ['class', 'id'])
            ->allowElement('br')
            ->allowElement('div', ['class', 'id'])
            ->allowElement('span', ['class', 'id'])
            ->allowElement('h1', ['class', 'id'])
            ->allowElement('h2', ['class', 'id'])
            ->allowElement('h3', ['class', 'id'])
            ->allowElement('h4', ['class', 'id'])
            ->allowElement('h5', ['class', 'id'])
            ->allowElement('h6', ['class', 'id'])
            ->allowElement('ul', ['class', 'id'])
            ->allowElement('ol', ['class', 'id'])
            ->allowElement('li', ['class', 'id'])
            ->allowElement('blockquote', ['class', 'id'])
            ->allowElement('strong', ['class', 'id'])
            ->allowElement('em', ['class', 'id'])
            ->allowElement('b')
            ->allowElement('i')
            ->allowElement('a', ['href', 'title', 'class', 'id'])
            ->allowElement('table', ['class', 'id'])
            ->allowElement('tbody', ['class', 'id'])
            ->allowElement('thead', ['class', 'id'])
            ->allowElement('tr', ['class', 'id'])
            ->allowElement('td', ['class', 'id', 'width', 'colspan', 'height'])
            ->allowElement('th', ['class', 'id']);

        self::$sanitizer = new HtmlSanitizer($config);

        return self::$sanitizer;
    }

    private static function normalizeAnchorHrefs(string $html, bool $allowWebviewRedirects = false): string
    {
        $dom = HtmlParser::parse('<div id="seismo-mail-root">' . $html . '</div>');
        $root = $dom->getElementById('seismo-mail-root');
        if (!$root instanceof DOMElement) {
            return $html;
        }

        self::normalizeAnchorsInSubtree($dom, $root, $allowWebviewRedirects);

        $inner = '';
        foreach ($root->childNodes as $child) {
            $inner .= HtmlParser::saveHTML($child);
        }

        return trim($inner);
    }

    private static function normalizeAnchorsInSubtree(DOMDocument $dom, DOMNode $node, bool $allowWebviewRedirects = false): void
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'a') {
                $href = trim($child->getAttribute('href'));
                if ($href !== '') {
                    $isAllowedRedirect = false;
                    if ($allowWebviewRedirects && EmailTrackingUrl::isRedirectTrackingUrl($href)) {
                        $lower = mb_strtolower($href, 'UTF-8');
                        if (str_contains($lower, 'mailchi.mp')
                            || str_contains($lower, 'campaign-archive.com')
                            || str_contains($lower, 'list-manage.com')
                            || str_contains($lower, 'awstrack.me')
                        ) {
                            $isAllowedRedirect = true;
                        }
                    }

                    if (EmailTrackingUrl::isRedirectTrackingUrl($href) && !$isAllowedRedirect) {
                        $label = trim($child->textContent);
                        $text  = $dom->createTextNode($label);
                        $child->parentNode?->replaceChild($text, $child);
                        continue;
                    }
                    $cleaned = EmailTrackingUrl::cleanNewsletterHref($href);
                    if ($cleaned !== '' && $cleaned !== $href) {
                        $child->setAttribute('href', $cleaned);
                    }
                }
            }
            if ($child instanceof DOMElement) {
                self::normalizeAnchorsInSubtree($dom, $child, $allowWebviewRedirects);
            }
        }
    }
}
