<?php

declare(strict_types=1);

namespace Seismo\Plugin\LexLegifrance;

use DateTimeImmutable;
use DateTimeZone;
use Seismo\Service\SourceFetcherInterface;

/**
 * French JORF (and other Légifrance fonds) via PISTE OAuth + lf-engine-app /search.
 */
final class LexLegifrancePlugin implements SourceFetcherInterface
{
    public const ALLOWED_FONDS = [
        'JORF', 'CNIL', 'CETAT', 'JURI', 'JUFI', 'CONSTIT', 'KALI',
        'CODE_DATE', 'CODE_ETAT', 'LODA_DATE', 'LODA_ETAT', 'ALL', 'CIRC', 'ACCO',
    ];

    public function getIdentifier(): string
    {
        return 'legifrance';
    }

    public function getLabel(): string
    {
        return 'Légifrance (PISTE API)';
    }

    public function getEntryType(): string
    {
        return 'lex_item';
    }

    public function getConfigKey(): string
    {
        return 'fr';
    }

    public function getMinIntervalSeconds(): int
    {
        return 4 * 60 * 60;
    }

    public function fetch(array $config): array
    {
        $clientId = trim((string)($config['client_id'] ?? ''));
        $clientSecret = (string)($config['client_secret'] ?? '');
        if ($clientId === '' || trim($clientSecret) === '') {
            throw new \RuntimeException('Légifrance is not configured: set client_id and client_secret on the Lex page.');
        }

        $tokenUrl = trim((string)($config['oauth_token_url'] ?? 'https://oauth.piste.gouv.fr/api/oauth/token'));
        $apiBase = rtrim(trim((string)($config['api_base_url'] ?? 'https://api.piste.gouv.fr/dila/legifrance/lf-engine-app')), '/');
        $fond = strtoupper(trim((string)($config['fond'] ?? 'JORF')));
        if (!in_array($fond, self::ALLOWED_FONDS, true)) {
            throw new \InvalidArgumentException('Unsupported Légifrance fond: ' . $fond);
        }

        $lookback = max(1, (int)($config['lookback_days'] ?? 90));
        $startDate = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . $lookback . ' days')
            ->format('Y-m-d');
        $endDate = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');

        $natures = $config['natures'] ?? ['LOI', 'ORDONNANCE', 'DECRET'];
        if (!is_array($natures)) {
            $natures = ['LOI', 'ORDONNANCE', 'DECRET'];
        }
        $natures = array_values(array_filter(array_map(
            static fn ($v) => is_string($v) ? strtoupper(trim($v)) : '',
            $natures
        )));
        if ($natures === []) {
            $natures = ['LOI', 'ORDONNANCE', 'DECRET'];
        }

        $limitTotal = max(1, min((int)($config['limit'] ?? 100), 200));
        $token = $this->fetchAccessToken($tokenUrl, $clientId, $clientSecret);

        $filtres = [
            [
                'facette' => 'DATE_SIGNATURE',
                'dates' => [
                    'start' => $startDate,
                    'end' => $endDate,
                ],
            ],
            [
                'facette' => 'NATURE',
                'valeurs' => $natures,
            ],
        ];

        $rows = [];
        $page = 1;
        $pageSize = min(100, $limitTotal);

