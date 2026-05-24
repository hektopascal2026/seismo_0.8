<?php

declare(strict_types=1);

namespace Seismo\Core\Fetcher;

/**
 * Collect article body text from public publisher HTML using several
 * machine-readable layers, then return the strongest candidate.
 *
 * Used by {@see RssArticleHydrator} for Media / thin-RSS hydration.
 */
final class ArticlePageBodyExtractor
{
    private const MIN_CANDIDATE_PLAIN = 50;

    /**
     * Per-host DOM selectors stripped before Readability during RSS hydration.
     * Keys are lowercase hostnames (with or without leading www.).
     *
     * @var array<string, string>
     */
    private const HOST_EXCLUDE_SELECTORS = [
        'golem.de' => <<<'SEL'
header
nav
.go-toolbar
.go-teaser-block
.go-ad-slot
footer
aside
SEL,
        'srf.ch' => <<<'SEL'
header
nav
footer
aside
.sharing-bar
.articlepage__sharing-bar
.expandable-box
.collection__teaser-list
.js-teaser-list
.media-caption
.image-figure
.articlepage__breadcrumbs
.articlepage__topmedia
.article-reference
.article-author
.articlepage__banner-container
.button--share
.modal-flyout
SEL,
    ];

    /** Tie-break when two candidates have equal plain-text length. */
    private const SOURCE_PRIORITY = [
        'json_ld'          => 4,
        'readability'      => 3,
        'og_description'   => 2,
        'meta_description' => 1,
    ];

    /**
     * Swiss/German subscription publishers: RSS {@code description} is usually the
     * only anonymous text; article HTML is paywalled for bots.
     *
     * @var list<string>
     */
    private const PAYWALLED_PUBLISHER_HOST_SUFFIXES = [
        'tagesanzeiger.ch',
        'derbund.ch',
        'bazonline.ch',
        '24heures.ch',
        'bilan.ch',
    ];

    /** Below {@see RssArticleHydrator::MIN_PLAIN_CHARS} but enough for timeline cards. */
    public const PAYWALL_TEASER_MIN_PLAIN = 120;

    /** Minimum plain length to treat a paywalled page fetch as successful (lead + quote + «In Kürze»). */
    public const PAYWALL_PREVIEW_MIN_PLAIN = 200;

    private const ARTICLE_JSON_LD_TYPES = [
        'NewsArticle',
        'Article',
        'BlogPosting',
        'ReportageNewsArticle',
        'AnalysisNewsArticle',
        'OpinionNewsArticle',
        'ReviewNewsArticle',
        'WebPage',
    ];

    /**
     * @param string $excludeSelectors Passed through to {@see ScraperContentExtractor}.
     */
    public static function excludeSelectorsForHost(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return '';
        }

        if (isset(self::HOST_EXCLUDE_SELECTORS[$host])) {
            return self::HOST_EXCLUDE_SELECTORS[$host];
        }

        if (str_starts_with($host, 'www.')) {
            $bare = substr($host, 4);

            return self::HOST_EXCLUDE_SELECTORS[$bare] ?? '';
        }

