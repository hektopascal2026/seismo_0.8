<?php

declare(strict_types=1);

namespace Seismo\Plugin\LexFedlex;

use EasyRdf\Sparql\Client;
use Seismo\Service\SourceFetcherInterface;

/**
 * Swiss federal legislation via Fedlex SPARQL (ported from 0.4 refreshFedlexItems).
 * Optionally ingests federal consultation procedures (JOLux `Consultation`)
 * lex_items family with document_type Vernehmlassung.
 *
 * Rows without a title or a parseable Fedlex act URI are skipped (normalisation contract).
 */
final class LexFedlexPlugin implements SourceFetcherInterface
{
    /**
     * Fedlex SPARQL language authority codes (EU Publications Office). Used for SPARQL injection defense.
     *
     * @var list<string>
     */
    public const FEDLEX_LANGUAGE_CODES = ['DEU', 'FRA', 'ITA', 'ENG', 'ROH'];

    public const DOCUMENT_TYPE_VERNEHM = 'Vernehmlassung';

    public function getIdentifier(): string
    {
        return 'fedlex';
    }

    public function getLabel(): string
    {
        return 'Swiss Fedlex';
    }

    public function getEntryType(): string
    {
        return 'lex_item';
    }

    public function getConfigKey(): string
    {
        return 'ch';
    }

    /**
     * 4 hours. Fedlex publications change on a daily-to-weekly cadence; 4h is
     * plenty fresh for a legislation monitor and keeps SPARQL load modest
     * when the master cron fires every 5 minutes. User-initiated refresh
     * from the Lex page bypasses this (force=true).
     */
    public function getMinIntervalSeconds(): int
    {
        return 4 * 60 * 60;
    }

    /**
     * Normalise config language to a safe Fedlex authority code (defaults to DEU).
     */
    public static function normalizeFedlexLanguage(string $raw): string
    {
        $u = strtoupper(trim($raw));

        return in_array($u, self::FEDLEX_LANGUAGE_CODES, true) ? $u : 'DEU';
    }

    /** ISO 639-1 segment for Fedlex WWW URLs ({@see https://www.fedlex.admin.ch/eli/.../de}). */
    public static function authorityToFedlexLangPath(string $authority): string
    {
        return match (strtoupper(trim($authority))) {
            'FRA' => 'fr',
            'ITA' => 'it',
            'ENG' => 'en',
            'ROH' => 'rm',
            default => 'de',
        };
    }

    public function fetch(array $config): array
    {
        $rows = self::fetchActs($config);

        if (!self::ingestVernehmlassungen($config)) {
            return $rows;
        }

        return array_merge($rows, self::fetchConsultations($config));
    }

    /**
     * Whether consultation procedures should be embedded in this Fedlex refresh.
     *
     * @param array<string, mixed> $config Plugin `ch` block.
     */
    public static function ingestVernehmlassungen(array $config): bool
    {
        return !array_key_exists('ingest_vernehmlassungen', $config)
            || $config['ingest_vernehmlassungen'] !== false;
    }