        while (count($rows) < $limitTotal && $page <= 20) {
            $remaining = $limitTotal - count($rows);
            $thisPageSize = min($pageSize, $remaining);
            $body = [
                'fond' => $fond,
                'recherche' => [
                    'pageNumber' => $page,
                    'pageSize' => $thisPageSize,
                    'sort' => 'SIGNATURE_DATE_DESC',
                    'typePagination' => 'DEFAUT',
                    'operateur' => 'ET',
                    'filtres' => $filtres,
                    'champs' => [
                        [
                            'typeChamp' => 'ALL',
                            'operateur' => 'ET',
                            'criteres' => [
                                [
                                    'typeRecherche' => 'UN_DES_MOTS',
                                    'operateur' => 'ET',
                                    'valeur' => 'le',
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $url = $apiBase . '/search';
            $decoded = $this->postJson($url, $token, $body);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Légifrance search returned invalid JSON.');
            }
            if (!empty($decoded['error'])) {
                throw new \RuntimeException('Légifrance API error: ' . json_encode($decoded['error'], JSON_UNESCAPED_UNICODE));
            }

            $batch = $decoded['results'] ?? [];
            if (!is_array($batch) || $batch === []) {
                break;
            }

            foreach ($batch as $hit) {
                if (!is_array($hit)) {
                    continue;
                }
                $mapped = $this->mapSearchHit($hit);
                if ($mapped !== null) {
                    $rows[] = $mapped;
                }
                if (count($rows) >= $limitTotal) {
                    break 2;
                }
            }

            $total = (int)($decoded['totalResultNumber'] ?? 0);
            if ($total > 0 && $page * $thisPageSize >= $total) {
                break;
            }
            ++$page;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapSearchHit(array $hit): ?array
    {
        $titles = $hit['titles'] ?? [];
        $titleStr = '';
        $id = '';
        if (is_array($titles) && $titles !== []) {
            $first = $titles[0];
            if (is_array($first)) {
                $titleStr = trim((string)($first['title'] ?? ''));
                $id = trim((string)($first['id'] ?? ''));
            }
        }
        if ($id === '') {
            $id = trim((string)($hit['jorfText'] ?? $hit['nor'] ?? ''));
        }
        if ($id === '') {
            return null;
        }
        if ($titleStr === '') {
            $titleStr = $id;
        }

        $dateRaw = (string)($hit['dateSignature'] ?? $hit['datePublication'] ?? $hit['date'] ?? '');
        $docDate = $this->normaliseApiDate($dateRaw);

        $descParts = [];
        if (!empty($hit['resumePrincipal']) && is_array($hit['resumePrincipal'])) {
            $descParts = array_merge($descParts, array_map('strval', $hit['resumePrincipal']));
        }
        $html = (string)($hit['descriptionFusionHtml'] ?? '');
        if ($html !== '') {
            $descParts[] = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $description = trim(implode("\n\n", array_filter($descParts)));
        if ($description === '') {
            $description = null;
        }

        $nature = trim((string)($hit['nature'] ?? ''));
        $url = 'https://www.legifrance.gouv.fr/jorf/id/' . rawurlencode($id);

        return [
            'celex' => mb_substr($id, 0, 255),
            'title' => $titleStr,
            'description' => $description,
            'document_date' => $docDate,
            'document_type' => $nature !== '' ? $nature : 'JORF',
            'eurlex_url' => $url,
            'work_uri' => $url,
            'source' => 'fr',
        ];
    }

    private function normaliseApiDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m)) {
            return $m[1];
        }
        $ts = strtotime($raw);

        return $ts > 0 ? gmdate('Y-m-d', $ts) : null;
    }

    private function fetchAccessToken(string $tokenUrl, string $clientId, string $clientSecret): string
    {
        $payload = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => 'openid',
        ], '', '&', PHP_QUERY_RFC3986);

        $decoded = $this->httpPostForm($tokenUrl, $payload);
        if (!is_array($decoded) || empty($decoded['access_token']) || !is_string($decoded['access_token'])) {
            throw new \RuntimeException('Légifrance OAuth token response missing access_token.');
        }

        return $decoded['access_token'];
    }

    private function postJson(string $url, string $token, array $body): mixed
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode Légifrance request JSON.');
        }

        return $this->httpRequest('POST', $url, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ], $json);
    }

    private function httpPostForm(string $url, string $body): mixed
    {
        return $this->httpRequest('POST', $url, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], $body);
    }

    /**
     * @param list<string> $headers
     */
    private function httpRequest(string $method, string $url, array $headers, string $body): mixed
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP curl extension is required for Légifrance API calls.');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed for ' . $url);
        }
        $headerFlat = array_map(static fn (string $h): string => $h, $headers);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerFlat,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new \RuntimeException('HTTP request failed for ' . $url . ($err !== '' ? ': ' . $err : ''));
        }
        if ($status >= 400) {
            throw new \RuntimeException('HTTP ' . $status . ' from ' . $url . ': ' . mb_substr((string)$raw, 0, 500));
        }
        $decoded = json_decode((string)$raw, true);

        return $decoded;
    }
}
