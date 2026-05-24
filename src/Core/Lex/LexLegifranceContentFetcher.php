<?php

declare(strict_types=1);

namespace Seismo\Core\Lex;

/**
 * Fetch full JORF text corpus via PISTE consult + search fallbacks.
 */
final class LexLegifranceContentFetcher
{
    public const MAX_CONTENT_BYTES = 1_048_576;

    public function __construct(
        private LexLegifranceApiClient $client,
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
            if (trim((string)($row['content'] ?? '')) !== '') {
                continue;
            }
            $consultId = self::consultIdFromRow($row);
            if ($consultId === null) {
                continue;
            }
            $content = $this->fetchPlainTextForConsultId(
                $consultId,
                null,
                trim((string)($row['title'] ?? '')) !== '' ? trim((string)$row['title']) : null,
            );
            if ($content === null) {
                continue;
            }
            $row['content'] = $content;
            $fetched++;
        }
        unset($row);

        return $rows;
    }

    public function fetchPlainTextForTextCid(string $textCid, ?string &$failureReason = null): ?string
    {
        return $this->fetchPlainTextForConsultId($textCid, $failureReason);
    }

    public function fetchPlainTextForConsultId(string $consultId, ?string &$failureReason = null, ?string $titleHint = null): ?string
    {
        $consultId = trim($consultId);
        if ($consultId === '') {
            $failureReason = 'no_jorf_text_cid';

            return null;
        }

        // Search works on PISTE plans that block /consult/* (HTTP 403).
        $hit = $this->searchHitByConsultId($consultId, $titleHint);
        if ($hit !== null) {
            $plain = LexLegifranceSearchTextExtractor::corpusFromSearchHit($hit);
            if ($plain !== null && $plain !== '') {
                return $plain;
            }
            $failureReason = 'empty_corpus';
        }

        $consultFailure = null;
        if (self::isJorfTextCid($consultId)) {
            $plain = $this->fetchFromJorfConsult($consultId, $consultFailure);
            if ($plain !== null) {
                return $plain;
            }
        } elseif (str_starts_with(strtoupper($consultId), 'LEGITEXT')) {
            $textId = explode('_', $consultId)[0];
            $plain  = $this->fetchFromLegiPart($textId, $consultFailure);
            if ($plain !== null) {
                return $plain;
            }
        }

        if ($failureReason === null) {
            $failureReason = ($hit === null && $consultFailure === 'api_error')
                ? 'search_miss'
                : ($consultFailure ?? 'search_miss');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $response
     */
    public function plainTextFromJorfResponse(array $response): ?string
    {
        if (isset($response['text']) && is_array($response['text'])) {
            $response = $response['text'];
        }

        $parts = [];
        foreach (['visa', 'title', 'resume', 'notice', 'exposeMotif', 'prepWork', 'signers'] as $key) {
            $value = trim((string)($response[$key] ?? ''));
            if ($value !== '') {
                $parts[] = LexPlainText::normalize($value);
            }
        }

        $parts = array_merge($parts, $this->collectArticleTexts($response));
        $plain = trim(implode("\n\n", array_filter($parts)));
        if ($plain === '') {
            return null;
        }
        if (strlen($plain) > self::MAX_CONTENT_BYTES) {
            $plain = substr($plain, 0, self::MAX_CONTENT_BYTES) . "\n\n[truncated]";
        }

        return $plain;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function textCidFromRow(array $row): ?string
    {
        return self::consultIdFromRow($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function consultIdFromRow(array $row): ?string
    {
        $celex = trim((string)($row['celex'] ?? ''));
        if ($celex !== '' && self::isConsultId($celex)) {
            return $celex;
        }

        foreach (['work_uri', 'eurlex_url'] as $key) {
            $url = trim((string)($row[$key] ?? ''));
            if ($url === '') {
                continue;
            }
            if (preg_match('#/jorf/id/(JORFTEXT[0-9A-Z]+)#i', $url, $m)) {
                return strtoupper($m[1]);
            }
        }

        return null;
    }

    public static function isJorfTextCid(string $id): bool
    {
        return $id !== '' && str_starts_with(strtoupper($id), 'JORFTEXT');
    }

    public static function isConsultId(string $id): bool
    {
        $id = strtoupper(trim($id));

        return self::isJorfTextCid($id)
            || str_starts_with($id, 'LEGITEXT')
            || preg_match('/^[A-Z]{4}\d{9}[A-Z]$/', $id) === 1;
    }

    /**
     * @param array<string, mixed> $hit
     */
    public static function jorfTextCidFromSearchHit(array $hit): string
    {
        $jorfText = trim((string)($hit['jorfText'] ?? ''));
        if (self::isJorfTextCid($jorfText)) {
            return strtoupper($jorfText);
        }

        $titles = $hit['titles'] ?? [];
        if (is_array($titles) && $titles !== []) {
            $first = $titles[0];
            if (is_array($first)) {
                foreach (['id', 'cid'] as $key) {
                    $candidate = trim((string)($first[$key] ?? ''));
                    if (self::isJorfTextCid($candidate)) {
                        return strtoupper($candidate);
                    }
                }
            }
        }

        $nor = trim((string)($hit['nor'] ?? ''));

        return $jorfText !== '' ? $jorfText : $nor;
    }

    private function fetchFromJorfConsult(string $textCid, ?string &$failureReason): ?string
    {
        $resp = $this->client->postJsonResponse('/consult/jorf', ['textCid' => strtoupper($textCid)]);
        if ($resp['status'] >= 400) {
            $failureReason = 'api_error';

            return null;
        }
        if (!is_array($resp['decoded'])) {
            $failureReason = 'invalid_json';

            return null;
        }

        return $this->plainTextFromJorfResponse($resp['decoded']);
    }

    private function fetchFromLegiPart(string $textId, ?string &$failureReason): ?string
    {
        $resp = $this->client->postJsonResponse('/consult/legiPart', [
            'textId' => $textId,
            'date'   => gmdate('Y-m-d'),
        ]);
        if ($resp['status'] >= 400) {
            $failureReason = 'api_error';

            return null;
        }
        if (!is_array($resp['decoded'])) {
            $failureReason = 'invalid_json';

            return null;
        }

        return $this->plainTextFromJorfResponse($resp['decoded']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function searchHitByConsultId(string $consultId, ?string $titleHint = null): ?array
    {
        $consultId = strtoupper(trim($consultId));
        $queries   = $this->searchQueriesForConsultId($consultId, $titleHint);

        foreach ($queries as $query) {
            $hits = $this->searchHits(
                (string)$query['typeChamp'],
                (string)$query['typeRecherche'],
                (string)$query['valeur'],
                (int)$query['pageSize'],
            );
            foreach ($hits as $hit) {
                if ($this->hitMatchesConsultId($hit, $consultId)) {
                    return $hit;
                }
            }
            if (($query['typeChamp'] ?? '') === 'NOR' && count($hits) === 1) {
                return $hits[0];
            }
        }

        return null;
    }

    /**
     * @return list<array{typeChamp: string, typeRecherche: string, valeur: string, pageSize: int}>
     */
    private function searchQueriesForConsultId(string $consultId, ?string $titleHint): array
    {
        $queries = [];

        if (preg_match('/^[A-Z]{4}\d{9}[A-Z]$/', $consultId) === 1) {
            $queries[] = ['typeChamp' => 'NOR', 'typeRecherche' => 'EXACTE', 'valeur' => $consultId, 'pageSize' => 5];
        }

        if (self::isJorfTextCid($consultId)) {
            $queries[] = ['typeChamp' => 'ALL', 'typeRecherche' => 'EXACTE', 'valeur' => $consultId, 'pageSize' => 10];
            $queries[] = ['typeChamp' => 'ALL', 'typeRecherche' => 'UN_DES_MOTS', 'valeur' => $consultId, 'pageSize' => 10];
            if (preg_match('/JORFTEXT0*(\d+)$/i', $consultId, $m)) {
                $queries[] = ['typeChamp' => 'NUM', 'typeRecherche' => 'EXACTE', 'valeur' => $m[1], 'pageSize' => 10];
            }
        } elseif (str_starts_with($consultId, 'LEGITEXT')) {
            $textId = explode('_', $consultId)[0];
            $queries[] = ['typeChamp' => 'ALL', 'typeRecherche' => 'EXACTE', 'valeur' => $textId, 'pageSize' => 10];
        }

        $titleHint = trim((string)$titleHint);
        if ($titleHint !== '' && mb_strlen($titleHint) >= 12) {
            $queries[] = ['typeChamp' => 'TITLE', 'typeRecherche' => 'UN_DES_MOTS', 'valeur' => $titleHint, 'pageSize' => 10];
        }

        return $queries;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchHits(string $typeChamp, string $typeRecherche, string $valeur, int $pageSize): array
    {
        $body = [
            'fond' => 'JORF',
            'recherche' => [
                'pageNumber' => 1,
                'pageSize' => max(1, min($pageSize, 20)),
                'sort' => 'SIGNATURE_DATE_DESC',
                'typePagination' => 'DEFAUT',
                'operateur' => 'ET',
                'champs' => [[
                    'typeChamp' => $typeChamp,
                    'operateur' => 'ET',
                    'criteres' => [[
                        'typeRecherche' => $typeRecherche,
                        'operateur' => 'ET',
                        'valeur' => $valeur,
                    ]],
                ]],
            ],
        ];

        $resp = $this->client->postJsonResponse('/search', $body);
        if ($resp['status'] >= 400 || !is_array($resp['decoded'])) {
            return [];
        }
        $results = $resp['decoded']['results'] ?? [];

        return is_array($results) ? array_values(array_filter($results, 'is_array')) : [];
    }

    /**
     * @param array<string, mixed> $hit
     */
    private function hitMatchesConsultId(array $hit, string $consultId): bool
    {
        $want = strtoupper(trim($consultId));

        $jorfText = strtoupper(trim((string)($hit['jorfText'] ?? '')));
        if ($jorfText !== '' && ($jorfText === $want || str_contains($want, $jorfText) || str_contains($jorfText, $want))) {
            return true;
        }

        $nor = strtoupper(trim((string)($hit['nor'] ?? '')));
        if ($nor !== '' && $nor === $want) {
            return true;
        }

        foreach ((array)($hit['titles'] ?? []) as $titleRow) {
            if (!is_array($titleRow)) {
                continue;
            }
            foreach (['id', 'cid'] as $key) {
                $candidate = strtoupper(trim((string)($titleRow[$key] ?? '')));
                if ($candidate !== '' && $candidate === $want) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @return list<string>
     */
    private function collectArticleTexts(array $node): array
    {
        $out = [];

        $articles = $node['articles'] ?? [];
        if (is_array($articles) && $articles !== []) {
            usort(
                $articles,
                static fn (mixed $a, mixed $b): int => (int)(is_array($a) ? ($a['intOrdre'] ?? 0) : 0)
                    <=> (int)(is_array($b) ? ($b['intOrdre'] ?? 0) : 0),
            );
            foreach ($articles as $article) {
                if (!is_array($article)) {
                    continue;
                }
                $chunk = $this->articlePlainText($article);
                if ($chunk !== '') {
                    $out[] = $chunk;
                }
            }
        }

        $sections = $node['sections'] ?? [];
        if (is_array($sections) && $sections !== []) {
            usort(
                $sections,
                static fn (mixed $a, mixed $b): int => (int)(is_array($a) ? ($a['intOrdre'] ?? 0) : 0)
                    <=> (int)(is_array($b) ? ($b['intOrdre'] ?? 0) : 0),
            );
            foreach ($sections as $section) {
                if (!is_array($section)) {
                    continue;
                }
                $title = trim((string)($section['title'] ?? ''));
                if ($title !== '') {
                    $out[] = $title;
                }
                $out = array_merge($out, $this->collectArticleTexts($section));
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $article
     */
    private function articlePlainText(array $article): string
    {
        $content = '';
        foreach (['content', 'texte', 'texteHtml', 'historique', 'nota'] as $key) {
            $plain = LexPlainText::fromHtml((string)($article[$key] ?? ''));
            if ($plain !== '') {
                $content = $plain;
                break;
            }
        }
        if ($content === '') {
            return '';
        }

        $num = trim((string)($article['num'] ?? $article['numero'] ?? ''));
        $surtitre = trim((string)($article['surtitre'] ?? ''));
        $head = [];
        if ($num !== '') {
            $head[] = 'Article ' . $num;
        }
        if ($surtitre !== '') {
            $head[] = $surtitre;
        }
        if ($head === []) {
            return $content;
        }

        return implode(' — ', $head) . "\n" . $content;
    }
}
