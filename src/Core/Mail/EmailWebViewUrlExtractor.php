<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

use DOMDocument;
use DOMElement;

/**
 * Find the newsletter "view in browser" / Webansicht link from HTML or plain text.
 */
final class EmailWebViewUrlExtractor
{
    public static function fromHtml(string $html): ?string
    {
        $html = trim($html);
        if ($html === '') {
            return null;
        }

        $prev = libxml_use_internal_errors(true);
        $dom  = new DOMDocument();
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="seismo-mail-root">' . $html . '</div>',
            LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            return null;
        }

        $root = $dom->getElementById('seismo-mail-root');
        if (!$root instanceof DOMElement) {
            return null;
        }

        foreach ($root->getElementsByTagName('a') as $anchor) {
            if (!$anchor instanceof DOMElement) {
                continue;
            }
            $href = self::normalizeHref($anchor->getAttribute('href'));
            if ($href === null) {
                continue;
            }
            $label   = trim($anchor->textContent . ' ' . $anchor->getAttribute('title'));
            $context = self::anchorContextText($anchor);
            if (EmailWebViewPhraseLexicon::textLooksLikeWebView($label)
                || EmailWebViewPhraseLexicon::textLooksLikeWebView($context)
                || EmailWebViewPhraseLexicon::shortAnchorInWebViewContext($label, $context)
            ) {
                return $href;
            }
        }

        return null;
    }

    public static function fromPlainText(string $plain): ?string
    {
        $plain = trim($plain);
        if ($plain === '') {
            return null;
        }

        $near = self::urlNearWebViewPhrase($plain);
        if ($near !== null) {
            return $near;
        }

        foreach (preg_split("/\r\n|\n|\r/", $plain) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line === '' || !EmailWebViewPhraseLexicon::textLooksLikeWebView($line)) {
                continue;
            }

            return self::firstHttpUrl($line);
        }

        return null;
    }

    private static function urlNearWebViewPhrase(string $text): ?string
    {
        $lower   = EmailWebViewPhraseLexicon::normalizeForMatch($text);
        $bestPos = null;
        foreach (EmailWebViewPhraseLexicon::allPhrases() as $phrase) {
            $pos = mb_strpos($lower, $phrase, 0, 'UTF-8');
            if ($pos !== false && ($bestPos === null || $pos < $bestPos)) {
                $bestPos = $pos;
            }
        }
        if ($bestPos === null) {
            return null;
        }

        $slice = mb_substr($text, (int)$bestPos, 2500, 'UTF-8');

        return self::firstHttpUrl($slice);
    }

    private static function anchorContextText(DOMElement $anchor): string
    {
        $node = $anchor->parentNode;
        for ($i = 0; $i < 5 && $node instanceof DOMElement; $i++) {
            $text = trim($node->textContent ?? '');
            if ($text !== '' && mb_strlen($text, 'UTF-8') <= 800) {
                return $text;
            }
            if ($text !== '') {
                return mb_substr($text, 0, 800, 'UTF-8');
            }
            $node = $node->parentNode;
        }

        return '';
    }

    private static function firstHttpUrl(string $text): ?string
    {
        if (preg_match_all('#https?://[^\s<>"\'\]]+#iu', $text, $matches) === false) {
            return null;
        }
        foreach ($matches[0] as $raw) {
            $url = self::normalizeHref(rtrim((string)$raw, '.,;)]'));
            if ($url !== null && !EmailTrackingUrl::isTrackingOrAsset($url)) {
                return $url;
            }
        }

        return null;
    }

    private static function normalizeHref(string $href): ?string
    {
        $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $href = ltrim($href, '[');
        $href = rtrim($href, '].,;');
        if ($href === ''
            || str_starts_with(strtolower($href), 'mailto:')
            || str_starts_with(strtolower($href), 'tel:')
            || str_starts_with(strtolower($href), 'javascript:')
        ) {
            return null;
        }
        if (!preg_match('#^https?://#i', $href)) {
            return null;
        }

        return $href;
    }
}
