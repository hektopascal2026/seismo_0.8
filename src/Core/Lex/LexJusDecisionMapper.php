<?php

declare(strict_types=1);

namespace Seismo\Core\Lex;

use Seismo\Service\Http\BaseClient;
use Seismo\Service\Http\HttpClientException;

/**
 * Map entscheidsuche.ch decision JSON (+ hosted HTML) to lex row corpus fields.
 */
final class LexJusDecisionMapper
{
    public const SYNOPSIS_MAX_CHARS = LexPlainText::DEFAULT_SYNOPSIS_CHARS;

    /** Legacy plugin spider folder for BVGer (entscheidsuche still serves JSON here). */
    private const BVGER_LEGACY_SPIDER_DIR = 'CH_BVGer';

    /**
     * @return list<string>
     */
    public static function candidateJsonUrls(
        string $celex,
        ?string $workUri = null,
        string $baseUrl = 'https://entscheidsuche.ch',
    ): array {
        $seen = [];
        $out  = [];

        $add = static function (?string $url) use (&$seen, &$out): void {
            $url = trim((string)$url);
            if ($url === '' || isset($seen[$url])) {
                return;
            }
            $seen[$url] = true;
            $out[] = $url;
        };

        $workUri = trim((string)$workUri);
        if ($workUri !== '') {
            if (str_starts_with($workUri, '/docs/')) {
                $add(rtrim($baseUrl, '/') . $workUri);
            } elseif (preg_match('#^https?://#i', $workUri)) {
                $add($workUri);
            }
        }

        $celex = trim(preg_replace('/\.json$/i', '', $celex));
        if ($celex === '' || !preg_match('#^(CH_(?:BGer|BGE|BVGE))_#', $celex, $m)) {
            return $out;
        }

        $root = rtrim($baseUrl, '/') . '/docs/';
        $add($root . $m[1] . '/' . $celex . '.json');
        if ($m[1] === 'CH_BVGE') {
            $add($root . self::BVGER_LEGACY_SPIDER_DIR . '/' . $celex . '.json');
        }

        return $out;
    }

    /**
     * Resolve the first candidate JSON URL (backwards compatible helper).
     */
    public static function resolveDecisionJsonUrl(
        string $celex,
        ?string $workUri = null,
        string $baseUrl = 'https://entscheidsuche.ch',
    ): ?string {
        $urls = self::candidateJsonUrls($celex, $workUri, $baseUrl);

        return $urls === [] ? null : $urls[0];
    }

    /**
     * Fetch corpus for a stored Jus row, trying alternate JSON paths and JSON text fallbacks.
     *
     * @return array{description: ?string, content: ?string}|null
     */
    public static function fetchCorpusForRow(
        BaseClient $http,
        string $celex,
        ?string $workUri = null,
        string $baseUrl = 'https://entscheidsuche.ch',
    ): ?array {
        $best = null;

        foreach (self::candidateJsonUrls($celex, $workUri, $baseUrl) as $jsonUrl) {
            $corpus = self::fetchCorpusFromJsonUrl($http, $jsonUrl, $baseUrl);
            if ($corpus === null) {
                continue;
            }
            if (trim((string)($corpus['content'] ?? '')) !== '') {
                return $corpus;
            }
            if ($best === null || trim((string)($corpus['description'] ?? '')) !== '') {
                $best = $corpus;
            }
        }

        return $best;
    }

    /**
     * @return array{description: ?string, content: ?string}|null
     */
    public static function fetchCorpusFromJsonUrl(
        BaseClient $http,
        string $jsonUrl,
        string $baseUrl = 'https://entscheidsuche.ch',
    ): ?array {
        if (!preg_match('#^https?://#i', $jsonUrl)) {
            return null;
        }

        $decision = self::getJson($http, $jsonUrl);
        if ($decision === null) {
            return null;
        }

        $htmlBody = null;
        $htmlFile = self::htmlFileFromDecision($decision);
        if ($htmlFile !== null) {
            $htmlBody = self::getBody(
                $http,
                rtrim($baseUrl, '/') . '/docs/' . ltrim($htmlFile, '/'),
            );
        }

        return self::corpusFieldsFromDecision($decision, $htmlBody);
    }

