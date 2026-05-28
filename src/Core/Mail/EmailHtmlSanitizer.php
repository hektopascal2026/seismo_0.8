<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

use DOMDocument;
use DOMElement;
use DOMNode;
use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Sanitize newsletter HTML before plain-text extraction (Slice 11c).
 */
final class EmailHtmlSanitizer
{
    /** Above this byte size, skip HTMLPurifier (CPU/memory) and use strip_tags. */
    private const PURIFY_MAX_BYTES = 150_000;

    private static ?HTMLPurifier $purifier = null;

    public static function sanitize(string $html, bool $allowWebviewRedirects = false): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        if (strlen($html) > self::PURIFY_MAX_BYTES) {
            return self::normalizeAnchorHrefs(strip_tags($html), $allowWebviewRedirects);
        }

        try {
            $clean = self::purifier()->purify($html);
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

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier !== null) {
            return self::$purifier;
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.DefinitionImpl', null);
        $config->set(
            'HTML.Allowed',
            'p,br,div,span,h1,h2,h3,h4,h5,h6,ul,ol,li,blockquote,'
            . 'strong,em,b,i,a[href|title],table,tbody,thead,tr,td,th'
        );
        $config->set('HTML.ForbiddenElements', [
            'img', 'script', 'style', 'meta', 'link', 'head', 'iframe', 'object', 'embed',
            'form', 'input', 'button', 'noscript',
        ]);
        $config->set('URI.DisableExternalResources', true);
        $config->set('URI.DisableResources', true);
        $config->set('AutoFormat.RemoveEmpty', true);

        self::$purifier = new HTMLPurifier($config);

        return self::$purifier;
    }

    private static function normalizeAnchorHrefs(string $html, bool $allowWebviewRedirects = false): string
    {
        $prev = libxml_use_internal_errors(true);
        $dom  = new DOMDocument();
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="seismo-mail-root">' . $html . '</div>',
            LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            return $html;
        }

        $root = $dom->getElementById('seismo-mail-root');
        if (!$root instanceof DOMElement) {
            return $html;
        }

        self::normalizeAnchorsInSubtree($dom, $root, $allowWebviewRedirects);

        $inner = '';
        foreach ($root->childNodes as $child) {
            $inner .= $dom->saveHTML($child);
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