        return self::HOST_EXCLUDE_SELECTORS['www.' . $host] ?? '';
    }

    /**
     * True when the fetched HTML/URL looks like a cookie or CMP interstitial,
     * not a publisher article page.
     */
    public static function looksLikeConsentWall(string $html, string $url = ''): bool
    {
        $urlLower = strtolower(trim($url));
        if ($urlLower !== '' && preg_match(
            '#/(zustimmung|consent|cookie-consent|cookie_choice|gdpr-consent|cookie-notice)(/|$|\?)#i',
            $urlLower
        )) {
            return true;
        }

        if ($html === '') {
            return false;
        }

        if (str_contains($html, 'GolemConsent')
            || (str_contains($html, 'cmp-cdn.golem.de') && preg_match('#Cookies zustimmen#ui', $html))) {
            return true;
        }

        $hasNoindex = (bool)preg_match(
            '#<meta[^>]+name\s*=\s*(["\'])robots\1[^>]+content\s*=\s*(["\'])[^"\']*noindex#i',
            $html
        );
        if (!$hasNoindex) {
            return false;
        }

        $consentPhrases = 0;
        foreach ([
            'Cookies zustimmen',
            'Cookie-Einstellungen',
            'Alle Cookies akzeptieren',
            'Accept all cookies',
            'Privacy Center',
            'Willkommen auf Golem.de',
        ] as $phrase) {
            if (preg_match('#' . preg_quote($phrase, '#') . '#ui', $html)) {
                $consentPhrases++;
            }
        }

        return $consentPhrases >= 2;
    }

    /**
     * Safety net after extraction — rejects bodies that are still CMP boilerplate.
     */
    public static function isPaywalledPublisherUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && self::isPaywalledPublisherHost($host);
    }

    public static function isPaywalledPublisherHost(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return false;
        }
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        foreach (self::PAYWALLED_PUBLISHER_HOST_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rejects login/paywall chrome that Readability sometimes scores as "content".
     */
    public static function looksLikePaywallBody(string $plain): bool
    {
        $plain = mb_strtolower(trim($plain), 'UTF-8');
        if ($plain === '') {
            return false;
        }

        $snippet = mb_substr($plain, 0, 1200, 'UTF-8');
        $hits    = 0;
        foreach ([
            'kein aktives abo',
            'unterstützen sie qualitätsjournalismus',
            'abo abschliessen',
            'sie haben kein aktives abo',
            'zum aboshop',
            'melden sie sich an',
            'jetzt abonnieren',
        ] as $needle) {
            if (str_contains($snippet, $needle)) {
                $hits++;
            }
        }

        return $hits >= 2;
    }

    public static function looksLikeConsentBody(string $plain): bool
    {
        $plain = mb_strtolower(trim($plain), 'UTF-8');
        if ($plain === '') {
            return false;
        }

        $snippet = mb_substr($plain, 0, 800, 'UTF-8');
        $hits    = 0;
        foreach ([
            'cookies zustimmen',
            'nutzung aller cookies',
            'cookie-einstellungen',
            'accept all cookies',
            'alle cookies akzeptieren',
            'privacy center',
            'willkommen auf golem.de',
            'golem pur bestellen',
            'zustimmungs-dialog',
        ] as $needle) {
            if (str_contains($snippet, $needle)) {
                $hits++;
            }
        }

        return $hits >= 2;
    }

    /**
     * Public preview on paywalled Tamedia article pages (what Safari Reader shows):
     * kicker, pull quote, lead paragraph, and «In Kürze» bullets from embedded JSON.
     */
    public static function extractPaywalledPublisherPreview(string $html): string
    {
        if (!self::isTamediaArticleHtml($html)) {
            return '';
        }

        $embedded = self::extractTamediaEmbeddedArticleFields($html);
        $parts    = [];

        if ($embedded['titleHeader'] !== '') {
            $parts[] = $embedded['titleHeader'];
        }
        if ($embedded['title'] !== '' && !self::textsAreNearDuplicate($embedded['title'], $embedded['lead'])) {
            $parts[] = $embedded['title'];
        }
        if ($embedded['lead'] !== '') {
            $parts[] = $embedded['lead'];
        }

        $summary = self::extractTamediaSummaryListData($html);
        if ($summary !== '') {
            $parts[] = $summary;
        }

        if ($parts === []) {
            $fallback = trim(self::extractJsonLdArticleBody($html));
            if ($fallback !== '') {
                $parts[] = $fallback;
            }
        }

        return self::mergeUniquePreviewParts($parts);
    }

    private static function isTamediaArticleHtml(string $html): bool
    {
        return str_contains($html, 'summary-list-data')
            || str_contains($html, '"tenantKey":"tagesanzeiger"')
            || str_contains($html, 'partner-feeds.publishing.tamedia.ch');
    }

    /**
     * @return array{title: string, titleHeader: string, lead: string}
     */
    private static function extractTamediaEmbeddedArticleFields(string $html): array
    {
        $best = ['title' => '', 'titleHeader' => '', 'lead' => ''];

        if (!preg_match_all('#<script[^>]+type=(["\'])application/json\1[^>]*>(.*?)</script>#is', $html, $matches)) {
            return $best;
        }

        foreach ($matches[2] as $raw) {
            $raw = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($raw === '') {
                continue;
            }

            try {
                $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (!is_array($data)) {
                continue;
            }

            if (isset($data['lead']) && is_string($data['lead'])) {
                $lead = trim($data['lead']);
                if (mb_strlen($lead, 'UTF-8') > mb_strlen($best['lead'], 'UTF-8')) {
                    $best['lead'] = $lead;
                }
            }
            if (isset($data['title']) && is_string($data['title'])) {
                $title = trim($data['title']);
                if (mb_strlen($title, 'UTF-8') > mb_strlen($best['title'], 'UTF-8')) {
                    $best['title'] = $title;
                }
            }
            if (isset($data['titleHeader']) && is_string($data['titleHeader'])) {
                $header = trim($data['titleHeader']);
                if ($header !== '') {
                    $best['titleHeader'] = $header;
                }
            }
        }

        return $best;
    }

    public static function extractTamediaSummaryListData(string $html): string
    {
        if (!preg_match('#<script id="summary-list-data"[^>]*>(.*?)</script>#s', $html, $match)) {
            return '';
        }

        try {
            $blocks = json_decode(trim($match[1]), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }

        if (!is_array($blocks)) {
            return '';
        }

        $lines = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $items = $block['items'] ?? null;
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $text = trim((string)($item['htmlText'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $text = self::toPlainText($text);
                if ($text !== '') {
                    $lines[] = $text;
                }
            }
        }

        if ($lines === []) {
            return '';
        }

        return "In Kürze:\n" . implode("\n", array_map(static fn (string $line): string => '• ' . $line, $lines));
    }

    /**
     * @param list<string> $parts
     */
    private static function mergeUniquePreviewParts(array $parts): string
    {
        $out   = [];
        $seen  = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $key = mb_strtolower(preg_replace('/\s+/u', ' ', $part) ?? $part, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[]      = $part;
        }

        return implode("\n\n", $out);
    }

    private static function textsAreNearDuplicate(string $a, string $b): bool
    {
        $a = mb_strtolower(trim($a), 'UTF-8');
        $b = mb_strtolower(trim($b), 'UTF-8');
        if ($a === '' || $b === '') {
            return false;
        }

        return $a === $b || str_contains($a, $b) || str_contains($b, $a);
    }

    public static function extractBestArticleBody(string $html, string $excludeSelectors = ''): string
    {
        if (self::isTamediaArticleHtml($html)) {
            $preview = self::extractPaywalledPublisherPreview($html);
            if (mb_strlen(self::toPlainText($preview), 'UTF-8') >= self::PAYWALL_PREVIEW_MIN_PLAIN) {
                return $preview;
            }
        }

        $candidates = [];

        $jsonLd = self::extractJsonLdArticleBody($html);
        if ($jsonLd !== '') {
            $candidates[] = ['source' => 'json_ld', 'content' => $jsonLd];
        }

        $read = ScraperContentExtractor::extractReadableContent($html, $excludeSelectors);
        $readable = trim($read['content'] ?? '');
        if ($readable !== '') {
            $candidates[] = ['source' => 'readability', 'content' => $readable];
        }

        $og = self::extractMetaContent($html, 'property', 'og:description');
        if ($og !== '') {
            $candidates[] = ['source' => 'og_description', 'content' => $og];
        }

        $meta = self::extractMetaContent($html, 'name', 'description');
        if ($meta !== '') {
            $candidates[] = ['source' => 'meta_description', 'content' => $meta];
        }

        $best = self::pickBestCandidate($candidates);
        if ($best !== '' && self::isSrfArticleHtml($html)) {
            $best = self::normalizeSrfArticlePlainText(self::toPlainText($best));
        }

        return $best;
    }

    private static function isSrfArticleHtml(string $html): bool
    {
        return str_contains($html, 'articlepage__article')
            || str_contains($html, 'class="articlepage"');
    }

    /**
     * Strip share chrome, expandable author/info boxes, and broadcast footers
     * that Readability sometimes keeps on srf.ch Q&A pages.
     */
    public static function normalizeSrfArticlePlainText(string $plain): string
    {
        $plain = trim(preg_replace("/\r\n?/", "\n", $plain) ?? $plain);
        if ($plain === '') {
            return '';
        }

        $lines = explode("\n", $plain);
        $out   = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (self::isSrfUiNoiseLine($line)) {
                continue;
            }
            $out[] = $line;
        }

        while ($out !== [] && self::isSrfLeadingShareLine($out[0])) {
            array_shift($out);
        }

        while ($out !== [] && self::isSrfTrailingBroadcastLine($out[count($out) - 1])) {
            array_pop($out);
        }

        return trim(implode("\n\n", $out));
    }

    private static function isSrfUiNoiseLine(string $line): bool
    {
        static $exact = [
            'Teilen',
            'Schliessen',
            'Facebook',
            'Bluesky',
            'LinkedIn',
            'WhatsApp',
            'E-Mail',
            'Link kopieren',
            'Legende:',
        ];
        if (in_array($line, $exact, true)) {
            return true;
        }

        return preg_match(
            '#^(Personen-Box|Box)\s+(aufklappen|zuklappen)$#ui',
            $line
        ) === 1
            || preg_match('#^Auf (Facebook|Bluesky|LinkedIn|X|WhatsApp) teilen$#ui', $line) === 1
            || preg_match('#^Per E-Mail teilen$#ui', $line) === 1
            || preg_match('#^Hier finden Sie weitere Artikel von .+ und Informationen zu (?:seiner|ihrer) Person\.?$#ui', $line) === 1;
    }

    private static function isSrfLeadingShareLine(string $line): bool
    {
        return in_array($line, ['Teilen', 'Schliessen', 'Facebook', 'Bluesky', 'LinkedIn', 'X', 'WhatsApp', 'E-Mail', 'Link kopieren'], true)
            || preg_match('#^Auf (Facebook|Bluesky|LinkedIn|X|WhatsApp) teilen$#ui', $line) === 1
            || preg_match('#^Per E-Mail teilen$#ui', $line) === 1;
    }

    private static function isSrfTrailingBroadcastLine(string $line): bool
    {
        return preg_match('#^Echo der Zeit,\s+\d{2}\.\d{2}\.\d{4}#ui', $line) === 1
            || preg_match('#^;?\s*srf/[a-z]+;#ui', $line) === 1;
    }

    /**
     * @param list<array{source: string, content: string}> $candidates
     */
    public static function pickBestCandidate(array $candidates): string
    {
        $best     = '';
        $bestLen  = 0;
        $bestPri  = 0;

        foreach ($candidates as $candidate) {
            $content = trim($candidate['content'] ?? '');
            if ($content === '') {
                continue;
            }

            $plainLen = mb_strlen(self::toPlainText($content), 'UTF-8');
            if ($plainLen < self::MIN_CANDIDATE_PLAIN) {
                continue;
            }

            $priority = self::SOURCE_PRIORITY[$candidate['source'] ?? ''] ?? 0;
            if ($plainLen > $bestLen || ($plainLen === $bestLen && $priority > $bestPri)) {
                $best    = $content;
                $bestLen = $plainLen;
                $bestPri = $priority;
            }
        }

        return $best;
    }

    public static function extractJsonLdArticleBody(string $html): string
    {
        if (!preg_match_all(
            '#<script[^>]+type\s*=\s*(["\'])application/ld\+json\1[^>]*>(.*?)</script>#is',
            $html,
            $matches
        )) {
            return '';
        }

        $best    = '';
        $bestLen = 0;

        foreach ($matches[2] as $rawJson) {
            $rawJson = trim(html_entity_decode($rawJson, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($rawJson === '') {
                continue;
            }

            try {
                $data = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            self::walkJsonLd($data, static function (array $node) use (&$best, &$bestLen): void {
                if (!self::isArticleJsonLdNode($node)) {
                    return;
                }

                foreach (['articleBody', 'text'] as $field) {
                    if (!isset($node[$field]) || !is_string($node[$field])) {
                        continue;
                    }
                    $body = trim($node[$field]);
                    if ($body === '') {
                        continue;
                    }
                    $len = mb_strlen(self::toPlainText($body), 'UTF-8');
                    if ($len > $bestLen) {
                        $best    = $body;
                        $bestLen = $len;
                    }
                }
            });
        }

        return $best;
    }

    private static function walkJsonLd(mixed $node, callable $visit): void
    {
        if (!is_array($node)) {
            return;
        }

        if (array_is_list($node)) {
            foreach ($node as $item) {
                self::walkJsonLd($item, $visit);
            }

            return;
        }

        if (isset($node['@graph']) && is_array($node['@graph'])) {
            self::walkJsonLd($node['@graph'], $visit);
        }

        if (isset($node['@type']) && is_array($node)) {
            $visit($node);
        }

        foreach (['mainEntity', 'mainEntityOfPage', 'hasPart', 'associatedMedia'] as $nestedKey) {
            if (!isset($node[$nestedKey])) {
                continue;
            }
            self::walkJsonLd($node[$nestedKey], $visit);
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function isArticleJsonLdNode(array $node): bool
    {
        $type = $node['@type'] ?? null;
        if ($type === null) {
            return isset($node['articleBody']) || isset($node['text']);
        }

        $types = is_array($type) ? $type : [$type];
        foreach ($types as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $local = preg_replace('#^https?://(www\.)?schema\.org/#i', '', $candidate) ?? $candidate;
            if (in_array($local, self::ARTICLE_JSON_LD_TYPES, true)) {
                if ($local === 'WebPage' && !isset($node['articleBody']) && !isset($node['text'])) {
                    continue;
                }

                return true;
            }
        }

        return isset($node['articleBody']) || isset($node['text']);
    }

    private static function extractMetaContent(string $html, string $attr, string $name): string
    {
        $pattern = '#<meta[^>]+' . preg_quote($attr, '#') . '\s*=\s*(["\'])'
            . preg_quote($name, '#') . '\1[^>]+content\s*=\s*(["\'])(.*?)\2#is';
        if (!preg_match($pattern, $html, $m)) {
            $pattern = '#<meta[^>]+content\s*=\s*(["\'])(.*?)\1[^>]+' . preg_quote($attr, '#')
                . '\s*=\s*(["\'])' . preg_quote($name, '#') . '\3#is';
            if (!preg_match($pattern, $html, $m)) {
                return '';
            }

            return trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return trim(html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public static function toPlainText(string $html): string
    {
        if ($html === '') {
            return '';
        }

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
