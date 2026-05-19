<?php

declare(strict_types=1);

namespace Seismo\Plugin\LexEu;

use EasyRdf\Sparql\Client;
use Seismo\Service\SourceFetcherInterface;

/**
 * EU legislation via Publications Office SPARQL (EUR-Lex data graph).
 */
final class LexEuPlugin implements SourceFetcherInterface
{
    /** EU / Fedlex-style language authority codes (injection-safe allowlist). */
    private const LANGUAGE_CODES = [
        'BUL', 'SPA', 'CES', 'DAN', 'DEU', 'EST', 'ELL', 'ENG', 'FIN', 'FRA', 'GLE',
        'HRV', 'HUN', 'ITA', 'LAV', 'LIT', 'MLT', 'NLD', 'POL', 'POR', 'RON', 'SLK',
        'SLV', 'SWE', 'ROH',
    ];

    public function getIdentifier(): string
    {
        return 'lex_eu';
    }

    public function getLabel(): string
    {
        return 'EUR-Lex (EU SPARQL)';
    }

    public function getEntryType(): string
    {
        return 'lex_item';
    }

    public function getConfigKey(): string
    {
        return 'eu';
    }

    public function getMinIntervalSeconds(): int
    {
        return 4 * 60 * 60;
    }

    public static function normalizeLanguage(string $raw): string
    {
        $u = strtoupper(trim($raw));

        return in_array($u, self::LANGUAGE_CODES, true) ? $u : 'ENG';
    }

    /**
     * Normalise configured document class to a full CDM class IRI (curie cdm:… only).
     */
    public static function documentClassToIri(string $raw): string
    {
        $t = trim($raw);
        if ($t === '') {
            return 'http://publications.europa.eu/ontology/cdm#legislation_secondary';
        }
        if (!preg_match('/^cdm:([A-Za-z0-9_-]+)$/', $t, $m)) {
            throw new \InvalidArgumentException(
                'Invalid EU document_class — use a curie like cdm:legislation_secondary (letters, digits, hyphen, underscore only).'
            );
        }

        return 'http://publications.europa.eu/ontology/cdm#' . $m[1];
    }

    /**
     * Grey-pill label for EUR-Lex items: prefers Cellar {@see cdm:resource_legal_type}
     * (R/L/D/… ) and falls back to the same letter parsed from canonical CELEX.
     */
    public static function resolveEuDocumentTypeLabel(string $celexId, ?string $cellarResourceLegalLetter): string
    {
        $letter = trim((string)$cellarResourceLegalLetter);
        if ($letter !== '') {
            $letter = strtoupper(mb_substr($letter, 0, 1));
        } else {
            $parsed = self::resourceLegalTypeLetterFromCelex($celexId);
            $letter = $parsed ?? '';
        }
        $label = self::resourceLegalTypeLetterToLabel($letter);

        return $label !== '' ? $label : 'EU legislation';
    }

    /** @return string|null */
    private static function resourceLegalTypeLetterFromCelex(string $celexId): ?string
    {
        $u = strtoupper(trim($celexId));
        if ($u !== '' && preg_match('/^\d\d{4}([A-Z])/u', $u, $m)) {
            return $m[1];
        }

        return null;
    }

    private static function resourceLegalTypeLetterToLabel(string $letter): string
    {
        return match ($letter) {
            'R' => 'Regulation',
            'L' => 'Directive',
            'D' => 'Decision',
            'B' => 'Budget',
            'F', 'H', 'I', 'M' => 'Recommendation',
            'J' => 'Joint action',
            'A', 'Z' => 'International agreement',
            'E', 'Y' => 'Opinion',
            'C', 'X' => 'Decision',
            'G' => 'Budget',
            'N' => 'Notice',
            'P' => 'Protocol',
            'S' => 'Statement',
            'T' => 'Treaty',
            'O', 'W' => 'EU legal act',
            default => '',
        };
    }

