<?php

declare(strict_types=1);

namespace Seismo\Plugin\LexLegifrance;

use DateTimeImmutable;
use DateTimeZone;
use Seismo\Core\Lex\LexLegifranceApiClient;
use Seismo\Core\Lex\LexLegifranceContentFetcher;
use Seismo\Core\Lex\LexPlainText;
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
        $client = LexLegifranceApiClient::fromConfig($config);

        $fond = strtoupper(trim((string)($config['fond'] ?? 'JORF')));
        if (!in_array($fond, self::ALLOWED_FONDS, true)) {
            throw new \InvalidArgumentException('Unsupported Légifrance fond: ' . $fond);
        }

        $lookback = max(1, (int)($config['lookback_days'] ?? 90));
        $startDate = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . $lookback . ' days')
            ->format('Y-m-d');
        $endDate = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');

        $allowedNatures = self::allowedNaturesFromConfig($config);

        $limitTotal = max(1, min((int)($config['limit'] ?? 100), 200));

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
                'valeurs' => $allowedNatures,
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

            $decoded = $client->postJson('/search', $body);
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
                $mapped = $this->mapSearchHit($hit, $allowedNatures);
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

        $contentLimit = max(0, min((int)($config['content_fetch_limit'] ?? 10), 30));
        if ($contentLimit > 0 && $rows !== []) {
            $rows = (new LexLegifranceContentFetcher($client))->attachContentToRows($rows, $contentLimit);
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    public static function allowedNaturesFromConfig(array $config): array
    {
        $raw = $config['natures'] ?? ['LOI', 'ORDONNANCE', 'DECRET'];
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', trim($raw)) ?: [];
        }
        if (!is_array($raw)) {
            $raw = ['LOI', 'ORDONNANCE', 'DECRET'];
        }
        $natures = array_values(array_filter(array_map(
            static fn ($v) => is_string($v) ? self::normalizeNatureKey($v) : '',
            $raw
        )));
        if ($natures === []) {
            return ['LOI', 'ORDONNANCE', 'DECRET'];
        }

        return $natures;
    }

    /**
     * Compare nature labels case- and accent-insensitively (API may return "Décret").
     */
    public static function normalizeNatureKey(string $raw): string
    {
        $u = mb_strtoupper(trim($raw), 'UTF-8');

        return str_replace(
            ['É', 'È', 'Ê', 'Ë', 'À', 'Â', 'Ä', 'Ù', 'Û', 'Ü', 'Î', 'Ï', 'Ô', 'Ö', 'Ç'],
            ['E', 'E', 'E', 'E', 'A', 'A', 'A', 'U', 'U', 'U', 'I', 'I', 'O', 'O', 'C'],
            $u
        );
    }

    /**
     * @param list<string> $allowedNatures Normalized keys from {@see allowedNaturesFromConfig()}.
     */
    private static function hitNatureIsAllowed(array $hit, array $allowedNatures): bool
    {
        $nature = self::normalizeNatureKey((string)($hit['nature'] ?? ''));
        if ($nature === '') {
            return true;
        }

        return in_array($nature, $allowedNatures, true);
    }

    /**
     * @param list<string> $allowedNatures
     * @return array<string, mixed>|null
     */
    private function mapSearchHit(array $hit, array $allowedNatures): ?array
    {
        if (!self::hitNatureIsAllowed($hit, $allowedNatures)) {
            return null;
        }
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
        $description = LexPlainText::truncate(trim(implode("\n\n", array_filter($descParts))));
        if ($description === null || $description === '') {
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
}
