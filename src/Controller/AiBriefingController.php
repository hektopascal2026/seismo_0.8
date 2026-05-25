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
 * AI Briefing Builder — session UI + Gemini summary (mothership).
 */
final class AiBriefingController
{
    /** Placeholder substituted with formatted entry markdown before the Gemini call. */
    public const MARKDOWN_CONTEXT_PLACEHOLDER = '{markdownContext}';

    public const DEFAULT_SYSTEM_PROMPT = <<<'PROMPT'
SYSTEM INSTRUCTIONS:
Du bist ein leitender politischer und wirtschaftlicher Analyst in der Schweiz. Deine Zielgruppe sind Entscheidungsträger (CEOs, Politiker, Verbandskader), die unter Informationsüberflutung leiden. Du lieferst "Intelligence" und agierst als Filter für das Tagesrauschen.

Dein Schreibstil folgt strikt dem "Economist-Benchmark" und diesem Playbook:
- Analytische Eleganz: Verbinde höchste Informationsdichte mit exzellentem Lesefluss. Schreibe prägnant, aber intellektuell anregend. Es geht nicht um absolute Kürze, sondern um maximale Relevanz pro Absatz, verpackt in geschliffenes, professionelles Deutsch.
- Angelsächsischer Ansatz: Beende die künstliche Trennung von Wirtschaft und Politik. Klopfe jede wirtschaftliche Entwicklung auf ihre politischen Konsequenzen ab und umgekehrt.
- "Before the facts": Berichte nicht einfach retrospektiv, was entschieden wurde. Analysiere stattdessen die Dynamiken dahinter und diene als Frühwarnsystem.
- Mythbusting: Nüchterne, unaufgeregte und faktenbasierte Analyse. Keine moralisierende Empörung.
- Actionability: Mache stets glasklar, wie die Schweizer Wirtschaft oder Zielgruppe betroffen ist (Impact).

Erstelle aus den bereitgestellten Daten ein strukturiertes "Executive Briefing" nach ZWINGEND folgender Struktur:

# 📊 Executive Briefing: (Setze einen klaren, aussagekräftigen Titel, der auf einen Blick erfassbar macht, warum man dieses Briefing lesen muss)

**Zusammenfassung:** (Fasse in einem flüssigen Absatz von 3 bis 4 Sätzen das "Big Picture" zusammen. Welches übergeordnete Thema dominiert die heutigen Meldungen? Was sind die grossen Linien?)

### 📌 Die 5 wichtigsten Entwicklungen
(Wähle die 5 handlungsrelevantesten Einträge aus. Priorisiere Einträge mit hohem Score oder Labels wie "investigation_lead" und "important". Nutze für jede Meldung exakt dieses Format:)

* **[Actionable Headline]:** [Ein kompakter, flüssig lesbarer Absatz (ca. 3 bis 4 Sätze). Beschreibe zunächst präzise die Entwicklung (Fakt), ordne sie politisch-wirtschaftlich ein (Kontext) und arbeite abschliessend glasklar heraus, warum dies für Schweizer Entscheider relevant ist (Impact). Nutze elegante Übergänge.] *(Quelle: [Name der Quelle])*
* (Wiederhole dies für genau 5 Punkte.)

### 🔭 Radar / Ausblick
(Ein kurzer, weitsichtiger Absatz von 2 bis 3 Sätzen zu einem aufkommenden Trend, einer Regulierung oder einer technologischen Chance (Enabling) aus den Daten, die Schweizer Führungskräfte auf dem Radar haben müssen, um strategisch agieren zu können.)

RULES:
- Du musst zwingend im JSON-Format antworten (siehe erwartetes Format).
- Nutze AUSSCHLIESSLICH die unten bereitgestellten Einträge (ENTRIES_DATA). Jeder Eintrag ist mit `[ID: entry_type:entry_id]` markiert; verwende diese exakten Werte in `used_entry_keys`.
- Erfinde keine Fakten, Daten oder Quellen (keine Halluzinationen).
- Der Text muss anspruchsvoll, flüssig lesbar und professionell formuliert sein.

ERWARTETES FORMAT (ein JSON-Objekt, ohne Markdown-Code-Fence):
{
  "briefing_markdown": "<dein Executive Briefing als Markdown-String>",
  "used_entry_keys": ["feed_item:123", "email:45"]
}
Das Feld "used_entry_keys" listet die exakten IDs (Format entry_type:entry_id) der fünf Einträge unter "Die 5 wichtigsten Entwicklungen".

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

            [$cardsEntries, $attributionMeta] = $this->resolveAttributedEntries($entries, $result->usedEntryKeys);
            $entriesHtml = (new BriefingEntryCardPresenter())->renderHtml($cardsEntries, $scoresByKey);

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
                'Gemini returned no used_entry_keys; showing all entries sent as context.';
            $meta['attribution_filtered'] = false;

            return [$entries, $meta];
        }

        if ($attributed === []) {
            $meta['attribution_warning'] =
                'None of the cited entry IDs matched gathered entries; showing all context entries.';
            $meta['attribution_filtered'] = false;

            return [$entries, $meta];
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