    public function fetch(array $config): array
    {
        $lookback = max(1, (int)($config['lookback_days'] ?? 90));
        $sinceDate = gmdate('Y-m-d', strtotime('-' . $lookback . ' days'));
        $lang = self::normalizeLanguage((string)($config['language'] ?? 'ENG'));
        $limit = max(1, min((int)($config['limit'] ?? 100), 200));
        $endpoint = trim((string)($config['endpoint'] ?? 'https://publications.europa.eu/webapi/rdf/sparql'));
        if ($endpoint === '' || !preg_match('#^https://#i', $endpoint)) {
            throw new \InvalidArgumentException('EU SPARQL endpoint must be an https URL.');
        }

        $classIri = self::documentClassToIri((string)($config['document_class'] ?? 'cdm:legislation_secondary'));
        $langUri = 'http://publications.europa.eu/resource/authority/language/' . $lang;
        $until = gmdate('Y-m-d', strtotime('+1 day'));

        // Expression titles attach via expression_belongs_to_work → work (not
        // work_has_expression → expression) for Cellar budgets / many act types.
        $sparqlQuery = '
        PREFIX cdm: <http://publications.europa.eu/ontology/cdm#>
        PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>

        SELECT ?work ?celex ?docDate (MAX(?titlePick) AS ?title) (SAMPLE(?legalLetter) AS ?legalLetter)
        WHERE {
            ?work a <' . $classIri . '> .
            ?work cdm:work_id_document ?celex .
            ?work cdm:work_date_document ?docDate .
            OPTIONAL { ?work cdm:resource_legal_type ?legalLetter . }
            FILTER(REGEX(STR(?celex), "^celex:[0-9]"))
            FILTER(?docDate >= "' . $sinceDate . '"^^xsd:date && ?docDate <= "' . $until . '"^^xsd:date)
            {
                ?ex cdm:expression_belongs_to_work ?work .
                ?ex cdm:expression_title ?titlePick .
                ?ex cdm:expression_uses_language <' . $langUri . '> .
            } UNION {
                ?ex2 cdm:expression_belongs_to_work ?work .
                ?ex2 cdm:expression_title ?titlePick .
                FILTER NOT EXISTS {
                    ?ex3 cdm:expression_belongs_to_work ?work .
                    ?ex3 cdm:expression_uses_language <' . $langUri . '> .
                }
            }
        }
        GROUP BY ?work ?celex ?docDate
        ORDER BY DESC(?docDate)
        LIMIT ' . $limit . '
    ';

        $sparql = new Client($endpoint);
        $results = $sparql->query($sparqlQuery);

        $rows = [];
        foreach ($results as $row) {
            $workUri = trim((string)($row->work ?? ''));
            $celexRaw = trim((string)($row->celex ?? ''));
            if ($workUri === '' || !str_starts_with($celexRaw, 'celex:')) {
                continue;
            }
            $celexId = strtoupper(substr($celexRaw, strlen('celex:')));
            if ($celexId === '' || strlen($celexId) > 64) {
                continue;
            }
            $title = trim((string)($row->title ?? ''));
            if ($title === '') {
                $title = $celexId;
            }
            $langPath = self::eurLexPathLang($lang);
            $eurlexUrl = 'https://eur-lex.europa.eu/legal-content/' . $langPath . '/TXT/HTML/?uri=CELEX:' . rawurlencode($celexId);

            $docLabel = self::resolveEuDocumentTypeLabel(
                $celexId,
                trim((string)($row->legalLetter ?? '')) !== '' ? trim((string)$row->legalLetter) : null
            );

            $rows[] = [
                'celex' => $celexId,
                'title' => $title,
                'description' => null,
                'document_date' => (string)($row->docDate ?? ''),
                'document_type' => $docLabel,
                'eurlex_url' => $eurlexUrl,
                'work_uri' => $workUri,
                'source' => 'eu',
            ];
        }

        // Second bounded SPARQL — enrich each work with its EuroVoc subject
        // labels. Kept as a separate query (not OPTIONAL/GROUP_CONCAT in the
        // primary fetch) so a slow Cellar response on the deep concept join
        // cannot collapse the main legislation feed. Failure is logged and
        // items are still returned without a description.
        $rows = self::enrichWithEurovocSubjects($sparql, $rows, $lang);

        return $rows;
    }

