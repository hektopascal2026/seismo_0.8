<?php

declare(strict_types=1);

namespace Seismo\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Seismo\Formatter\MarkdownBriefingFormatter;
use Seismo\Http\CsrfToken;
use Seismo\Repository\MagnituExportRepository;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Service\BriefingEntryCardPresenter;
use Seismo\Service\BriefingEntryGatherer;
use Seismo\Service\BriefingSourceSelection;
use Seismo\Service\GeminiBriefingException;
use Seismo\Service\GeminiBriefingService;

/**
 * AI Briefing Builder — session UI + Gemini summary.
 *
 * On path satellites, {@see getDbConnection()} is the desk catalog (`seismo_<slug>`);
 * entry rows still come from the mothership via {@see entryTable()}, while Magnitu
 * labels and relevance use local `entry_scores`.
 */
final class AiBriefingController
{
    /** Placeholder substituted with formatted entry markdown before the Gemini call. */
    public const MARKDOWN_CONTEXT_PLACEHOLDER = '{markdownContext}';

    public const DEFAULT_SYSTEM_PROMPT = <<<'PROMPT'
SYSTEM INSTRUCTIONS:
Du bist ein leitender politischer und wirtschaftlicher Analyst in der Schweiz. Deine Zielgruppe sind Entscheidungsträger (CEOs, Verwaltungsräte, Verbandskader), die unter Informationsüberflutung leiden. Du lieferst strategische "Intelligence" und filterst das Tagesrauschen rigoros.

Dein Schreibstil folgt strikt dem "Economist-Benchmark":
- Analytische Eleganz: Schreibe prägnant, aber intellektuell anregend in geschliffenem Deutsch.
- Das "Delta" elegant einweben: Nenne den tagesaktuellen Trigger zwingend, aber natürlich (z.B. "Ein neuer Bericht der IEA warnt...", "Der Bundesrat hat am Mittwoch..."). VERBOTEN sind mechanische Phrasen wie "Heute wird bekannt, dass..." oder "Heute zeigt sich...".
- Angelsächsischer Ansatz: Beende die künstliche Trennung von Wirtschaft und Politik.
- Strikte Relevanz (Triage): Ignoriere reine Konsum-News, Human-Interest, Sport oder Kriminal-Kuriositäten. Fokussiere dich AUSSCHLIESSLICH auf harte Makroökonomie, Regulierung, Geopolitik und systemische Marktverschiebungen.
- Harter Impact statt Binsenweisheiten: Schreibe niemals "Unternehmen müssen das beobachten" oder "Entscheider müssen reagieren". Nenne stattdessen konkrete Folgen: Steigende Compliance-Kosten, Fachkräftemangel, Lieferketten-Risiken oder neue Marktbarrieren.

Erstelle aus den bereitgestellten Daten ein "Executive Briefing" nach ZWINGEND folgender Struktur:

# 📊 Executive Briefing: (ein kurzer prägnanter Titel, der klar macht, warum man das Briefing lesen soll)

**Zusammenfassung:** (Ein flüssiger Absatz, 3-4 Sätze. Was ist der makroökonomische oder politische rote Faden der wichtigsten Entwicklungen? VERBOTEN sind Meta-Einleitungen wie "Die heutigen Meldungen zeichnen ein Bild...". Steig im ersten Satz direkt in die harte Analyse ein.)

### 📌 Die 5 wichtigsten Entwicklungen
(Wähle die 5 strategisch relevantesten Einträge. Filtere weiche Themen gnadenlos heraus. Nutze exakt dieses Format:)

* **[Actionable Headline]:** [Ein kompakter, flüssig lesbarer Absatz (3-4 Sätze). 1. Nenne den konkreten aktuellen Auslöser. 2. Ordne die Dynamik dahinter politisch-wirtschaftlich ein. 3. Beschreibe den direkten, harten Impact auf Schweizer Unternehmen oder den Werkplatz. Nutze elegante, logische Übergänge.] *(Quelle: [Name der Quelle])*
* (Wiederhole dies für genau 5 Punkte. Nach jedem Absatz eine Zeile leer.)

### 🔭 Radar / Ausblick
(Ein weitsichtiger Absatz, 2-3 Sätze zu einem aufkommenden strategischen Trend aus den Daten (z.B. neue EU-Regulierung mit Spillover-Effekt, geopolitische Shifts). VERBOTEN sind gesellschaftliche Kuriositäten. Es muss ein Thema sein, das CEOs für die strategische Planung auf dem Radar brauchen.)

RULES:
- Du musst zwingend im JSON-Format antworten (inklusive "briefing_markdown" und "used_entry_keys").
- Nutze AUSSCHLIESSLICH die unten bereitgestellten Einträge (ENTRIES_DATA). Jeder Eintrag ist mit `[ID: entry_type:entry_id]` markiert; `used_entry_keys` muss exakt diese IDs der fünf Top-Entwicklungen enthalten.
- Erfinde keine Fakten oder Quellen.
- Streiche jedes Adjektiv, das keinen informativen Mehrwert bietet.

ERWARTETES FORMAT (ein JSON-Objekt, ohne Markdown-Code-Fence):
{
  "briefing_markdown": "<dein Executive Briefing als Markdown-String>",
  "used_entry_keys": ["feed_item:123", "email:45"]
}

ENTRIES_DATA:
{markdownContext}
PROMPT;

