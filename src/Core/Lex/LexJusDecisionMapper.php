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
                $text = trim((string)($block['Text'] ?? ''));
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
     * Resolve entscheidsuche decision JSON URL from stored row fields.
     */
    public static function resolveDecisionJsonUrl(
        string $celex,
        ?string $workUri = null,
        string $baseUrl = 'https://entscheidsuche.ch',
    ): ?string {
        $workUri = trim((string)$workUri);
        if ($workUri !== '') {
            if (str_starts_with($workUri, '/docs/')) {
                return rtrim($baseUrl, '/') . $workUri;
            }
            if (preg_match('#^https?://#i', $workUri)) {
                return $workUri;
            }
        }

        $celex = trim(preg_replace('/\.json$/i', '', $celex));
        if ($celex === '') {
            return null;
        }

        if (!preg_match('#^(CH_(?:BGer|BGE|BVGE))_#', $celex, $m)) {
            return null;
        }

        return rtrim($baseUrl, '/') . '/docs/' . $m[1] . '/' . $celex . '.json';
    }

    /**
     * Fetch decision JSON + hosted HTML from a stored `work_uri` and return corpus fields.
     *
     * @return array{description: ?string, content: ?string}|null
     */
    public static function fetchCorpusFromWorkUri(BaseClient $http, string $workUri): ?array
    {
        $jsonUrl = self::resolveDecisionJsonUrl('', $workUri);
        if ($jsonUrl === null) {
            $jsonUrl = trim($workUri);
        }
        if ($jsonUrl === '' || !preg_match('#^https?://#i', $jsonUrl)) {
            return null;
        }

        $decision = self::getJson($http, $jsonUrl);
        if ($decision === null) {
            return null;
        }

        $htmlBody = null;
        $htmlFile = self::htmlFileFromDecision($decision);
        if ($htmlFile !== null) {
            $base = preg_match('#^(https?://[^/]+)#i', $jsonUrl, $m) ? $m[1] : 'https://entscheidsuche.ch';
            $htmlBody = self::getBody($http, rtrim($base, '/') . '/docs/' . ltrim($htmlFile, '/'));
        }

        return self::corpusFieldsFromDecision($decision, $htmlBody);
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