    /**
     * Backwards-compatible entry when callers already hold a full JSON URL.
     *
     * @return array{description: ?string, content: ?string}|null
     */
    public static function fetchCorpusFromWorkUri(BaseClient $http, string $workUri): ?array
    {
        return self::fetchCorpusFromJsonUrl($http, trim($workUri));
    }

    /**
     * @param array<string, mixed> $decision
     * @return array{description: ?string, content: ?string}
     */
    public static function corpusFieldsFromDecision(
        array $decision,
        ?string $htmlBody,
    ): array {
        $content = null;
        if ($htmlBody !== null && $htmlBody !== '') {
            $plain = LexPlainText::fromHtml($htmlBody);
            $content = $plain !== '' ? $plain : null;
        }

        if ($content === null) {
            $jsonText = self::textCorpusFromDecision($decision);
            $content = $jsonText !== '' ? $jsonText : null;
        }

        $description = self::synopsisFromDecision($decision);
        if ($description === null && $content !== null) {
            $description = LexPlainText::truncate($content, self::SYNOPSIS_MAX_CHARS);
        }

        return [
            'description' => $description,
            'content'     => $content,
        ];
    }

    /**
     * Aggregate German (preferred) text blocks from entscheidsuche JSON metadata.
     *
     * @param array<string, mixed> $decision
     */
    public static function textCorpusFromDecision(array $decision): string
    {
        $parts = [];
        foreach (['Abstract', 'Kopfzeile', 'Meta'] as $key) {
            $blocks = $decision[$key] ?? null;
            if (!is_array($blocks)) {
                continue;
            }
            foreach ($blocks as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $langs = $block['Sprachen'] ?? null;
                if (is_array($langs) && $langs !== [] && !in_array('de', $langs, true)) {
                    continue;
                }
                $text = trim(html_entity_decode(strip_tags((string)($block['Text'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($text !== '') {
                    $parts[$text] = true;
                }
            }
        }

        return LexPlainText::normalize(implode("\n\n", array_keys($parts)));
    }

    /**
     * @param array<string, mixed> $decision
     */
    public static function synopsisFromDecision(array $decision): ?string
    {
        $abstract = $decision['Abstract'] ?? null;
        if (is_array($abstract)) {
            foreach ($abstract as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $langs = $block['Sprachen'] ?? null;
                if (is_array($langs) && $langs !== [] && !in_array('de', $langs, true)) {
                    continue;
                }
                $text = trim(html_entity_decode(strip_tags((string)($block['Text'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($text === '') {
                    continue;
                }
                $plain = LexPlainText::normalize($text);

                return LexPlainText::truncate($plain, self::SYNOPSIS_MAX_CHARS);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $decision
     */
    public static function htmlFileFromDecision(array $decision): ?string
    {
        $html = $decision['HTML'] ?? null;
        if (!is_array($html)) {
            return null;
        }
        $file = trim((string)($html['Datei'] ?? ''));

        return $file !== '' ? $file : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function getJson(BaseClient $http, string $url): ?array
    {
        try {
            $resp = $http->get($url, ['Accept' => 'application/json']);
        } catch (HttpClientException) {
            return null;
        }
        if (!$resp->isOk() || $resp->body === '') {
            return null;
        }
        try {
            $data = $resp->json();
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    private static function getBody(BaseClient $http, string $url): ?string
    {
        try {
            $resp = $http->get($url, ['Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8']);
        } catch (HttpClientException) {
            return null;
        }
        if (!$resp->isOk() || $resp->body === '') {
            return null;
        }

        return $resp->body;
    }
}