    private const MIN_SYSTEM_PROMPT_LEN = 20;

    private const MAX_SYSTEM_PROMPT_LEN = 8000;

    private const DEFAULT_LOOKBACK_DAYS = 7;

    private const DEFAULT_LIMIT = 200;

    /** Warn in UI meta when source markdown exceeds this size (bytes). */
    private const CONTEXT_WARN_CHARS = 200_000;

    private const CONTEXT_HEAVY_CHARS = 400_000;

    /** @var list<string> */
    private const MODULE_KEYS = ['feeds', 'media', 'scraper', 'email', 'lex', 'leg'];

    public function show(): void
    {
        $csrfField = CsrfToken::field();
        $basePath  = getBasePath();

        $geminiConfigured = false;
        try {
            $config = new SystemConfigRepository(getDbConnection());
            $key    = $config->get(SettingsController::KEY_GEMINI_API_KEY);
            $geminiConfigured = $key !== null && trim($key) !== '';
        } catch (\Throwable $e) {
            error_log('Seismo briefing_builder show: ' . $e->getMessage());
        }

        $defaultSystemPrompt = self::DEFAULT_SYSTEM_PROMPT;
        $defaultLookbackDays = self::DEFAULT_LOOKBACK_DAYS;
        $defaultLimit        = self::DEFAULT_LIMIT;
        $maxLimit            = MagnituExportRepository::MAX_LIMIT;

        require_once SEISMO_ROOT . '/views/helpers.php';
        require SEISMO_ROOT . '/views/briefing_builder.php';
    }

