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
    public static function resolve(string $html, string $plain, array $preferredLocaleRanks, array $customWebviewKeywords = [], array &$warnings = [], bool $prefersHydration = false): EmailWebViewResolution
    {
        $html  = trim($html);
        $plain = trim($plain);

        // 1. If custom keywords are supplied, try matching them first to respect user rules refinement
        if ($customWebviewKeywords !== []) {
            $generic = self::genericWebViewUrl($html, $plain, $customWebviewKeywords, true, $warnings);
            if ($generic !== null) {
                return new EmailWebViewResolution($generic, null, false, self::joinWarnings($warnings));
            }
        }

        $press = self::pressReleaseUrl($html, $plain);
        if ($press !== null) {
            $isEuComm = self::isEuCommissionPressCornerUrl($press);
            return new EmailWebViewResolution(
                $press,
                $isEuComm ? EmailWebViewPhraseLexicon::RANK_LOCALE_ENGLISH : null,
                $isEuComm,
                self::joinWarnings($warnings)
            );
        }

        $picked = self::pickAlternateByRanks(
            self::collectAlternateLocaleCandidates($html, $plain, true, $warnings),
            $preferredLocaleRanks
        );
        if ($picked !== null) {
            return new EmailWebViewResolution(
                $picked['url'],
                $picked['rank'],
                EmailAlternateLocalePolicy::shouldHydrateBodyFromWebView($picked['rank'], $prefersHydration),
                self::joinWarnings($warnings)
            );
        }

        $generic = self::genericWebViewUrl($html, $plain, $customWebviewKeywords, true, $warnings);

        return new EmailWebViewResolution($generic, null, false, self::joinWarnings($warnings));
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
    public static function collectAlternateLocaleCandidates(string $html, string $plain, bool $allowWebviewRedirects = true, array &$warnings = []): array
    {
        $byUrl = [];

        $root = self::loadMailRoot($html);
        if ($root instanceof DOMElement) {
            foreach ($root->getElementsByTagName('a') as $anchor) {
                if (!$anchor instanceof DOMElement) {
                    continue;
                }
                $rawHref = $anchor->getAttribute('href');
                $href = self::normalizeHref($rawHref, $allowWebviewRedirects);
                $label   = trim($anchor->textContent . ' ' . $anchor->getAttribute('title'));
                $context = self::anchorContextText($anchor);
                if ($href === null) {
                    self::checkAndLogDiscardedRedirect($rawHref, $label, $context, [], $warnings);
                    continue;
                }
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

        self::mergeAlternateCandidatesFromPlain($plain, $byUrl, $allowWebviewRedirects);

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
    private static function mergeAlternateCandidatesFromPlain(string $plain, array &$byUrl, bool $allowWebviewRedirects = true): void
    {
        $plain = trim($plain);
        if ($plain === '') {
            return;
        }

        $lower = EmailWebViewPhraseLexicon::normalizeForMatch($plain);

        foreach (EmailWebViewPhraseLexicon::alternateLocaleEntries() as $entry) {
            $url = self::firstHttpUrlAfterNormalizedPhrase($plain, $entry['phrase'], 2500, $allowWebviewRedirects);
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
                $url   = self::firstHttpUrl($slice, $allowWebviewRedirects);
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
                self::firstEuCommissionPressCornerUrl($plain),
                self::firstParlamentGvAtUrl($plain),
                self::firstParlamentChNewsUrl($plain),
                self::firstPressReleaseUrlNearMarker($plain),
            ] as $press
        ) {
            if ($press !== null) {
                return $press;
            }
        }

        return null;
    }

    private static function genericWebViewUrl(string $html, string $plain, array $customKeywords = [], bool $allowWebviewRedirects = true, array &$warnings = []): ?string
    {
        $root = self::loadMailRoot($html);
        if ($root instanceof DOMElement) {
            foreach ($root->getElementsByTagName('a') as $anchor) {
                if (!$anchor instanceof DOMElement) {
                    continue;
                }
                $rawHref = $anchor->getAttribute('href');
                $href = self::normalizeHref($rawHref, $allowWebviewRedirects);
                $label   = trim($anchor->textContent . ' ' . $anchor->getAttribute('title'));
                $context = self::anchorContextText($anchor);
                if ($href === null) {
                    self::checkAndLogDiscardedRedirect($rawHref, $label, $context, $customKeywords, $warnings);
                    continue;
                }

                $matchedCustom = false;
                if ($customKeywords !== []) {
                    $lowerLabel = mb_strtolower($label, 'UTF-8');
                    $lowerContext = mb_strtolower($context, 'UTF-8');
                    foreach ($customKeywords as $kw) {
                        $kwLower = mb_strtolower(trim((string)$kw), 'UTF-8');
                        if ($kwLower !== '' && (str_contains($lowerLabel, $kwLower) || str_contains($lowerContext, $kwLower))) {
                            $matchedCustom = true;
                            break;
                        }
                    }
                }

                if ($matchedCustom
                    || EmailWebViewPhraseLexicon::textLooksLikeWebView($label)
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

        if ($customKeywords !== []) {
            $nearCustom = self::urlNearPhraseList($plain, $customKeywords, $allowWebviewRedirects);
            if ($nearCustom !== null) {
                return $nearCustom;
            }
        }

        $near = self::urlNearWebViewPhrase($plain, $allowWebviewRedirects);
        if ($near !== null) {
            return $near;
        }

        foreach (preg_split("/\r\n|\n|\r/", $plain) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }

            $matchedCustomLine = false;
            if ($customKeywords !== []) {
                $lowerLine = mb_strtolower($line, 'UTF-8');
                foreach ($customKeywords as $kw) {
                    $kwLower = mb_strtolower(trim((string)$kw), 'UTF-8');
                    if ($kwLower !== '' && str_contains($lowerLine, $kwLower)) {
                        $matchedCustomLine = true;
                        break;
                    }
                }
            }

            if ($matchedCustomLine || EmailWebViewPhraseLexicon::textLooksLikeWebView($line)) {
                return self::firstHttpUrl($line, $allowWebviewRedirects);
            }
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

    private static function urlNearWebViewPhrase(string $text, bool $allowWebviewRedirects = false): ?string
    {
        return self::urlNearPhraseList($text, EmailWebViewPhraseLexicon::allPhrases(), $allowWebviewRedirects);
    }

    /**
     * @param list<string> $phrases
     */
    private static function urlNearPhraseList(string $text, array $phrases, bool $allowWebviewRedirects = false): ?string
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

        // Slice original text so URLs keep their casing (normalizeForMatch lowercases hosts/paths).
        $slice = mb_substr($text, (int)$bestPos, 2500, 'UTF-8');

        return self::firstHttpUrl($slice, $allowWebviewRedirects);
    }

    private static function anchorContextText(DOMElement $anchor): string
    {
        $parent = $anchor->parentNode;
        if ($parent instanceof DOMElement) {
            $text = trim($parent->textContent ?? '');
            if (mb_strlen($text, 'UTF-8') < 50) {
                $grandparent = $parent->parentNode;
                if ($grandparent instanceof DOMElement) {
                    $gTag = strtolower($grandparent->tagName);
                    if ($gTag === 'tr' || $gTag === 'tbody' || $gTag === 'table') {
                        $gText = preg_replace('/\s+/u', ' ', trim($grandparent->textContent ?? '')) ?? '';
                        if ($gText !== '') {
                            return $gText;
                        }
                    }
                }
            }
            return $text;
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
            || str_contains($bodyNorm, 'press corner')
            || str_contains($bodyNorm, 'parlamentskorrespondenz')
            || (str_contains($bodyNorm, 'press service') && str_contains($bodyNorm, 'european parliament'));

        $headlineFallback = null;
        foreach ($root->getElementsByTagName('a') as $anchor) {
            if (!$anchor instanceof DOMElement) {
                continue;
            }
            $href = self::normalizeHref($anchor->getAttribute('href'));
            if ($href === null || EmailTrackingUrl::isRedirectTrackingUrl($href)) {
                continue;
            }
            if (self::isAdminChNewnsbUrl($href) || self::isEuroparlPressRoomUrl($href) || self::isEuCommissionPressCornerUrl($href) || self::isParlamentGvAtUrl($href) || self::isParlamentChNewsUrl($href)) {
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
            if ($url !== null && !EmailTrackingUrl::isRedirectTrackingUrl($url)) {
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
                && !EmailTrackingUrl::isRedirectTrackingUrl($url)
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

        $slice = mb_substr($lower, (int)$bestPos, 3000, 'UTF-8');

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

    private static function isEuCommissionPressCornerUrl(string $url): bool
    {
        return preg_match(
            '#^https?://ec\.europa\.eu/commission/presscorner/detail/[a-z]{2}/[a-z0-9_-]+#i',
            $url
        ) === 1;
    }

    private static function firstEuCommissionPressCornerUrl(string $text): ?string
    {
        if (preg_match_all(
            '#https?://ec\.europa\.eu/commission/presscorner/detail/[a-z]{2}/[a-z0-9_-]+#iu',
            $text,
            $matches
        ) === false) {
            return null;
        }
        foreach ($matches[0] as $raw) {
            $url = self::normalizeHref((string)$raw);
            if ($url !== null && !EmailTrackingUrl::isRedirectTrackingUrl($url)) {
                return $url;
            }
        }

        return null;
    }

    private static function isParlamentChNewsUrl(string $url): bool
    {
        return preg_match(
            '#^https?://(?:[a-z0-9.-]+\.)?parlament\.ch/[^\s<>"\'\]]*/news/[^\s<>"\'\]]+#i',
            $url
        ) === 1;
    }

    private static function firstParlamentChNewsUrl(string $text): ?string
    {
        if (preg_match_all(
            '#https?://(?:[a-z0-9.-]+\.)?parlament\.ch/[^\s<>"\'\]]*/news/[^\s<>"\'\]]+#iu',
            $text,
            $matches
        ) === false) {
            return null;
        }
        foreach ($matches[0] as $raw) {
            $url = self::normalizeHref((string)$raw);
            if ($url !== null && !EmailTrackingUrl::isRedirectTrackingUrl($url)) {
                return $url;
            }
        }

        return null;
    }

    private static function isParlamentGvAtUrl(string $url): bool
    {
        return preg_match(
            '#^https?://(?:www\.)?parlament\.gv\.at/[^\s<>"\'\]]*/pk[^\s<>"\'\]]+#i',
            $url
        ) === 1;
    }

    private static function firstParlamentGvAtUrl(string $text): ?string
    {
        if (preg_match_all(
            '#https?://(?:www\.)?parlament\.gv\.at/[^\s<>"\'\]]*/pk[^\s<>"\'\]]+#iu',
            $text,
            $matches
        ) === false) {
            return null;
        }
        foreach ($matches[0] as $raw) {
            $url = self::normalizeHref((string)$raw);
            if ($url !== null && !EmailTrackingUrl::isRedirectTrackingUrl($url)) {
                return $url;
            }
        }

        return null;
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
        if (str_contains($host, 'ec.europa.eu')) {
            return self::isEuCommissionPressCornerUrl($href);
        }
        if (str_contains($host, 'parlament.gv.at')) {
            return self::isParlamentGvAtUrl($href);
        }
        if (str_contains($host, 'parlament.ch')) {
            return self::isParlamentChNewsUrl($href);
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

    private static function firstHttpUrl(string $text, bool $allowWebviewRedirects = false): ?string
    {
        if (preg_match_all('#<(\s*https?://[^>\s]+)\s*>#iu', $text, $bracketed, PREG_SET_ORDER) !== false) {
            foreach ($bracketed as $m) {
                $url = self::normalizeHref((string)($m[1] ?? ''), $allowWebviewRedirects);
                if ($url !== null) {
                    return $url;
                }
            }
        }

        if (preg_match_all('#https?://[^\s<>"\'\]]+#iu', $text, $matches) === false) {
            return null;
        }
        foreach ($matches[0] as $raw) {
            $url = self::normalizeHref(rtrim((string)$raw, '.,;)]'), $allowWebviewRedirects);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    private static function firstHttpUrlAfterNormalizedPhrase(string $plain, string $phrase, int $len = 2500, bool $allowWebviewRedirects = false): ?string
    {
        $normalized = EmailWebViewPhraseLexicon::normalizeForMatch($plain);
        $pos = mb_strpos($normalized, $phrase, 0, 'UTF-8');
        if ($pos === false) {
            return null;
        }

        $slice = mb_substr($plain, (int)$pos, $len, 'UTF-8');

        return self::firstHttpUrl($slice, $allowWebviewRedirects);
    }

    private static function normalizeHref(string $href, bool $allowWebviewRedirects = false): ?string
    {
        $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $href = ltrim($href, '[(');
        $href = rtrim($href, '].,;)');
        
        $lower = strtolower($href);
        if ($href === ''
            || str_starts_with($lower, 'mailto:')
            || str_starts_with($lower, 'tel:')
            || str_starts_with($lower, 'javascript:')
            || str_contains($lower, 'mailto:')
            || str_contains($lower, 'destination=mailto:')
            || str_contains($lower, 'x.com')
            || str_contains($lower, 'twitter.com')
            || str_contains($lower, 'facebook.com')
            || str_contains($lower, 'linkedin.com')
            || str_contains($lower, 'instagram.com')
            || str_contains($lower, 'youtube.com')
            || str_contains($lower, 'bsky.app')
            || str_contains($lower, 'bsky.social')
        ) {
            return null;
        }
        if (!preg_match('#^https?://#i', $href)) {
            return null;
        }

        if (EmailTrackingUrl::isRedirectTrackingUrl($href)) {
            if ($allowWebviewRedirects && EmailTrackingUrl::isAllowedWebviewRedirectUrl($href)) {
                return EmailTrackingUrl::cleanNewsletterHref($href);
            }
            return null;
        }

        return EmailTrackingUrl::cleanNewsletterHref($href);
    }

    /**
     * Scan discarded tracking redirects; log a warning if the link text looks like a webview
     * version, alerting the administrator to add the domain to the whitelist.
     */
    private static function checkAndLogDiscardedRedirect(string $rawHref, string $label, string $context, array $customKeywords = [], array &$warnings = []): void
    {
        if (EmailTrackingUrl::isRedirectTrackingUrl($rawHref) && !EmailTrackingUrl::isAllowedWebviewRedirectUrl($rawHref)) {
            $matchedCustom = false;
            if ($customKeywords !== []) {
                $lowerLabel = mb_strtolower($label, 'UTF-8');
                $lowerContext = mb_strtolower($context, 'UTF-8');
                foreach ($customKeywords as $kw) {
                    $kwLower = mb_strtolower(trim((string)$kw), 'UTF-8');
                    if ($kwLower !== '' && (str_contains($lowerLabel, $kwLower) || str_contains($lowerContext, $kwLower))) {
                        $matchedCustom = true;
                        break;
                    }
                }
            }

            if ($matchedCustom
                || EmailWebViewPhraseLexicon::textLooksLikeWebView($label)
                || EmailWebViewPhraseLexicon::textLooksLikeWebView($context)
                || EmailWebViewPhraseLexicon::shortAnchorInWebViewContext($label, $context)
                || EmailWebViewPhraseLexicon::textLooksLikeAlternateLocaleVersion($label)
                || EmailWebViewPhraseLexicon::textLooksLikeAlternateLocaleVersion($context)
            ) {
                $host = parse_url($rawHref, PHP_URL_HOST) ?? $rawHref;
                $msg = "Discarded potential webview link containing non-whitelisted tracking domain '{$host}'";
                $warnings[] = $msg;
                error_log("Seismo warning: {$msg} with label: '{$label}'");
            }
        }
    }

    private static function joinWarnings(array $warnings): ?string
    {
        if ($warnings === []) {
            return null;
        }
        return implode('; ', array_unique($warnings));
    }
}