    /**
     * Fetch `cdm:work_is_about_concept_eurovoc` `skos:prefLabel` for every work
     * we just returned and concatenate the labels into a short `Subjects: …`
     * description. EUR-Lex Cellar federates EuroVoc, so a single second query
     * against the same endpoint is enough — no separate EuroVoc client.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function enrichWithEurovocSubjects(Client $sparql, array $rows, string $lang3): array
    {
        if ($rows === []) {
            return $rows;
        }

        $workUris = [];
        foreach ($rows as $r) {
            $u = trim((string)($r['work_uri'] ?? ''));
            if ($u !== '') {
                $workUris[$u] = true;
            }
        }
        if ($workUris === []) {
            return $rows;
        }

        $lang2 = self::eurLexPathLang($lang3);
        $values = implode(' ', array_map(static fn (string $u): string => '<' . $u . '>', array_keys($workUris)));

        // The OPTIONAL English label gives us a graceful fallback for EuroVoc
        // concepts that aren't translated in the configured language — keeps
        // the description non-empty for DE/FR/IT operators on niche concepts.
        $sq = '
        PREFIX cdm: <http://publications.europa.eu/ontology/cdm#>
        PREFIX skos: <http://www.w3.org/2004/skos/core#>

        SELECT ?work ?primary ?fallback WHERE {
            VALUES ?work { ' . $values . ' }
            ?work cdm:work_is_about_concept_eurovoc ?ev .
            OPTIONAL {
                ?ev skos:prefLabel ?primary .
                FILTER(LANGMATCHES(LANG(?primary), "' . $lang2 . '"))
            }
            OPTIONAL {
                ?ev skos:prefLabel ?fallback .
                FILTER(LANGMATCHES(LANG(?fallback), "EN"))
            }
        }';

        try {
            $subjectResults = $sparql->query($sq);
        } catch (\Throwable $e) {
            error_log('LexEuPlugin EuroVoc subject fetch failed (items kept without description): ' . $e->getMessage());
            return $rows;
        }

        /** @var array<string, list<string>> $byWork */
        $byWork = [];
        foreach ($subjectResults as $sr) {
            $w = trim((string)($sr->work ?? ''));
            if ($w === '') {
                continue;
            }
            $label = trim((string)($sr->primary ?? ''));
            if ($label === '') {
                $label = trim((string)($sr->fallback ?? ''));
            }
            if ($label === '') {
                continue;
            }
            $byWork[$w] ??= [];
            if (!in_array($label, $byWork[$w], true)) {
                $byWork[$w][] = $label;
            }
        }

        foreach ($rows as &$r) {
            $w = trim((string)($r['work_uri'] ?? ''));
            $labels = $byWork[$w] ?? [];
            if ($labels === []) {
                continue;
            }
            sort($labels, SORT_NATURAL | SORT_FLAG_CASE);
            $r['description'] = 'Subjects: ' . implode(' • ', array_slice($labels, 0, 12));
        }
        unset($r);

        return $rows;
    }

    private static function eurLexPathLang(string $authority3): string
    {
        $map = [
            'BUL' => 'BG', 'SPA' => 'ES', 'CES' => 'CS', 'DAN' => 'DA', 'DEU' => 'DE',
            'EST' => 'ET', 'ELL' => 'EL', 'ENG' => 'EN', 'FIN' => 'FI', 'FRA' => 'FR',
            'GLE' => 'GA', 'HRV' => 'HR', 'HUN' => 'HU', 'ITA' => 'IT', 'LAV' => 'LV',
            'LIT' => 'LT', 'MLT' => 'MT', 'NLD' => 'NL', 'POL' => 'PL', 'POR' => 'PT',
            'RON' => 'RO', 'SLK' => 'SK', 'SLV' => 'SL', 'SWE' => 'SV', 'ROH' => 'RM',
        ];

        return $map[$authority3] ?? 'EN';
    }
}