    /**
     * @param array<string, mixed> $config Plugin `ch` block.
     * @return array<int, array<string, mixed>>
     */
    private static function fetchActs(array $config): array
    {
        $lookback = (int)($config['lookback_days'] ?? 90);
        $sinceDate = date('Y-m-d', strtotime('-' . $lookback . ' days'));
        $lang = self::normalizeFedlexLanguage((string)($config['language'] ?? 'DEU'));
        $langPath = self::authorityToFedlexLangPath($lang);
        $limit = (int)($config['limit'] ?? 100);
        $limit = max(1, min($limit, 200));
        $endpoint = $config['endpoint'] ?? 'https://fedlex.data.admin.ch/sparqlendpoint';

        $resourceTypes = $config['resource_types'] ?? [];
        $typeIds = array_map(static function ($rt) {
            return is_array($rt) ? (int)$rt['id'] : (int)$rt;
        }, $resourceTypes);

        if ($typeIds === []) {
            $typeIds = [21, 22, 29, 26, 27, 28, 8, 9, 10, 31, 32];
        }

        $typeFilter = implode(', ', array_map(static function (int $n) {
            return '<https://fedlex.data.admin.ch/vocabulary/resource-type/' . $n . '>';
        }, $typeIds));

        $until = date('Y-m-d', strtotime('+1 year'));

        // Slice C (May 2026): enrich Fedlex acts with responsibilityOf
        // (responsible federal office, 100% coverage on recent SR acts) and
        // classifiedByTaxonomyEntry (parent / classifying act's full title,
        // ~97% coverage). Both come back as ?label triples on the entity URI
        // and are filtered to the configured short language tag via
        // LANGMATCHES. Folded into the primary fetch via OPTIONAL + GROUP BY
        // (SAMPLE for responsibility, GROUP_CONCAT for taxonomy) so a single
        // round-trip per refresh tick still returns one row per act.
        $sparqlQuery = '
        PREFIX jolux: <http://data.legilux.public.lu/resource/ontology/jolux#>
        PREFIX skos:  <http://www.w3.org/2004/02/skos/core#>
        PREFIX xsd:   <http://www.w3.org/2001/XMLSchema#>

        SELECT
          ?act ?title ?pubDate ?typeDoc
          (SAMPLE(?respLabelRaw) AS ?respLabel)
          (GROUP_CONCAT(DISTINCT ?taxLabelRaw; SEPARATOR=" • ") AS ?taxLabel)
        WHERE {
            ?act a jolux:Act .
            ?act jolux:publicationDate ?pubDate .
            ?act jolux:typeDocument ?typeDoc .
            ?act jolux:isRealizedBy ?expr .
            ?expr jolux:title ?title .
            ?expr jolux:language <http://publications.europa.eu/resource/authority/language/' . $lang . '> .
            FILTER(?typeDoc IN (' . $typeFilter . '))
            FILTER(?pubDate >= "' . $sinceDate . '"^^xsd:date && ?pubDate <= "' . $until . '"^^xsd:date)
            OPTIONAL {
                ?act jolux:responsibilityOf ?resp .
                ?resp skos:prefLabel ?respLabelRaw .
                FILTER(LANGMATCHES(LANG(?respLabelRaw), "' . $langPath . '"))
            }
            OPTIONAL {
                ?act jolux:classifiedByTaxonomyEntry ?tx .
                ?tx skos:prefLabel ?taxLabelRaw .
                FILTER(LANGMATCHES(LANG(?taxLabelRaw), "' . $langPath . '"))
            }
        }
        GROUP BY ?act ?title ?pubDate ?typeDoc
        ORDER BY DESC(?pubDate)
        LIMIT ' . $limit . '
    ';

        $sparql = new Client($endpoint);
        $results = $sparql->query($sparqlQuery);

        $rows = [];
        $fedlexHost = 'https://fedlex.data.admin.ch/';
        foreach ($results as $row) {
            $actUri = trim((string)$row->act);
            if ($actUri === '' || !str_starts_with($actUri, $fedlexHost)) {
                continue;
            }

            $title = trim((string)$row->title);
            if ($title === '') {
                continue;
            }

            $eliId = trim(str_replace($fedlexHost, '', $actUri), '/');
            if ($eliId === '') {
                continue;
            }

            $dateDoc = (string)$row->pubDate;
            $typeDoc = (string)$row->typeDoc;

            $docType = self::parseFedlexType($typeDoc);
            $fedlexUrl = 'https://www.fedlex.admin.ch/' . $eliId . '/' . $langPath;

            $respLabel = trim((string)($row->respLabel ?? ''));
            $taxLabel  = trim((string)($row->taxLabel ?? ''));
            $description = self::composeFedlexDescription($title, $respLabel, $taxLabel);

            $rows[] = [
                'celex' => $eliId,
                'title' => $title,
                'description' => $description,
                'document_date' => $dateDoc,
                'document_type' => $docType,
                'eurlex_url' => $fedlexUrl,
                'work_uri' => $actUri,
                'source' => 'ch',
            ];
        }

        return $rows;
    }

