<?php

declare(strict_types=1);

namespace Seismo\Plugin\LexBgerAtf;

use Seismo\Service\Http\BaseClient;
use Seismo\Service\SourceFetcherInterface;

/**
 * Official BGE Leitentscheide from search.bger.ch (Eurospider ATF index).
 *
 * Source: {@see https://search.bger.ch/ext/eurospider/live/de/php/clir/http/index_atf.php}
 * — not entscheidsuche.ch (that mirror is incomplete for recent BGE).
 */
final class LexBgerAtfPlugin implements SourceFetcherInterface
{
    private const DEFAULT_BASE = 'https://search.bger.ch/ext/eurospider/live/de/php/clir/http/';
    private const MAX_LIMIT = 200;
    /** Calendar year ≈ BGE band number + offset (e.g. 151 → 2025). */
    private const BGE_YEAR_OFFSET = 1874;

    public function __construct(
        private readonly BaseClient $http = new BaseClient(),
    ) {
    }

    public function getIdentifier(): string
    {
        return 'jus_bge';
    }

    public function getLabel(): string
    {
        return 'Jus: BGE Leitentscheide (bger.ch)';
    }

    public function getEntryType(): string
    {
        return 'lex_item';
    }

    public function getConfigKey(): string
    {
        return 'ch_bge';
    }

    public function getMinIntervalSeconds(): int
    {
        return 6 * 60 * 60;
    }

    public function fetch(array $config): array
    {
        $base = rtrim((string)($config['base_url'] ?? self::DEFAULT_BASE), '/') . '/';
        $lang = strtolower(trim((string)($config['lang'] ?? 'de')));
        if (!in_array($lang, ['de', 'fr', 'it'], true)) {
            $lang = 'de';
        }
        $lookback = max(1, min(365, (int)($config['lookback_days'] ?? 365)));
        $limit = max(1, min(self::MAX_LIMIT, (int)($config['limit'] ?? 100)));
        $cutoffDate = gmdate('Y-m-d', strtotime('-' . $lookback . ' days'));

        $indexHtml = $this->fetchHtml($base . 'index_atf.php?lang=' . rawurlencode($lang));
        $yearNumbers = $this->discoverBgeYearNumbers($indexHtml);
        if ($yearNumbers === []) {
            return [];
        }

        $yearsToScan = $this->selectYearsForLookback($yearNumbers, $cutoffDate);
        $docRefs = [];
        foreach ($yearsToScan as $bgeYear) {
            foreach ($this->discoverVolumesForYear($indexHtml, $bgeYear) as $volume) {
                $volHtml = $this->fetchHtml(
                    $base . 'index_atf.php?year=' . $bgeYear . '&volume=' . rawurlencode($volume) . '&lang=' . rawurlencode($lang)
                );
                foreach ($this->extractDocumentRefs($volHtml) as $ref) {
                    $docRefs[$ref['celex']] = $ref;
                }
            }
        }

        if ($docRefs === []) {
            return [];
        }

        $refs = array_values($docRefs);
        usort($refs, static fn (array $a, array $b): int => self::compareCelex($b['celex'], $a['celex']));
        $filtered = [];
        foreach ($refs as $r) {
            if (($r['document_date'] ?? '0000-01-01') >= $cutoffDate) {
                $filtered[] = $r;
            }
        }
        $refs = $filtered;
        $refs = array_slice($refs, 0, $limit);

        $rows = [];
        foreach ($refs as $ref) {
            $url = $this->documentUrl($base, $ref['doc_id_raw'], $lang);
            $meta = $this->fetchDocumentMeta($url, $ref['celex']);
            $title = $meta['title'] !== '' ? $meta['title'] : self::celexToDisplayLabel($ref['celex']);
            $docDate = $meta['document_date'] ?? $ref['document_date'];
            $rows[] = [
                'celex'         => $ref['celex'],
                'title'         => mb_substr($title, 0, 65535),
                'description'   => $meta['description'],
                'document_date' => $docDate,
                'document_type' => 'Leitentscheid',
                'eurlex_url'    => mb_substr($url, 0, 500),
                'work_uri'      => mb_substr($url, 0, 500),
                'source'        => $this->getConfigKey(),
            ];
        }

        return $rows;
    }

