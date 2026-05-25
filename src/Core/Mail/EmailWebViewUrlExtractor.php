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
    /**
     * @param list<int> $preferredLocaleRanks {@see EmailAlternateLocalePolicy::preferredLocaleRanks()}
     */
    public static function resolve(string $html, string $plain, array $preferredLocaleRanks): EmailWebViewResolution
    {
        $html  = trim($html);
        $plain = trim($plain);

        $press = self::pressReleaseUrl($html, $plain);
        if ($press !== null) {
            return new EmailWebViewResolution($press, null, false);
        }

        $picked = self::pickAlternateByRanks(
            self::collectAlternateLocaleCandidates($html, $plain),
            $preferredLocaleRanks
        );
        if ($picked !== null) {
            return new EmailWebViewResolution(
                $picked['url'],
                $picked['rank'],
                EmailAlternateLocalePolicy::shouldHydrateBodyFromWebView($picked['rank'])
            );
        }

        $generic = self::genericWebViewUrl($html, $plain);

        return new EmailWebViewResolution($generic, null, false);
    }

    public static function fromHtml(string $html): ?string
    {
        return self::resolve($html, '', EmailAlternateLocalePolicy::englishFirstRanks())->url;
    }

    public static function fromPlainText(string $plain): ?string
    {
        return self::resolve('', $plain, EmailAlternateLocalePolicy::englishFirstRanks())->url;
    }

    /**
     * @return list<array{url: string, rank: int}>
     */
    public static function collectAlternateLocaleCandidates(string $html, string $plain): array
    {
        $byUrl = [];

        $root = self::loadMailRoot($html);
        if ($root instanceof DOMElement) {
            foreach ($root->getElementsByTagName('a') as $anchor) {
                if (!$anchor instanceof DOMElement) {
                    continue;
                }
                $href = self::normalizeHref($anchor->getAttribute('href'));
                if ($href === null || EmailTrackingUrl::isTrackingOrAsset($href)) {
                    continue;
                }
                $label   = trim($anchor->textContent . ' ' . $anchor->getAttribute('title'));
                $context = self::anchorContextText($anchor);
                $rank    = EmailWebViewPhraseLexicon::alternateLocaleRankForText($label);
                if ($rank === null) {
                    $rank = EmailWebViewPhraseLexicon::alternateLocaleRankForText($context);
                }
                if ($rank === null) {
                    continue;
                }
                self::mergeAlternateCandidate($byUrl, $href, $rank);
            }
        }

        self::mergeAlternateCandidatesFromPlain($plain, $byUrl);

        return array_values(array_map(
            static fn (array $c): array => ['url' => $c['url'], 'rank' => $c['rank']],
            $byUrl
        ));
    }

    /**
     * @param array<string, array{url: string, rank: int}> $byUrl
     */
    private static function mergeAlternateCandidate(array &$byUrl, string $url, int $rank): void
    {
        if (!isset($byUrl[$url]) || $rank < $byUrl[$url]['rank']) {
            $byUrl[$url] = ['url' => $url, 'rank' => $rank];
        }
    }

    /**
     * @param array<string, array{url: string, rank: int}> $byUrl
     */
    private static function mergeAlternateCandidatesFromPlain(string $plain, array &$byUrl): void
    {
        $plain = trim($plain);
        if ($plain === '') {
            return;
        }

        $lower = EmailWebViewPhraseLexicon::normalizeForMatch($plain);

        foreach (EmailWebViewPhraseLexicon::alternateLocaleEntries() as $entry) {
            $pos = mb_strpos($lower, $entry['phrase'], 0, 'UTF-8');
            if ($pos === false) {
                continue;
            }
            $slice = mb_substr($plain, (int)$pos, 2500, 'UTF-8');
            $url   = self::firstHttpUrl($slice);
            if ($url !== null) {
                self::mergeAlternateCandidate($byUrl, $url, $entry['rank']);
            }
        }

        foreach (EmailWebViewPhraseLexicon::alternateLocaleRegexSpecs() as $spec) {
            if (preg_match_all($spec['pattern'], $lower, $matches, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }
            $tokenList = $matches[1] ?? [];
            $matchList = $matches[0] ?? [];
            foreach ($matchList as $i => $match) {
                $token = (string)($tokenList[$i][0] ?? '');
                $rank  = $spec['ranks'][$token] ?? EmailWebViewPhraseLexicon::RANK_LOCALE_OTHER;
                $pos   = (int)($match[1] ?? 0);
                $slice = mb_substr($plain, $pos, 2500, 'UTF-8');
                $url   = self::firstHttpUrl($slice);
                if ($url !== null) {
                    self::mergeAlternateCandidate($byUrl, $url, $rank);
                }
            }
        }
    }

    /**
     * @param list<array{url: string, rank: int}> $candidates
     * @param list<int> $preferredLocaleRanks
     * @return array{url: string, rank: int}|null
     */
    public static function pickAlternateByRanks(array $candidates, array $preferredLocaleRanks): ?array
    {
        if ($candidates === []) {
            return null;
        }

        foreach ($preferredLocaleRanks as $wanted) {
            foreach ($candidates as $c) {
                if ($c['rank'] === $wanted) {
                    return $c;
                }
            }
        }

        $best = null;
        foreach ($candidates as $c) {
            if ($best === null || $c['rank'] < $best['rank']) {
                $best = $c;
            }
        }

        return $best;
    }

    private static function pressReleaseUrl(string $html, string $plain): ?string
    {
        $root = self::loadMailRoot($html);
        if ($root instanceof DOMElement) {
            $fromDom = self::firstPressReleaseArticleLinkFromDom($root);
            if ($fromDom !== null) {
                return $fromDom;
            }
        }

        foreach (
            [
                self::firstAdminChNewnsbUrl($plain),
                self::firstEuroparlPressRoomUrl($plain),
                self::firstPressReleaseUrlNearMarker($plain),
            ] as $press
        ) {
            if ($press !== null) {
                return $press;
            }
        }

        return null;
    }

    private static function genericWebViewUrl(string $html, string $plain): ?string
    {
        $root = self::loadMailRoot($html);
        if ($root instanceof DOMElement) {
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
        }

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

    private static function loadMailRoot(string $html): ?DOMElement
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

        return $root instanceof DOMElement ? $root : null;
    }

    private static function urlNearWebViewPhrase(string $text): ?string
    {
        return self::urlNearPhraseList($text, EmailWebViewPhraseLexicon::allPhrases());
    }

    /**
     * @param list<string> $phrases
     */
    private static function urlNearPhraseList(string $text, array $phrases): ?string
    {
        $lower   = EmailWebViewPhraseLexicon::normalizeForMatch($text);
        $bestPos = null;
        foreach ($phrases as $phrase) {
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