    /**
     * Draft project consultations reachable as jolux:Consultation (+ cons-open dates when present).
     *
     * @param array<string, mixed> $config Plugin `ch` block.
     * @return array<int, array<string, mixed>>
     */
    private static function fetchConsultations(array $config): array
    {
        $lookback = (int)($config['lookback_days'] ?? 90);
        $sinceDate = date('Y-m-d', strtotime('-' . $lookback . ' days'));
        $until = date('Y-m-d', strtotime('+3 years'));

        $lang = self::normalizeFedlexLanguage((string)($config['language'] ?? 'DEU'));
        $langTag = self::authorityToFedlexLangPath($lang);
        $langPath = $langTag;
        $limit = (int)($config['limit'] ?? 100);
        $limit = max(1, min($limit, 200));
        $endpoint = $config['endpoint'] ?? 'https://fedlex.data.admin.ch/sparqlendpoint';

        $sparqlQuery = '
PREFIX jolux: <http://data.legilux.public.lu/resource/ontology/jolux#>
PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
PREFIX dcterms: <http://purl.org/dc/terms/>

SELECT ?cons ?draft ?phaseStart ?phaseEnd ?eff ?desc ?status
WHERE {
  {
    SELECT ?cons ?draft ?phaseStart ?phaseEnd ?eff ?status
    WHERE {
      BIND ("' . $sinceDate . '"^^xsd:date AS ?since )
      BIND ("' . $until . '"^^xsd:date AS ?until )
      ?cons a jolux:Consultation .
      ?draft jolux:draftHasTask ?cons .
      ?cons jolux:consultationStatus ?status .
      OPTIONAL {
        ?cons jolux:hasSubTask ?open .
        FILTER(CONTAINS(STR(?open), "/cons-open"))
        ?open jolux:eventStartDate ?phaseStart .
        ?open jolux:eventEndDate ?phaseEnd .
      }
      OPTIONAL { ?cons dcterms:modified ?modRaw . BIND(xsd:date(?modRaw) AS ?phaseMod) . }
      BIND(COALESCE(?phaseStart, ?phaseMod) AS ?eff)
      FILTER(BOUND(?eff) && ?eff >= ?since && ?eff <= ?until)
    }
    ORDER BY DESC(?eff)
    LIMIT ' . $limit . '
  }
  ?cons jolux:eventDescription ?desc .
  FILTER(LANG(?desc) = "' . $langTag . '")
}
ORDER BY DESC(?eff)
';

        $sparql = new Client($endpoint);
        $results = $sparql->query($sparqlQuery);

        $fedlexHost = 'https://fedlex.data.admin.ch/';
        $grouped = [];
        $orderKeys = [];

        foreach ($results as $row) {
            $consUri = trim((string)$row->cons);
            $draftUri = trim((string)$row->draft);
            $descLit = isset($row->desc) ? trim((string)$row->desc) : '';

            if ($consUri === '' || $draftUri === '' || !str_starts_with($consUri, $fedlexHost)) {
                continue;
            }

            if (!isset($grouped[$consUri])) {
                $orderKeys[] = $consUri;
                $phaseStartRaw = isset($row->phaseStart) ? (string)$row->phaseStart : '';
                $phaseEndRaw = isset($row->phaseEnd) ? (string)$row->phaseEnd : '';
                $effRaw = isset($row->eff) ? (string)$row->eff : '';
                $statusUri = isset($row->status) ? (string)$row->status : '';

                $grouped[$consUri] = [
                    'draft_uri' => $draftUri,
                    'document_date' => $effRaw !== '' ? substr($effRaw, 0, 10) : null,
                    'phase_start' => $phaseStartRaw !== '' ? substr($phaseStartRaw, 0, 10) : null,
                    'phase_end' => $phaseEndRaw !== '' ? substr($phaseEndRaw, 0, 10) : null,
                    'status_uri' => $statusUri,
                    'text_parts' => [],
                ];
            }

            if ($descLit !== '') {
                $grouped[$consUri]['text_parts'][$descLit] = true;
            }
        }

        $out = [];
        foreach ($orderKeys as $consUri) {
            $g = $grouped[$consUri];
            [$title, $body] = self::pickConsultationTitles(array_keys($g['text_parts']));

            if ($title === '') {
                continue;
            }

            $draftRel = trim(str_replace($fedlexHost, '', (string)$g['draft_uri']), '/');
            if ($draftRel === '') {
                continue;
            }

            $celexCons = trim(str_replace($fedlexHost, '', $consUri), '/');
            if ($celexCons === '') {
                continue;
            }

            $descrLines = [];
            if (!empty($g['phase_end'])) {
                $descrLines[] = 'Stellungnahmefrist bis ' . date('d.m.Y', strtotime($g['phase_end'] . 'T00:00:00 UTC'));
            } elseif (!empty($g['phase_start'])) {
                $descrLines[] = 'Vernehmlassung ab ' . date('d.m.Y', strtotime($g['phase_start'] . 'T00:00:00 UTC'));
            }

            $statusLbl = '';
            if ($g['status_uri'] !== '') {
                $statusLbl = basename(parse_url($g['status_uri'], PHP_URL_PATH) ?: '');
            }
            if ($statusLbl !== '') {
                $descrLines[] = 'Status-ID: ' . $statusLbl;
            }

            if ($body !== '') {
                $descrLines[] = $body;
            }
            $description = $descrLines !== [] ? implode("\n\n", $descrLines) : null;

            $out[] = [
                'celex' => $celexCons,
                'title' => $title,
                'description' => $description,
                'document_date' => $g['document_date'],
                'document_type' => self::DOCUMENT_TYPE_VERNEHM,
                'eurlex_url' => 'https://www.fedlex.admin.ch/' . $draftRel . '/' . $langPath,
                'work_uri' => $consUri,
                'source' => 'ch',
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $uniqueDescriptions Distinct literals for one consultation node.
     * @return array{0:string,1:string} title, remainder body prose
     */
    private static function pickConsultationTitles(array $uniqueDescriptions): array
    {
        $filtered = array_values(array_filter($uniqueDescriptions, static function (string $t): bool {
            return mb_strlen(trim($t)) >= 30;
        }));
        if ($filtered === []) {
            $fallback = array_values(array_filter(array_map('trim', $uniqueDescriptions)));

            return $fallback === [] ? ['', ''] : [$fallback[0], implode("\n\n", array_slice($fallback, 1))];
        }

        usort($filtered, static function (string $a, string $b): int {
            return mb_strlen($a) <=> mb_strlen($b);
        });

        $title = (string) array_shift($filtered);

        return [$title, $filtered !== [] ? implode("\n\n", $filtered) : ''];
    }

    /**
     * Compose a description from the SPARQL-enriched responsibility +
     * taxonomy labels (Slice C). Responsibility is the responsible federal
     * office; taxonomy entry is the parent / classifying act's full title
     * (with date), which on amendment acts gives the only hint of the
     * underlying regime. Format: `"<resp> — <tax>"` when both present.
     *
     * The taxonomy label is suppressed when it is verbatim-equal to the
     * fetched act title — for new consolidated acts the two can collide
     * exactly and the redundancy would just bloat the card. Otherwise
     * both are surfaced; the dashboard partial truncates at 300 chars.
     */
    public static function composeFedlexDescription(string $title, string $respLabel, string $taxLabel): ?string
    {
        $resp = trim($respLabel);
        $tax  = trim($taxLabel);

        if ($tax !== '' && $tax === trim($title)) {
            $tax = '';
        }

        if ($resp === '' && $tax === '') {
            return null;
        }

        if ($resp !== '' && $tax !== '') {
            return $resp . ' — ' . $tax;
        }

        return $resp !== '' ? $resp : $tax;
    }

    /**
     * Parse the document type from a Fedlex resource-type URI.
     */
    public static function parseFedlexType(string $typeUri): string
    {
        $map = [
            '21' => 'Bundesgesetz',
            '22' => 'Dringl. Bundesgesetz',
            '29' => 'Verordnung BR',
            '26' => 'Departementsverordnung',
            '27' => 'Amtsverordnung',
            '28' => 'Verordnung BV',
            '8'  => 'Bundesbeschluss',
            '9'  => 'Bundesbeschluss',
            '10' => 'Bundesbeschluss',
            '31' => 'Bilateral Treaty',
            '32' => 'Multilateral Treaty',
        ];

        if (preg_match('/resource-type\/(\d+)$/', $typeUri, $m)) {
            return $map[$m[1]] ?? 'Other';
        }

        return 'Other';
    }
}
