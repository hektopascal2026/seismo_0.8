<?php

declare(strict_types=1);

namespace Seismo\Core\Lex;

use Seismo\Service\Http\BaseClient;
use Seismo\Service\Http\HttpClientException;

/**
 * Fetch plain-text corpus for EU acts via Publications Office CELLAR (primary)
 * and EUR-Lex HTML pages as a legacy fallback.
 *
 * EUR-Lex now serves AWS WAF bot challenges to non-browser clients; CELLAR
 * content negotiation returns the same XHTML without that gate.
 */
final class LexEurLexContentFetcher
{
    /** Cap stored corpus size — EU directives can exceed 1 MB of plain text. */
    public const MAX_CONTENT_BYTES = 1_048_576;

    /**
     * EUR-Lex UI path language (EN, DE, …) → Cellar {@code ?language=} authority code.
     *
     * @var array<string, string>
     */
    private const CELLAR_LANG_BY_EURLEX_PATH = [
        'BG' => 'bul', 'CS' => 'ces', 'DA' => 'dan', 'DE' => 'deu', 'ET' => 'est',
        'EL' => 'ell', 'EN' => 'eng', 'FI' => 'fin', 'FR' => 'fra', 'GA' => 'gle',
        'HR' => 'hrv', 'HU' => 'hun', 'IT' => 'ita', 'LV' => 'lav', 'LT' => 'lit',
        'MT' => 'mlt', 'NL' => 'nld', 'PL' => 'pol', 'PT' => 'por', 'RO' => 'ron',
        'SK' => 'slk', 'SL' => 'slv', 'SV' => 'swe', 'RM' => 'roh', 'ES' => 'spa',
    ];

    public function __construct(
        private BaseClient $http = new BaseClient(45),
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function attachContentToRows(array $rows, int $limit): array
    {
        $limit = max(0, $limit);
        if ($limit === 0 || $rows === []) {
            return $rows;
        }

        $fetched = 0;
        foreach ($rows as &$row) {
            if ($fetched >= $limit) {
                break;
            }
            $content = $this->fetchPlainTextFromRow($row);
            if ($content === null) {
                continue;
            }
            $row['content'] = $content;
            $fetched++;
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function fetchPlainTextFromRow(array $row): ?string
    {
        $celex = strtoupper(trim((string)($row['celex'] ?? '')));
        $url   = trim((string)($row['eurlex_url'] ?? ''));
        $parsed = $url !== '' ? self::parseEurLexUrl($url) : null;

        if ($celex !== '') {
            $pathLang = $parsed['path_lang'] ?? 'EN';

            return $this->fetchPlainTextForCelex($celex, $pathLang);
        }
        if ($url !== '') {
            return $this->fetchPlainTextFromUrl($url);
        }

        return null;
    }

    public function fetchPlainTextFromUrl(string $eurlexUrl): ?string
    {
        $url = trim($eurlexUrl);
        if ($url === '' || !preg_match('#^https://#i', $url)) {
            return null;
        }

        $parsed = self::parseEurLexUrl($url);
        if ($parsed !== null) {
            return $this->fetchPlainTextForCelex($parsed['celex'], $parsed['path_lang']);
        }

        return $this->fetchPlainTextFromEurLexHtml($url);
    }

    public function fetchPlainTextForCelex(string $celexId, string $eurLexPathLang = 'EN'): ?string
    {
        $celex = strtoupper(trim($celexId));
        if ($celex === '' || strlen($celex) > 64) {
            return null;
        }

        $cellar = $this->fetchPlainTextFromCellar($celex, $eurLexPathLang);
        if ($cellar !== null) {
            return $cellar;
        }

        $langPath = strtoupper(trim($eurLexPathLang));
        if ($langPath === '') {
            $langPath = 'EN';
        }
        $eurlexUrl = 'https://eur-lex.europa.eu/legal-content/' . $langPath
            . '/TXT/HTML/?uri=CELEX:' . rawurlencode($celex);

        return $this->fetchPlainTextFromEurLexHtml($eurlexUrl);
    }

    /**
     * @return array{celex: string, path_lang: string}|null
     */
    public static function parseEurLexUrl(string $url): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (!preg_match(
            '#^https://eur-lex\.europa\.eu/legal-content/([A-Za-z]{2})/#i',
            $url,
            $langMatch,
        )) {
            return null;
        }
        if (!preg_match('/(?:\?|&)uri=CELEX:([^&]+)/i', $url, $celexMatch)) {
            return null;
        }
        $celex = strtoupper(rawurldecode($celexMatch[1]));
        if ($celex === '' || strlen($celex) > 64) {
            return null;
        }

        return [
            'celex'     => $celex,
            'path_lang' => strtoupper($langMatch[1]),
        ];
    }

    public static function isAwsWafChallengePage(string $html): bool
    {
        if ($html === '') {
            return false;
        }
        if (str_contains($html, 'awsWafCookieDomainList') || str_contains($html, 'AwsWafIntegration')) {
            return true;
        }

        return str_contains($html, 'awswaf.com')
            && (str_contains($html, 'challenge-container') || str_contains($html, 'verify that you\'re not a robot'));
    }

    public function plainTextFromHtml(string $html): ?string
    {
        if (trim($html) === '' || self::isAwsWafChallengePage($html)) {
            return null;
        }

        $fragment = $this->extractMainContentHtml($html);
        $fragment = $this->trimHtmlFromOperativeStart($fragment !== '' ? $fragment : $html);
        $plain = LexPlainText::fromHtml($fragment);
        $plain = self::stripCoverPageFromPlainText($plain);
        if ($plain === '') {
            return null;
        }
        if (strlen($plain) > self::MAX_CONTENT_BYTES) {
            $plain = substr($plain, 0, self::MAX_CONTENT_BYTES) . "\n\n[truncated]";
        }

        return $plain;
    }

    /**
     * Drop Cellar / EUR-Lex cover sheets (institution letterhead, COM reference, duplicate titles).
     * Corpus should start at the operative preamble (Having regard / Whereas / first recital).
     */
    public static function stripCoverPageFromPlainText(string $plain): string
    {
        $plain = trim($plain);
        if ($plain === '') {
            return '';
        }

        $patterns = [
            '/(?:\n\s*|^)Having regard to\b/ui',
            '/(?:\n\s*|^)Whereas:\s*(?:\n|$)/ui',
            '/(?:\n\s*|^)\(1\)[A-Za-zÀ-ÿ]/u',
            '/(?:\n\s*|^)Article\s+1\b/ui',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $plain, $m, PREG_OFFSET_CAPTURE)) {
                $trimmed = trim(mb_substr($plain, $m[0][1] + 1));
                if ($trimmed !== '') {
                    return $trimmed;
                }
            }
        }

        return $plain;
    }

