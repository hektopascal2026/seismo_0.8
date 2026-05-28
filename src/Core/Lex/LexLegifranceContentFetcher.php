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

    /**
     * Full JORF body via /consult/jorf only (use during ingest when the search hit is already known).
     */
    public function fetchJorfConsultCorpus(string $textCid, ?string &$failureReason = null): ?string
    {
        $textCid = self::normalizeConsultId(trim($textCid));
        if (!self::isPlainJorfTextCid($textCid)) {
            $failureReason = 'no_jorf_text_cid';

            return null;
        }

        return $this->fetchFromJorfConsult($textCid, $failureReason);
    }

    /**
     * Fetch structured JORF consult response data, retaining original fields (notice, prepWork, exposeMotif)
     * along with the plain text body content.
     *
     * @return array{content: string, notice: string, prepWork: string, exposeMotif: string}|null
     */
    public function fetchJorfConsultData(string $textCid, ?string &$failureReason = null): ?array
    {
        $textCid = self::normalizeConsultId(trim($textCid));
        $isDole = str_starts_with($textCid, 'JORFDOLE');
        if (!self::isPlainJorfTextCid($textCid) && !$isDole) {
            $failureReason = 'no_jorf_text_cid';

            return null;
        }

        $endpoint = $isDole ? '/consult/dole' : '/consult/jorf';
        $resp = $this->client->postJsonResponse($endpoint, ['textCid' => $textCid]);
        if ($resp['status'] >= 400) {
            $failureReason = 'api_error';

            return null;
        }
        if (!is_array($resp['decoded'])) {
            $failureReason = 'invalid_json';

            return null;
        }

        $decoded = $resp['decoded'];

        if ($isDole) {
            $titre = trim((string)($decoded['titre'] ?? ''));
            $notice = trim((string)($decoded['notice'] ?? ''));
            $exposeMotif = trim((string)($decoded['exposeMotif'] ?? ''));

            $parts = [];
            if ($titre !== '') {
                $parts[] = $titre;
            }
            if ($notice !== '') {
                $parts[] = "Notice :\n" . LexPlainText::fromHtml($notice);
            }
            if ($exposeMotif !== '') {
                $parts[] = "Exposé des motifs :\n" . LexPlainText::fromHtml($exposeMotif);
            }

            $plainText = trim(implode("\n\n", $parts));

            return [
                'content'     => $plainText,
                'notice'      => $notice,
                'prepWork'    => '',
                'exposeMotif' => $exposeMotif,
            ];
        }

        $plainText = $this->plainTextFromJorfResponse($decoded);
        if ($plainText === null || $plainText === '') {
            $failureReason = 'empty_corpus';

            return null;
        }

        $textNode = isset($decoded['text']) && is_array($decoded['text']) ? $decoded['text'] : $decoded;

        return [
            'content'     => $plainText,
            'notice'      => trim((string)($textNode['notice'] ?? '')),
            'prepWork'    => trim((string)($textNode['prepWork'] ?? '')),
            'exposeMotif' => trim((string)($textNode['exposeMotif'] ?? '')),
        ];
    }

    public function fetchPlainTextForTextCid(string $textCid, ?string &$failureReason = null): ?string
    {
        return $this->fetchPlainTextForConsultId($textCid, $failureReason);
    }

    public function fetchPlainTextForConsultId(string $consultId, ?string &$failureReason = null, ?string $titleHint = null): ?string
    {
        $consultId = self::normalizeConsultId(trim($consultId));
        if ($consultId === '') {
            $failureReason = 'no_jorf_text_cid';

            return null;
        }

        $consultFailure = null;
        if (self::isPlainJorfTextCid($consultId)) {
            $plain = $this->fetchFromJorfConsult($consultId, $consultFailure);
            if ($plain !== null && $plain !== '') {
                return $plain;
            }
        } elseif (str_starts_with($consultId, 'LEGITEXT')) {
            $plain = $this->fetchFromLegiPart($consultId, $consultFailure);
            if ($plain !== null && $plain !== '') {
                return $plain;
            }
        }

        $hit = $this->searchHitByConsultId($consultId, $titleHint);
        if ($hit !== null) {
            $plain = LexLegifranceSearchTextExtractor::corpusFromSearchHit($hit);
            if ($plain !== null && $plain !== '') {
                return $plain;
            }
            $failureReason = 'empty_corpus';

            return null;
        }

        if ($failureReason === null) {
            $failureReason = $consultFailure ?? 'search_miss';
        }

        return null;
    }

    public function plainTextFromJorfResponse(array $response): ?string
    {
        if (isset($response['text']) && is_array($response['text'])) {
            $response = $response['text'];
        }

        $parts = [];
        foreach (['visa', 'title', 'resume', 'notice', 'exposeMotif', 'signers'] as $key) {
            $value = trim((string)($response[$key] ?? ''));
            if ($value !== '') {
                $parts[] = LexPlainText::normalize($value);
            }
        }

        $parts = array_merge($parts, $this->collectArticleTexts($response));

        $prepWork = trim((string)($response['prepWork'] ?? ''));
        if ($prepWork !== '') {
            $parts[] = "\n(1) " . LexPlainText::normalize($prepWork);
        }

        $plain = trim(implode("\n\n", array_filter($parts)));
        if ($plain === '') {
            return null;
        }
        if (strlen($plain) > self::MAX_CONTENT_BYTES) {
            $plain = \Seismo\Util\Utf8ByteCap::truncate($plain, self::MAX_CONTENT_BYTES, "\n\n[truncated]");
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

    public static function consultIdFromRow(array $row): ?string
    {
        $celex = trim((string)($row['celex'] ?? ''));
        if ($celex !== '') {
            $normalized = self::normalizeConsultId($celex);
            if (self::isConsultId($normalized)) {
                return $normalized;
            }
        }

        foreach (['work_uri', 'eurlex_url'] as $key) {
            $url = trim((string)($row[$key] ?? ''));
            if ($url === '') {
                continue;
            }
            if (preg_match('#/jorf/id/(JORFTEXT[0-9A-Z]+)#i', $url, $m)) {
                return self::normalizeConsultId($m[1]);
            }
            if (preg_match('#/dossierlegislatif/(JORFDOLE[0-9A-Z]+)#i', $url, $m)) {
                return self::normalizeConsultId($m[1]);
            }
        }

        return null;
    }

    /**
     * Strip Légifrance version suffixes (e.g. {@code _01-01-2999}) from stored ids.
     *
     * /consult/jorf expects the bare chronical id ({@code JORFTEXT000053981016}), not the
     * versioned {@code titles[].id} value persisted in older ingests.
     */
    public static function normalizeConsultId(string $id): string
    {
        $id = strtoupper(trim($id));
        if (preg_match('/^(JORFTEXT\d+)(?:_\d{2}-\d{2}-\d{4})?$/', $id, $m)) {
            return $m[1];
        }
        if (preg_match('/^(JORFDOLE\d+)(?:_\d{2}-\d{2}-\d{4})?$/', $id, $m)) {
            return $m[1];
        }
        if (preg_match('/^(LEGITEXT\d+)(?:_.*)?$/', $id, $m)) {
            return $m[1];
        }

        return $id;
    }

    public static function isJorfTextCid(string $id): bool
    {
        $id = strtoupper(trim($id));
        return self::isPlainJorfTextCid($id)
            || (str_starts_with($id, 'JORFTEXT')
                && preg_match('/^JORFTEXT\d+(?:_\d{2}-\d{2}-\d{4})?$/i', trim($id)) === 1)
            || (str_starts_with($id, 'JORFDOLE')
                && preg_match('/^JORFDOLE\d+(?:_\d{2}-\d{2}-\d{4})?$/i', trim($id)) === 1);
    }

    public static function isPlainJorfTextCid(string $id): bool
    {
        $id = strtoupper(trim($id));
        return preg_match('/^JORFTEXT\d+$/i', $id) === 1
            || preg_match('/^JORFDOLE\d+$/i', $id) === 1;
    }

    public static function isConsultId(string $id): bool
    {
        $id = strtoupper(trim($id));

        return self::isJorfTextCid($id)
            || str_starts_with($id, 'LEGITEXT')
            || str_starts_with($id, 'JORFDOLE')
            || preg_match('/^[A-Z]{4}\d{9}[A-Z]$/', $id) === 1;
    }

    /**
     * @param array<string, mixed> $hit
     */
    public static function jorfTextCidFromSearchHit(array $hit): string
    {
        $titles = $hit['titles'] ?? [];
        if (is_array($titles) && $titles !== []) {
            $first = $titles[0];
            if (is_array($first)) {
                foreach (['cid', 'id'] as $key) {
                    $candidate = self::normalizeConsultId(trim((string)($first[$key] ?? '')));
                    if (self::isPlainJorfTextCid($candidate)) {
                        return $candidate;
                    }
                }
            }
        }

        $jorfText = self::normalizeConsultId(trim((string)($hit['jorfText'] ?? '')));
        if (self::isPlainJorfTextCid($jorfText)) {
            return $jorfText;
        }

        $nor = trim((string)($hit['nor'] ?? ''));

        return $nor;
    }

    private function fetchFromJorfConsult(string $textCid, ?string &$failureReason): ?string
    {
        $textCid = self::normalizeConsultId($textCid);
        $resp = $this->client->postJsonResponse('/consult/jorf', ['textCid' => $textCid]);
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
        $consultId = self::normalizeConsultId($consultId);
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

        if (self::isPlainJorfTextCid($consultId)) {
            $queries[] = ['typeChamp' => 'ALL', 'typeRecherche' => 'EXACTE', 'valeur' => $consultId, 'pageSize' => 10];
            $queries[] = ['typeChamp' => 'ALL', 'typeRecherche' => 'UN_DES_MOTS', 'valeur' => $consultId, 'pageSize' => 10];
            if (preg_match('/JORFTEXT0*(\d+)$/i', $consultId, $m)) {
                $queries[] = ['typeChamp' => 'NUM', 'typeRecherche' => 'EXACTE', 'valeur' => $m[1], 'pageSize' => 10];
            }
        } elseif (str_starts_with($consultId, 'LEGITEXT')) {
            $queries[] = ['typeChamp' => 'ALL', 'typeRecherche' => 'EXACTE', 'valeur' => $consultId, 'pageSize' => 10];
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
        $want = self::normalizeConsultId($consultId);

        $nor = strtoupper(trim((string)($hit['nor'] ?? '')));
        if ($nor !== '' && $nor === $want) {
            return true;
        }

        foreach ((array)($hit['titles'] ?? []) as $titleRow) {
            if (!is_array($titleRow)) {
                continue;
            }
            foreach (['cid', 'id'] as $key) {
                $candidate = self::normalizeConsultId((string)($titleRow[$key] ?? ''));
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

    /**
     * Parse and extract only the essential chamber bill numbers (e.g. Sénat/Assemblée nationale)
     * from the raw travaux préparatoires text to compose a highly readable, human-friendly metadata brief.
     */
    public static function extractDeliberationBrief(string $prepWork): string
    {
        $prepWork = trim($prepWork);
        if ($prepWork === '') {
            return '';
        }

        $matches = [];
        // Match Senate or Assembly Project/Proposition de loi numbers
        $pattern = '/(Assemblée nationale|Sénat)\s*:\s*(Projet de loi|Proposition de loi)[^;]*?n°\s*(\d+)/ui';
        if (preg_match_all($pattern, $prepWork, $m, PREG_SET_ORDER)) {
            $lines = [];
            foreach ($m as $item) {
                $chamber = trim($item[1]);
                $type = trim($item[2]);
                $num = trim($item[3]);
                $lines[] = "{$chamber} : {$type} n° {$num}";
            }
            if ($lines !== []) {
                return implode(' • ', array_unique($lines));
            }
        }

        return '';
    }
}