    public function generate(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'POST required'], JSON_UNESCAPED_UNICODE);

            return;
        }
        if (!CsrfToken::verifyRequest(false)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token. Reload the page.'], JSON_UNESCAPED_UNICODE);

            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        try {
            $selection = $this->parseModuleSelection($_POST['modules'] ?? null);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);

            return;
        }

        $lookbackDays = $this->parseLookbackDays($_POST['lookback_days'] ?? null);
        $since        = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . $lookbackDays . ' days')
            ->format('Y-m-d\TH:i:s\Z');

        $limit = $this->clampLimit($_POST['limit'] ?? self::DEFAULT_LIMIT);

        $systemPrompt = trim((string)($_POST['system_prompt'] ?? ''));
        if (strlen($systemPrompt) < self::MIN_SYSTEM_PROMPT_LEN) {
            http_response_code(400);
            echo json_encode(
                ['ok' => false, 'error' => 'System prompt is too short (minimum ' . self::MIN_SYSTEM_PROMPT_LEN . ' characters).'],
                JSON_UNESCAPED_UNICODE
            );

            return;
        }
        if (strlen($systemPrompt) > self::MAX_SYSTEM_PROMPT_LEN) {
            http_response_code(400);
            echo json_encode(
                ['ok' => false, 'error' => 'System prompt is too long (maximum ' . self::MAX_SYSTEM_PROMPT_LEN . ' characters).'],
                JSON_UNESCAPED_UNICODE
            );

            return;
        }

        $includeImportant = (string)($_POST['include_important'] ?? '0') === '1';
        $labelFilter      = ['investigation_lead'];
        if ($includeImportant) {
            $labelFilter[] = 'important';
        }

        try {
            $pdo      = getDbConnection();
            $gatherer = new BriefingEntryGatherer();
            [$entries, $scoresByKey] = $gatherer->gather($pdo, $since, $limit, $selection, $labelFilter);
            $gatherer->sortByRelevanceDesc($entries, $scoresByKey);

            $markdown = MarkdownBriefingFormatter::format($entries, $scoresByKey, [
                'since'        => $since,
                'limit'        => $limit,
                'label_filter' => $labelFilter,
                'total'        => count($entries),
            ], true);

            $markdownChars   = strlen($markdown);
            $contextWarning  = $this->contextSizeWarning($markdownChars);

            $gemini = new GeminiBriefingService(new SystemConfigRepository($pdo));
            $result = $gemini->generateSummary($systemPrompt, $markdown);

            $entriesHtml     = '';
            $attributionMeta = [
                'context_entry_count'    => count($entries),
                'attributed_entry_count' => 0,
                'used_entry_keys'        => $result->usedEntryKeys,
                'attribution_filtered'   => false,
            ];

            if ($result->attributionParsed) {
                [$cardsEntries, $attributionMeta] = $this->resolveAttributedEntries($entries, $result->usedEntryKeys);
                if ($cardsEntries !== []) {
                    $entriesHtml = (new BriefingEntryCardPresenter())->renderHtml($cardsEntries, $scoresByKey);
                }
            } else {
                $attributionMeta['attribution_warning'] =
                    'Attribution JSON could not be parsed; briefing text is shown without source cards.';
            }

            $meta = [
                'entry_count'    => count($entries),
                'markdown_chars' => $markdownChars,
                'since'          => $since,
                'lookback_days'  => $lookbackDays,
                'limit'          => $limit,
                'modules'        => $this->enabledModuleNames($selection),
                'labels'         => $labelFilter,
            ];
            $meta = array_merge($meta, $attributionMeta);
            if ($contextWarning !== null) {
                $meta['context_warning'] = $contextWarning;
            }

            echo json_encode([
                'ok'           => true,
                'text'         => $result->markdown,
                'meta'         => $meta,
                'entries_html' => $entriesHtml,
            ], JSON_UNESCAPED_UNICODE);
        } catch (GeminiBriefingException $e) {
            http_response_code(502);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            error_log('Seismo briefing_builder_generate: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Could not generate briefing.'], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function parseModuleSelection(mixed $raw): BriefingSourceSelection
    {
        $picked = [];
        if (is_array($raw)) {
            foreach ($raw as $v) {
                if (is_string($v) && in_array($v, self::MODULE_KEYS, true)) {
                    $picked[$v] = true;
                }
            }
        }

        if ($picked === []) {
            throw new \InvalidArgumentException('Select at least one source module (Feeds, Media, Scraper, Mail, Lex, or Leg).');
        }

        return BriefingSourceSelection::forModules(
            isset($picked['feeds']),
            isset($picked['media']),
            isset($picked['scraper']),
            isset($picked['email']),
            isset($picked['lex']),
            isset($picked['leg']),
        );
    }

    /**
     * @return list<string>
     */
    private function enabledModuleNames(BriefingSourceSelection $selection): array
    {
        $names = [];
        if ($selection->moduleFeeds()) {
            $names[] = 'feeds';
        }
        if ($selection->moduleMedia()) {
            $names[] = 'media';
        }
        if ($selection->moduleScraper()) {
            $names[] = 'scraper';
        }
        if ($selection->moduleEmail()) {
            $names[] = 'email';
        }
        if ($selection->moduleLex()) {
            $names[] = 'lex';
        }
        if ($selection->moduleLeg()) {
            $names[] = 'leg';
        }

        return $names;
    }

    private function parseLookbackDays(mixed $raw): int
    {
        $n = (int)$raw;

        return in_array($n, [1, 3, 7], true) ? $n : self::DEFAULT_LOOKBACK_DAYS;
    }

    private function clampLimit(mixed $raw): int
    {
        $n = (int)$raw;
        if ($n < 1) {
            $n = self::DEFAULT_LIMIT;
        }

        return min($n, MagnituExportRepository::MAX_LIMIT);
    }

    private function contextSizeWarning(int $markdownChars): ?string
    {
        if ($markdownChars >= self::CONTEXT_HEAVY_CHARS) {
            return 'Source context is very large; use fewer modules, a shorter lookback, or a lower per-module limit.';
        }
        if ($markdownChars >= self::CONTEXT_WARN_CHARS) {
            return 'Source context is large; generation may be slow or hit model input limits.';
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param list<string> $usedEntryKeys
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    private function resolveAttributedEntries(array $entries, array $usedEntryKeys): array
    {
        $contextCount = count($entries);
        $attributed   = BriefingEntryCardPresenter::filterByUsedKeys($entries, $usedEntryKeys);

        $meta = [
            'context_entry_count'    => $contextCount,
            'attributed_entry_count' => count($attributed),
            'used_entry_keys'        => $usedEntryKeys,
            'attribution_filtered'   => false,
        ];

        if ($usedEntryKeys === []) {
            $meta['attribution_warning'] =
                'Gemini returned no used_entry_keys; source cards are omitted.';

            return [[], $meta];
        }

        if ($attributed === []) {
            $meta['attribution_warning'] =
                'None of the cited entry IDs matched gathered entries; source cards are omitted.';

            return [[], $meta];
        }

        $unmatched = count($usedEntryKeys) - count($attributed);
        if ($unmatched > 0) {
            $meta['attribution_warning'] = $unmatched
                . ' cited ID(s) did not match gathered entries.';
        }

        $meta['attribution_filtered'] = true;

        return [$attributed, $meta];
    }
}