    private function fetchPlainTextFromCellar(string $celex, string $eurLexPathLang): ?string
    {
        $pathLang = strtoupper(trim($eurLexPathLang));
        $cellarLang = self::CELLAR_LANG_BY_EURLEX_PATH[$pathLang] ?? 'eng';
        $url = 'https://publications.europa.eu/resource/celex/'
            . rawurlencode($celex)
            . '?language=' . $cellarLang;

        try {
            $resp = $this->http->get($url, [
                'Accept'          => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => $cellarLang,
            ]);
        } catch (HttpClientException) {
            return null;
        }
        if (!$resp->isOk() || $resp->body === '') {
            return null;
        }

        return $this->plainTextFromHtml($resp->body);
    }

    private function fetchPlainTextFromEurLexHtml(string $url): ?string
    {
        try {
            $resp = $this->http->getWebPage($url);
        } catch (HttpClientException) {
            return null;
        }
        if (!$resp->isOk() || $resp->body === '') {
            return null;
        }

        return $this->plainTextFromHtml($resp->body);
    }

    private function extractMainContentHtml(string $html): string
    {
        $prev = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            if (@$dom->loadHTML($html) === false) {
                return '';
            }
            $xpath = new \DOMXPath($dom);

            $queries = [
                "//*[contains(concat(' ', normalize-space(@class), ' '), ' eli-container ')]",
                "//*[contains(concat(' ', normalize-space(@class), ' '), ' contentWrapper ')]",
                "//*[@class='content' or contains(concat(' ', normalize-space(@class), ' '), ' content ')"
                . " and not(contains(concat(' ', normalize-space(@class), ' '), ' contentWrapper '))]",
            ];
            foreach ($queries as $query) {
                $nodes = $xpath->query($query);
                if ($nodes === false || $nodes->length === 0) {
                    continue;
                }
                $chunk = '';
                foreach ($nodes as $node) {
                    $chunk .= $dom->saveHTML($node) ?: '';
                }
                if ($chunk !== '') {
                    return $chunk;
                }
            }

            return '';
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    /**
     * Remove cover-page nodes from Cellar COM-style XHTML before plain-text conversion.
     */
    private function trimHtmlFromOperativeStart(string $html): string
    {
        $prev = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            if (@$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR) === false) {
                return $html;
            }
            $xpath = new \DOMXPath($dom);
            $contentNodes = $xpath->query(
                "//*[@class='content' or contains(concat(' ', normalize-space(@class), ' '), ' content ')"
                . " and not(contains(concat(' ', normalize-space(@class), ' '), ' contentWrapper '))]",
            );
            if ($contentNodes === false || $contentNodes->length === 0) {
                return $html;
            }
            $content = $contentNodes->item(0);
            if (!$content instanceof \DOMElement) {
                return $html;
            }

            $startQueries = [
                ".//p[contains(normalize-space(.), 'Having regard to')][1]",
                ".//p[contains(normalize-space(.), 'Whereas:')][1]",
                ".//*[contains(concat(' ', normalize-space(@class), ' '), ' ManualConsidrant ')][1]",
                ".//*[contains(concat(' ', normalize-space(@class), ' '), ' Formuledadoption ')][1]",
            ];
            $start = null;
            foreach ($startQueries as $query) {
                $found = $xpath->query($query, $content);
                if ($found !== false && $found->length > 0) {
                    $start = $found->item(0);
                    break;
                }
            }
            if ($start === null) {
                return $html;
            }

            $this->removeNodesBefore($content, $start);

            return $dom->saveHTML($content) ?: $html;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    private function removeNodesBefore(\DOMNode $ancestor, \DOMNode $target): void
    {
        $path = [];
        for ($node = $target; $node !== null; $node = $node->parentNode) {
            $path[] = $node;
            if ($node === $ancestor) {
                break;
            }
        }
        if ($path === [] || end($path) !== $ancestor) {
            return;
        }

        $cursor = $ancestor;
        for ($i = count($path) - 2; $i >= 0; $i--) {
            $mark = $path[$i];
            if (!$cursor instanceof \DOMElement) {
                return;
            }
            while ($cursor->firstChild !== null && $cursor->firstChild !== $mark) {
                $cursor->removeChild($cursor->firstChild);
            }
            $cursor = $mark;
        }
    }
}
