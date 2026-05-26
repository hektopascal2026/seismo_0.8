<?php

declare(strict_types=1);

namespace Seismo\Core\Lex;

use Seismo\Plugin\LexFedlex\LexFedlexPlugin;
use Seismo\Service\Http\BaseClient;
use Seismo\Service\Http\HttpClientException;
use Seismo\Service\Sparql\SparqlEasyRdf;

/**
 * Fetch Swiss federal act corpus from Fedlex Akoma Ntoso XML (filestore via SPARQL).
 *
 * Portal HTML ({@see https://www.fedlex.admin.ch/eli/...}) is an SPA shell; full text
 * is exposed as {@code jolux:isExemplifiedBy} on the {@code /de/xml} manifestation.
 */
final class LexFedlexContentFetcher
{
    public const MAX_CONTENT_BYTES = 1_048_576;

    private const FEDLEX_HOST = 'https://fedlex.data.admin.ch/';

    public function __construct(
        private BaseClient $http = new BaseClient(45),
        private string $sparqlEndpoint = 'https://fedlex.data.admin.ch/sparqlendpoint',
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
            if (trim((string)($row['source'] ?? '')) !== 'ch') {
                continue;
            }
            if (!self::isOfficialCompilationAct($row)) {
                continue;
            }
            $content = $this->fetchPlainTextFromRow($row);
            if ($content === null || $content === '') {
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
        if (!self::isOfficialCompilationAct($row)) {
            return null;
        }

        $actUri = trim((string)($row['work_uri'] ?? ''));
        if ($actUri === '' || !str_starts_with($actUri, self::FEDLEX_HOST)) {
            return null;
        }

        $langPath = self::langPathFromRow($row);
        $xmlUrl = $this->resolveXmlFileUrl($actUri, $langPath);
        if ($xmlUrl === null) {
            return null;
        }

        try {
            $resp = $this->http->get($xmlUrl, [
                'Accept' => 'application/xml,text/xml,*/*;q=0.8',
            ]);
        } catch (HttpClientException) {
            return null;
        }

        if (!$resp->isOk() || $resp->body === '' || !str_contains($resp->body, '<')) {
            return null;
        }

        return $this->plainTextFromAkomaXml($resp->body);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function isOfficialCompilationAct(array $row): bool
    {
        $celex = trim((string)($row['celex'] ?? ''));

        return $celex !== '' && str_starts_with($celex, 'eli/oc/');
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function langPathFromRow(array $row): string
    {
        $url = trim((string)($row['eurlex_url'] ?? ''));
        if (preg_match('#/eli/[^/]+/([a-z]{2})(?:/|$)#', $url, $m)) {
            return $m[1];
        }

        return 'de';
    }

    public function plainTextFromAkomaXml(string $xml): ?string
    {
        $xml = trim($xml);
        if ($xml === '') {
            return null;
        }

        $prev = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            if (@$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) === false) {
                return null;
            }
            $xpath = new \DOMXPath($dom);
            $chunks = [];

            foreach (['preface', 'preamble'] as $local) {
                $nodes = $xpath->query('//*[local-name()="' . $local . '"]');
                if ($nodes === false) {
                    continue;
                }
                foreach ($nodes as $node) {
                    $t = trim((string)$node->textContent);
                    if ($t !== '') {
                        $chunks[] = $t;
                    }
                }
            }

            $bodyNodes = $xpath->query('//*[local-name()="body"]');
            if ($bodyNodes !== false && $bodyNodes->length > 0) {
                $body = $bodyNodes->item(0);
                if ($body instanceof \DOMElement) {
                    $levelNodes = (new \DOMXPath($dom))->query('.//*[local-name()="level"]', $body);
                    if ($levelNodes !== false && $levelNodes->length > 0) {
                        foreach ($levelNodes as $level) {
                            $t = trim((string)$level->textContent);
                            if ($t !== '') {
                                $chunks[] = $t;
                            }
                        }
                    } else {
                        $t = trim((string)$body->textContent);
                        if ($t !== '') {
                            $chunks[] = $t;
                        }
                    }
                }
            }

            if ($chunks === []) {
                $t = LexPlainText::normalize(strip_tags($xml));
                if ($t === '') {
                    return null;
                }

                return self::capPlain($t);
            }

            $plain = LexPlainText::normalize(implode("\n\n", $chunks));

            return $plain === '' ? null : self::capPlain($plain);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    private function resolveXmlFileUrl(string $actUri, string $langPath): ?string
    {
        $langPath = LexFedlexPlugin::authorityToFedlexLangPath(
            match ($langPath) {
                'fr' => 'FRA',
                'it' => 'ITA',
                'en' => 'ENG',
                'rm' => 'ROH',
                default => 'DEU',
            },
        );
        $manifest = rtrim($actUri, '/') . '/' . $langPath . '/xml';

        $sq = '
        PREFIX jolux: <http://data.legilux.public.lu/resource/ontology/jolux#>
        SELECT ?file WHERE {
            <' . $manifest . '> jolux:isExemplifiedBy ?file .
        }
        LIMIT 1';

        try {
            $results = SparqlEasyRdf::client($this->sparqlEndpoint)->query($sq);
        } catch (\Throwable) {
            return null;
        }

        foreach ($results as $row) {
            $file = trim((string)($row->file ?? ''));
            if ($file !== '' && preg_match('#^https://#i', $file)) {
                return $file;
            }
        }

        return null;
    }

    private static function capPlain(string $plain): string
    {
        if (strlen($plain) > self::MAX_CONTENT_BYTES) {
            return \Seismo\Util\Utf8ByteCap::truncate($plain, self::MAX_CONTENT_BYTES, "\n\n[truncated]");
        }

        return $plain;
    }
}
