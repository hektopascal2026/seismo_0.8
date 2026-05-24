<?php

declare(strict_types=1);

namespace Seismo\Core\Lex;

use Seismo\Service\Http\BaseClient;
use Seismo\Service\Http\HttpClientException;

/**
 * Fetch plain-text corpus from EUR-Lex HTML pages (`…/TXT/HTML/?uri=CELEX:…`).
 */
final class LexEurLexContentFetcher
{
    /** Cap stored corpus size — EU directives can exceed 1 MB of plain text. */
    public const MAX_CONTENT_BYTES = 1_048_576;

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
            $url = trim((string)($row['eurlex_url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $content = $this->fetchPlainTextFromUrl($url);
            if ($content === null) {
                continue;
            }
            $row['content'] = $content;
            $fetched++;
        }
        unset($row);

        return $rows;
    }

    public function fetchPlainTextFromUrl(string $eurlexUrl): ?string
    {
        $url = trim($eurlexUrl);
        if ($url === '' || !preg_match('#^https://#i', $url)) {
            return null;
        }

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

    public function plainTextFromHtml(string $html): ?string
    {
        $fragment = $this->extractEliContainerHtml($html);
        $plain = LexPlainText::fromHtml($fragment !== '' ? $fragment : $html);
        if ($plain === '') {
            return null;
        }
        if (strlen($plain) > self::MAX_CONTENT_BYTES) {
            $plain = substr($plain, 0, self::MAX_CONTENT_BYTES) . "\n\n[truncated]";
        }

        return $plain;
    }

    private function extractEliContainerHtml(string $html): string
    {
        $prev = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            if (@$dom->loadHTML($html) === false) {
                return '';
            }
            $xpath = new \DOMXPath($dom);
            $nodes = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' eli-container ')]");
            if ($nodes === false || $nodes->length === 0) {
                return '';
            }
            $chunk = '';
            foreach ($nodes as $node) {
                $chunk .= $dom->saveHTML($node) ?: '';
            }

            return $chunk;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }
}