    private function fetchHtml(string $url): string
    {
        $res = $this->http->getWebPage($url);
        if (!$res->isOk() || $res->body === '') {
            throw new \RuntimeException('HTTP ' . $res->status . ' fetching ' . $url);
        }

        return $res->body;
    }

    /**
     * @return list<int>
     */
    private function discoverBgeYearNumbers(string $indexHtml): array
    {
        if (!preg_match_all('/year=(\d+)/', $indexHtml, $m)) {
            return [];
        }
        $years = array_map('intval', $m[1]);
        $years = array_values(array_unique(array_filter($years, static fn (int $y): bool => $y >= 80 && $y <= 300)));

        return $years;
    }

    /**
     * @param list<int> $yearNumbers
     * @return list<int>
     */
    private function selectYearsForLookback(array $yearNumbers, string $cutoffDate): array
    {
        rsort($yearNumbers);
        $cutoffYear = (int)substr($cutoffDate, 0, 4);
        $minBgeYear = $cutoffYear - self::BGE_YEAR_OFFSET - 1;

        $out = [];
        foreach ($yearNumbers as $y) {
            if ($y >= $minBgeYear) {
                $out[] = $y;
            }
        }

        return $out !== [] ? $out : array_slice($yearNumbers, 0, 2);
    }

    /**
     * @return list<string> Roman volume ids (I, II, …)
     */
    private function discoverVolumesForYear(string $indexHtml, int $bgeYear): array
    {
        $pattern = '/index_atf\.php\?year=' . $bgeYear . '(?:&amp;|&)volume=([IVX]+)/i';
        if (!preg_match_all($pattern, $indexHtml, $m)) {
            return ['I', 'II', 'III', 'IV', 'V'];
        }
        $vols = array_values(array_unique($m[1]));

        return $vols !== [] ? $vols : ['I'];
    }

    /**
     * @return list<array{celex: string, doc_id_raw: string, document_date: string}>
     */
    private function extractDocumentRefs(string $html): array
    {
        if (!preg_match_all('/highlight_docid=([^"\'&\s>]+)/', $html, $m)) {
            return [];
        }

        $out = [];
        foreach ($m[1] as $encoded) {
            $raw = rawurldecode($encoded);
            $celex = $this->celexFromAtfId($raw);
            if ($celex === '') {
                continue;
            }
            $out[] = [
                'celex'         => $celex,
                'doc_id_raw'    => $encoded,
                'document_date' => $this->documentDateFromCelex($celex),
            ];
        }

        return $out;
    }

