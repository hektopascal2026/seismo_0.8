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

        $press = self::firstPressReleaseArticleLinkFromDom($root);
        if ($press !== null) {
            return $press;
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

        $press = self::firstAdminChNewnsbUrl($plain);
        if ($press !== null) {
            return $press;
        }

        $press = self::firstEuroparlPressRoomUrl($plain);
        if ($press !== null) {
            return $press;
        }

        $press = self::firstPressReleaseUrlNearMarker($plain);
        if ($press !== null) {
            return $press;
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
        $parent = $anchor->parentNode;
        if ($parent instanceof DOMElement) {
            return trim($parent->textContent ?? '');
        }

        return '';
    }

    /**
     * Government / institution press mail: article URL is often the headline link, not a
     * labelled "view in browser" line (Swiss /newnsb/, Europarl /press-room/, etc.).
     */
    private static function firstPressReleaseArticleLinkFromDom(DOMElement $root): ?string
    {
        $bodyNorm = EmailWebViewPhraseLexicon::normalizeForMatch($root->textContent ?? '');
        $isPress  = str_contains($bodyNorm, 'medienmitteilung')
            || str_contains($bodyNorm, 'news service bund')
            || str_contains($bodyNorm, 'communique de presse')
            || str_contains($bodyNorm, 'press release')
            || (str_contains($bodyNorm, 'press service') && str_contains($bodyNorm, 'european parliament'));

        $headlineFallback = null;
        foreach ($root->getElementsByTagName('a') as $anchor) {
            if (!$anchor instanceof DOMElement) {
                continue;
            }
            $href = self::normalizeHref($anchor->getAttribute('href'));
            if ($href === null || EmailTrackingUrl::isTrackingOrAsset($href)) {
                continue;
            }
            if (self::isAdminChNewnsbUrl($href) || self::isEuroparlPressRoomUrl($href)) {
                return $href;
            }
            if ($isPress && $headlineFallback === null && self::looksLikePressHeadlineAnchor($anchor, $href)) {
                $headlineFallback = $href;
            }
        }

        return $headlineFallback;
    }

    private static function firstAdminChNewnsbUrl(string $text): ?string
    {
        if (preg_match_all(
            '#https?://(?:[a-z0-9.-]+\.)?admin\.ch/[^\s<>"\'\]]*newnsb[^\s<>"\'\]]*#iu',
            $text,
            $matches
        ) === false) {
            return null;
        }
        foreach ($matches[0] as $raw) {
            $url = self::normalizeHref((string)$raw);
            if ($url !== null && !EmailTrackingUrl::isTrackingOrAsset($url)) {
                return $url;
            }
        }

        return null;
    }

    private static function firstEuroparlPressRoomUrl(string $text): ?string
    {
        if (preg_match_all(
            '#https?://(?:www\.)?europarl\.europa\.eu/[^\s<>"\'\]]*/press-room/[^\s<>"\'\]]+#iu',
            $text,
            $matches
        ) === false) {
            return null;
        }
        foreach ($matches[0] as $raw) {
            $url = self::normalizeHref((string)$raw);
            if ($url !== null
                && self::isEuroparlPressRoomUrl($url)
                && !EmailTrackingUrl::isTrackingOrAsset($url)
            ) {
                return $url;
            }
        }

        return null;
    }

    private static function firstPressReleaseUrlNearMarker(string $plain): ?string
    {
        $lower = EmailWebViewPhraseLexicon::normalizeForMatch($plain);
        $markers = ['medienmitteilung |', 'medienmitteilung|', 'communique de presse', 'press release |'];
        $bestPos = null;
        foreach ($markers as $marker) {
            $pos = mb_strpos($lower, $marker, 0, 'UTF-8');
            if ($pos !== false && ($bestPos === null || $pos < $bestPos)) {
                $bestPos = $pos;
            }
        }
        if ($bestPos === null) {
            return null;
        }

        $slice = mb_substr($plain, (int)$bestPos, 3000, 'UTF-8');

        return self::firstHttpUrl($slice);
    }

    private static function isAdminChNewnsbUrl(string $url): bool
    {
        return preg_match('#^https?://(?:[a-z0-9.-]+\.)?admin\.ch/.+newnsb#i', $url) === 1;
    }

    private static function isEuroparlPressRoomUrl(string $url): bool
    {
        return preg_match(
            '#^https?://(?:www\.)?europarl\.europa\.eu/.+/press-room/\d{8}IPR\d+#i',
            $url
        ) === 1;
    }

    private static function looksLikePressHeadlineAnchor(DOMElement $anchor, string $href): bool
    {
        $label = trim($anchor->textContent ?? '');
        if (self::isBoilerplateAnchorLabel($label)) {
            return false;
        }
        if (mb_strlen($label, 'UTF-8') < 20) {
            return false;
        }
        $host = mb_strtolower((string)(parse_url($href, PHP_URL_HOST) ?? ''), 'UTF-8');
        if (str_contains($host, 'admin.ch')) {
            if (str_contains(EmailWebViewPhraseLexicon::normalizeForMatch($label), 'news.admin')) {
                return false;
            }

            return true;
        }
        if (str_contains($host, 'europarl.europa.eu')) {
            return self::isEuroparlPressRoomUrl($href);
        }

        return false;
    }

    private static function isBoilerplateAnchorLabel(string $label): bool
    {
        $n = EmailWebViewPhraseLexicon::normalizeForMatch($label);
        if ($n === '') {
            return true;
        }
        if (EmailWebViewPhraseLexicon::textLooksLikeWebView($n)) {
            return true;
        }
        if (preg_match('#^(www\.)?news\.admin\.ch#', $n) === 1) {
            return true;
        }
        if (preg_match('#^medienmitteilung\b#', $n) === 1) {
            return true;
        }
        if (preg_match('#^press (service|release)\b#', $n) === 1) {
            return true;
        }
        if (preg_match('#^european parliament\b#', $n) === 1) {
            return true;
        }
        if (preg_match('#^(available in|plenary session)\b#', $n) === 1) {
            return true;
        }
        if (preg_match('#^[a-z]{2}$#', $n) === 1) {
            return true;
        }

        return mb_strlen($n, 'UTF-8') < 8;
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