    private function celexFromAtfId(string $atfId): string
    {
        if (preg_match('#atf://([^:]+):#i', $atfId, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    /** Approximate calendar year for lookback filtering when the document page has no date. */
    private function documentDateFromCelex(string $celex): string
    {
        if (preg_match('/^(\d+)-[IVX]+-/i', $celex, $m)) {
            $calYear = (int)$m[1] + self::BGE_YEAR_OFFSET;

            return $calYear . '-12-31';
        }

        return gmdate('Y-m-d');
    }

    public static function celexToDisplayLabel(string $celex): string
    {
        if (preg_match('/^(\d+)-([IVX]+)-(\d+)$/i', $celex, $m)) {
            return $m[1] . ' ' . strtoupper($m[2]) . ' ' . $m[3];
        }

        return $celex;
    }

    private function documentUrl(string $base, string $encodedDocId, string $lang): string
    {
        return $base . 'index.php?highlight_docid=' . $encodedDocId
            . '&lang=' . rawurlencode($lang) . '&type=show_document';
    }

    /**
     * @return array{title: string, description: ?string, document_date: ?string}
     */
    private function fetchDocumentMeta(string $url, string $celex): array
    {
        try {
            $html = $this->fetchHtml($url);
        } catch (\Throwable) {
            return ['title' => '', 'description' => null, 'document_date' => null];
        }

        return $this->parseDocumentMeta($html, $celex);
    }

    /**
     * @return array{title: string, description: ?string, document_date: ?string}
     */
    private function parseDocumentMeta(string $html, string $celex): array
    {
        $title = '';
        $description = null;
        $documentDate = null;
        $citation = self::celexToDisplayLabel($celex);

        preg_match_all('#<div class="paraatf">([^<]+)</div>#', $html, $m);

        foreach ($m[1] ?? [] as $rawPara) {
            $para = html_entity_decode(trim(strip_tags($rawPara)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $para = preg_replace('/\s+/u', ' ', $para) ?? $para;
            if ($para === '' || $para === $citation || preg_match('/^\d+\s+[IVX]+\s+\d+$/u', $para)) {
                continue;
            }
            if (preg_match('/vom\s+\d{1,2}\.\s+/u', $para)) {
                $parsed = $this->parseFrenchGermanDateFromLine($para);
                if ($parsed !== null) {
                    $documentDate = $parsed;
                }
                continue;
            }
            if (mb_strlen($para) >= 40 && ($title === '' || str_contains($para, 'i.S.'))) {
                $title = $para;
            }
        }

        if (preg_match('#id="regeste"[^>]*>(.*?)(?=<a\s+name=)#s', $html, $reg)) {
            $regText = html_entity_decode(strip_tags($reg[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $regText = trim(preg_replace('/\s+/u', ' ', $regText) ?? '');
            if ($regText !== '') {
                $description = mb_substr($regText, 0, 4000);
            }
        }

        if ($title === '' && preg_match('/<title>\s*([^<]+?)\s*<\/title>/i', $html, $tm)) {
            $pageTitle = html_entity_decode(trim($tm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($pageTitle !== $citation) {
                $title = $pageTitle;
            }
        }

        return [
            'title'         => $title,
            'description'   => $description,
            'document_date' => $documentDate,
        ];
    }

    private function parseFrenchGermanDateFromLine(string $line): ?string
    {
        if (!preg_match('/vom\s+(\d{1,2})\.\s+([A-Za-zäöüÄÖÜéèêà]+)\s+(\d{4})/u', $line, $m)) {
            return null;
        }

        static $months = [
            'januar' => '01', 'janvier' => '01', 'gennaio' => '01',
            'februar' => '02', 'février' => '02', 'fevrier' => '02', 'febbraio' => '02',
            'märz' => '03', 'maerz' => '03', 'mars' => '03', 'marzo' => '03',
            'april' => '04', 'avril' => '04',
            'mai' => '05', 'maggio' => '05',
            'juni' => '06', 'juin' => '06', 'giugno' => '06',
            'juli' => '07', 'juillet' => '07', 'luglio' => '07',
            'august' => '08', 'août' => '08', 'aout' => '08', 'agosto' => '08',
            'september' => '09', 'septembre' => '09', 'settembre' => '09',
            'oktober' => '10', 'octobre' => '10', 'ottobre' => '10',
            'november' => '11', 'novembre' => '11',
            'dezember' => '12', 'décembre' => '12', 'decembre' => '12', 'dicembre' => '12',
        ];

        $monthKey = mb_strtolower($m[2], 'UTF-8');
        $month = $months[$monthKey] ?? null;
        if ($month === null) {
            return null;
        }

        return sprintf('%04d-%s-%02d', (int)$m[3], $month, (int)$m[1]);
    }

    private static function compareCelex(string $a, string $b): int
    {
        if (preg_match('/^(\d+)-([IVX]+)-(\d+)/i', $a, $ma)
            && preg_match('/^(\d+)-([IVX]+)-(\d+)/i', $b, $mb)) {
            $cmp = (int)$ma[1] <=> (int)$mb[1];
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = self::romanVolumeRank($ma[2]) <=> self::romanVolumeRank($mb[2]);
            if ($cmp !== 0) {
                return $cmp;
            }

            return (int)$ma[3] <=> (int)$mb[3];
        }

        return strcmp($a, $b);
    }

    private static function romanVolumeRank(string $roman): int
    {
        static $map = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5];

        return $map[strtoupper($roman)] ?? 0;
    }
}
