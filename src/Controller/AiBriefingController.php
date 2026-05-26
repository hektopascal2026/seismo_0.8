<?php

declare(strict_types=1);

namespace Seismo\Controller;

use PDO;
use DateTimeImmutable;
use DateTimeZone;
use Seismo\Formatter\MarkdownBriefingFormatter;
use Seismo\Http\CsrfToken;
use Seismo\Repository\MagnituExportRepository;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Core\MagnituScoreBands;
use Seismo\Service\BriefingEntryCardPresenter;
use Seismo\Service\BriefingEntryGatherer;
use Seismo\Service\BriefingGeminiContext;
use Seismo\Service\BriefingModuleGuard;
use Seismo\Service\BriefingScoreFilter;
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

    /** Saved system prompt in local `system_config` (per mothership or satellite desk). */
    public const CONFIG_KEY_SYSTEM_PROMPT = 'briefing:system_prompt';

    /** Named prompt library (`system_config` JSON list, per desk). */
    public const CONFIG_KEY_PROMPT_LIBRARY = 'ai_briefing_prompts';

    private const PROMPT_LIBRARY_SEED_NAME = 'Default';

    private const MAX_PROMPT_LIBRARY = 50;

    private const MAX_PROMPT_NAME_LEN = 80;

    public const DEFAULT_SYSTEM_PROMPT = <<<'PROMPT'
SYSTEM INSTRUCTIONS:
Du bist ein leitender politischer und wirtschaftlicher Analyst in der Schweiz. Deine Zielgruppe sind Entscheidungsträger (CEOs, Verwaltungsräte, Verbandskader), die unter Informationsüberflutung leiden. Du lieferst strategische "Intelligence" und filterst das Tagesrauschen rigoros.

Dein Schreibstil folgt strikt dem "Economist-Benchmark":
- Analytische Eleganz: Schreibe prägnant, aber intellektuell anregend in geschliffenem Deutsch.
- Das "Delta" elegant einweben: Nenne den tagesaktuellen Trigger zwingend, aber natürlich (z.B. "Ein neuer Bericht der IEA warnt...", "Der Bundesrat hat am Mittwoch..."). VERBOTEN sind mechanische Phrasen wie "Heute wird bekannt, dass..." oder "Heute zeigt sich...".
- Angelsächsischer Ansatz: Beende die künstliche Trennung von Wirtschaft und Politik.
- Strikte Relevanz (Triage): Ignoriere reine Konsum-News, Human-Interest, Sport oder Kriminal-Kuriositäten. Fokussiere dich AUSSCHLIESSLICH auf harte Makroökonomie, Regulierung, Geopolitik und systemische Marktverschiebungen.
- Harter Impact statt Binsenweisheiten: Schreibe niemals "Unternehmen müssen das beobachten" oder "Entscheider müssen reagieren". Nenne stattdessen konkrete Folgen: Steigende Compliance-Kosten, Fachkräftemangel, Lieferketten-Risiken oder neue Marktbarrieren.

SYSTEM-ABLAUF (ZWEI PHASEN — ZWINGEND EINHALTEN):

PHASE 1 — AUSWAHL (nur JSON, kein Briefing-Text):
- Wähle aus ENTRIES_DATA die vom USER PROMPT und "Number of items" geforderte Anzahl an Einträgen.
- Priorisiere harte Makro-, Regulierungs- und Geopolitik-Signale; streiche weiche Themen.
- Gib nur JSON zurück: used_entry_keys (Reihenfolge = spätere Briefing-Reihenfolge) und optional selection_reasoning (kurz: warum diese IDs, warum andere ausgeschlossen).
- Schreibe in Phase 1 KEIN Markdown, keine Überschriften, kein Executive Briefing.

PHASE 2 — BRIEFING (nur Markdown für die bereits gewählten SELECTED_ENTRY_KEYS):
- Decke jeden Eintrag in SELECTED_ENTRY_KEYS genau einmal ab, in dieser Reihenfolge — ein Bullet pro Eintrag.
- Zitiere jeden Eintrag zusätzlich mit der System-ID in Klammern, z.B. (feed_item:123). Das ist Pflicht neben dem lesbaren Quellennamen.
- Kein JSON, kein Meta-Chat ("Hier ist das Briefing...").

Verwende in Phase 2 ZWINGEND folgende Struktur:

# 📊 Executive Briefing: (ein kurzer prägnanter Titel, der klar macht, warum man das Briefing lesen soll)

**Zusammenfassung:** (Ein flüssiger Absatz, 3-4 Sätze. Was ist der makroökonomische oder politische rote Faden? VERBOTEN: Meta-Einleitungen wie "Die heutigen Meldungen zeichnen ein Bild...". Direkter Einstieg in die Analyse.)

### 📌 Die wichtigsten Entwicklungen

* **[Actionable Headline]:** [3-4 Sätze: 1. Konkreter Auslöser. 2. Politisch-wirtschaftliche Einordnung. 3. Harter Impact auf Schweizer Unternehmen/Werkplatz. Flüssige Übergänge.] *(Quelle: [Name der Quelle])* (entry_type:entry_id)
* (Pro SELECTED_ENTRY_KEYS-Eintrag genau ein Bullet; nach jedem Bullet eine Leerzeile.)

### 🔭 Radar / Ausblick
(2-3 Sätze zu einem strategischen Trend aus den gewählten Daten — z.B. EU-Spillover, geopolitischer Shift. Keine Kuriositäten; nur CEO-relevante Planungsthemen.)

Inhaltliche Regeln (beide Phasen):
- Erfinde keine Fakten oder Quellen.
- Streiche jedes Adjektiv ohne informativen Mehrwert.
PROMPT;

    /** Allowed “number of items” values in the Briefing Builder UI. */
    public const ALLOWED_ITEM_COUNTS = [5, 7, 10, 12, 15];

    public const DEFAULT_ITEM_COUNT = 5;

    private const MIN_SYSTEM_PROMPT_LEN = 20;

    private const MAX_SYSTEM_PROMPT_LEN = 32000;

    private const MIN_LOOKBACK_DAYS = 1;

    private const MAX_LOOKBACK_DAYS = 7;

    private const DEFAULT_LOOKBACK_DAYS = 7;

    /** Used when POST `lookback_days` is outside 1–7. */
    private const FALLBACK_LOOKBACK_DAYS = 2;

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

        $geminiConfigured           = false;
        $systemPrompt               = self::DEFAULT_SYSTEM_PROMPT;
        $savedPrompts               = [];
        $defaultPromptStored        = false;
        $initialActivePromptTabId   = null;

        try {
            $config = new SystemConfigRepository(getDbConnection());
            $key    = $config->get(SettingsController::KEY_GEMINI_API_KEY);
            $geminiConfigured = $key !== null && trim($key) !== '';
            $storedDefault    = $config->get(self::CONFIG_KEY_SYSTEM_PROMPT);
            $defaultPromptStored = $storedDefault !== null && trim($storedDefault) !== '';
            $instancePrompt = self::resolveStoredSystemPrompt($config);
            $savedPrompts   = self::ensurePromptLibrarySeeded($config, $instancePrompt);
            $defaultRow     = self::findPromptLibraryEntryByName($savedPrompts, self::PROMPT_LIBRARY_SEED_NAME);
            $systemPrompt   = $defaultRow !== null ? $defaultRow['content'] : $instancePrompt;
        } catch (\Throwable $e) {
            error_log('Seismo briefing_builder show: ' . $e->getMessage());
        }

        foreach ($savedPrompts as $row) {
            if ($row['name'] === self::PROMPT_LIBRARY_SEED_NAME) {
                $initialActivePromptTabId = $row['id'];
                break;
            }
        }

        $defaultLookbackDays = self::DEFAULT_LOOKBACK_DAYS;
        $defaultLimit        = self::DEFAULT_LIMIT;
        $maxLimit            = MagnituExportRepository::BRIEFING_MAX_LIMIT;
        $defaultItemCount    = self::DEFAULT_ITEM_COUNT;
        $itemCountOptions    = self::ALLOWED_ITEM_COUNTS;
        $alertThreshold      = 0.60;
        $maxContextEntries   = BriefingGeminiContext::DEFAULT_MAX_ENTRIES;
        $maxContextDefault   = BriefingGeminiContext::DEFAULT_MAX_ENTRIES;
        $maxContextMin       = BriefingGeminiContext::MIN_MAX_CONTEXT_ENTRIES;
        $maxContextMax       = BriefingGeminiContext::MAX_MAX_CONTEXT_ENTRIES;

        try {
            $configRepo       = new SystemConfigRepository(getDbConnection());
            $alertThreshold   = $configRepo->getAlertThreshold();
            $maxContextEntries = (new BriefingGeminiContext($configRepo))->maxContextEntries();
        } catch (\Throwable $e) {
            error_log('Seismo briefing_builder show alert_threshold: ' . $e->getMessage());
        }

        require_once SEISMO_ROOT . '/views/helpers.php';
        require SEISMO_ROOT . '/views/briefing_builder.php';
    }

    /**
     * Gather and filter entries only — returns counts for the UI before Gemini runs.
     */
    public function prepare(): void
    {
        $this->beginBriefingJsonAction('prepare');

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
            $pdo     = getDbConnection();
            $filters = $this->parseBriefingFiltersFromPost();
            $this->persistMaxContextEntriesFromPost($pdo, $_POST['max_context_entries'] ?? null);
            $gathered = $this->gatherBriefingContext($pdo, $filters, enrichBodies: false);
            $entryCount = count($gathered['entries']);
            if ($entryCount === 0) {
                http_response_code(400);
                echo json_encode(
                    ['ok' => false, 'error' => 'No entries matched your filters.'],
                    JSON_UNESCAPED_UNICODE
                );

                return;
            }

            $meta = [
                'entry_count'    => $entryCount,
                'markdown_chars' => $gathered['markdownChars'],
            ];
            if ($gathered['gatherStats'] !== null) {
                $meta['gather_stats'] = $gathered['gatherStats'];
            }
            if ($gathered['contextWarning'] !== null) {
                $meta['context_warning'] = $gathered['contextWarning'];
            }
            if ($gathered['contextTruncated'] > 0) {
                $meta['context_truncated'] = $gathered['contextTruncated'];
                $meta['max_context_entries'] = $gathered['maxContextEntries'];
            }

            echo json_encode(['ok' => true, 'meta' => $meta], JSON_UNESCAPED_UNICODE);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            error_log('Seismo briefing_builder_prepare: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Could not load entries.'], JSON_UNESCAPED_UNICODE);
        }
    }

    public function generate(): void
    {
        $this->beginBriefingJsonAction('generate');

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
            $filters = $this->parseBriefingFiltersFromPost();
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);

            return;
        }

        $itemCount = $this->parseItemCount($_POST['item_count'] ?? null);

        try {
            $systemPrompt = $this->parseSystemPrompt($_POST['system_prompt'] ?? null);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);

            return;
        }

        try {
            $pdo = getDbConnection();
            $this->persistMaxContextEntriesFromPost($pdo, $_POST['max_context_entries'] ?? null);
            $gathered = $this->gatherBriefingContext($pdo, $filters);
            $entries      = $gathered['entries'];
            $scoresByKey  = $gathered['scoresByKey'];
            $markdown     = $gathered['markdown'];
            $markdownChars  = $gathered['markdownChars'];
            $contextWarning = $gathered['contextWarning'];
            $since          = $filters['since'];
            $limit          = $filters['limit'];
            $scoreFilter    = $filters['scoreFilter'];
            $lookbackDays   = $filters['lookbackDays'];
            $selection      = $filters['selection'];

            if ($entries === []) {
                http_response_code(400);
                echo json_encode(
                    ['ok' => false, 'error' => 'No entries matched your filters.'],
                    JSON_UNESCAPED_UNICODE
                );

                return;
            }

            $contextEntryCount = count($entries);
            $citationSlots     = min($itemCount, $contextEntryCount);

            $gemini = new GeminiBriefingService(new SystemConfigRepository($pdo));
            $briefingMeta = [
                'since'                => $since,
                'limit'                => $limit,
                'score_selection'      => MagnituScoreBands::describeBriefingGather($scoreFilter),
                'total'                  => $contextEntryCount,
                'entry_body_max_chars'   => $gathered['entry_body_max_chars'] ?? MarkdownBriefingFormatter::ENTRY_BODY_DEFAULT_CHARS,
            ];
            $result = $gemini->generateSummary(
                $systemPrompt,
                $markdown,
                $itemCount,
                $contextEntryCount,
                $entries,
                $scoresByKey,
                $briefingMeta,
                $selection,
            );

            $entriesHtml     = '';
            $usedEntryKeys   = $result->usedEntryKeys;
            $keysInferred    = false;
            if ($usedEntryKeys === []) {
                $usedEntryKeys = $this->inferUsedEntryKeysFromBriefing($result->markdown, $entries, $itemCount);
                $keysInferred  = $usedEntryKeys !== [];
            }

            $attributionMeta = [
                'context_entry_count'    => count($entries),
                'attributed_entry_count' => 0,
                'used_entry_keys'        => $usedEntryKeys,
                'attribution_filtered'   => false,
                'item_count'             => $itemCount,
                'cited_entry_count'      => count($usedEntryKeys),
            ];

            if ($result->attributionParsed || $keysInferred) {
                [$cardsEntries, $attributionMeta] = $this->resolveAttributedEntries(
                    $entries,
                    $usedEntryKeys,
                    $itemCount,
                );
                if ($keysInferred) {
                    $inferredMsg = 'Citations inferred from briefing text (Gemini omitted or invalid used_entry_keys).';
                    $attributionMeta['attribution_warning'] = isset($attributionMeta['attribution_warning'])
                        ? $attributionMeta['attribution_warning'] . ' ' . $inferredMsg
                        : $inferredMsg;
                }
                if ($cardsEntries !== []) {
                    $entriesHtml = (new BriefingEntryCardPresenter())->renderHtml($cardsEntries, $scoresByKey);
                }
            } else {
                $attributionMeta['attribution_warning'] =
                    'Attribution JSON could not be parsed; briefing text is shown without source cards.';
            }

            $meta = [
                'entry_count'      => $contextEntryCount,
                'markdown_chars'   => $markdownChars,
                'since'            => $since,
                'lookback_days'    => $lookbackDays,
                'limit'            => $limit,
                'modules'          => $this->enabledModuleNames($selection),
                'alert_threshold'  => $scoreFilter->alertThreshold,
                'include_important_below_threshold' => $scoreFilter->includeImportantBelowThreshold,
                'disregard_magnitu' => $scoreFilter->disregardMagnitu,
                'score_selection'  => MagnituScoreBands::describeBriefingGather($scoreFilter),
                'citation_slots'      => $citationSlots,
                'max_context_entries' => $gathered['maxContextEntries'],
            ];
            $meta = array_merge($meta, $attributionMeta, $gemini->lastGenerationMeta());
            if ($contextWarning !== null) {
                $meta['context_warning'] = $contextWarning;
            }
            if (isset($gathered['contextTruncated']) && $gathered['contextTruncated'] > 0) {
                $meta['context_truncated'] = $gathered['contextTruncated'];
                $meta['max_context_entries'] = $gathered['maxContextEntries'];
            }
            if (!empty($meta['rate_limit_fallback'])) {
                $fallbackNote = 'Retried automatically after a Gemini rate limit (smaller batched context).';
                $meta['context_warning'] = isset($meta['context_warning'])
                    ? $meta['context_warning'] . ' ' . $fallbackNote
                    : $fallbackNote;
            }
            if (!empty($meta['citation_gap'])) {
                $citationNote = 'Briefing text omitted some entry_type:entry_id citations; source cards follow selection order.';
                $meta['context_warning'] = isset($meta['context_warning'])
                    ? $meta['context_warning'] . ' ' . $citationNote
                    : $citationNote;
            }
            if (!empty($meta['batched_summary'])) {
                $batchNote = 'Summary was generated in '
                    . (int)($meta['summary_batches'] ?? 0)
                    . ' parts after a single pass hit output limits; review section breaks.';
                $meta['context_warning'] = isset($meta['context_warning'])
                    ? $meta['context_warning'] . ' ' . $batchNote
                    : $batchNote;
            }

            $this->echoBriefingJson([
                'ok'           => true,
                'text'         => $result->markdown,
                'meta'         => $meta,
                'entries_html' => $entriesHtml,
            ]);
        } catch (GeminiBriefingException $e) {
            http_response_code(502);
            $errorPayload = [
                'ok'    => false,
                'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Gemini briefing failed.',
            ];
            if (isset($gemini)) {
                $failureMeta = $gemini->lastGenerationMeta();
                if ($failureMeta !== []) {
                    $errorPayload['meta'] = $failureMeta;
                }
            }
            $this->echoBriefingJson($errorPayload);
        } catch (\Throwable $e) {
            error_log('Seismo briefing_builder_generate: ' . $e->getMessage());
            http_response_code(500);
            $this->echoBriefingJson([
                'ok'    => false,
                'error' => 'Could not generate briefing.',
            ]);
        }
    }

    public function savePrompt(): void
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
            $systemPrompt = $this->parseSystemPrompt($_POST['system_prompt'] ?? null);
            $config       = new SystemConfigRepository(getDbConnection());
            $config->set(self::CONFIG_KEY_SYSTEM_PROMPT, $systemPrompt);
            $library = self::loadPromptLibrary($config);
            $library = self::upsertDefaultLibraryEntry($library, $systemPrompt);
            $config->setJson(self::CONFIG_KEY_PROMPT_LIBRARY, $library);
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            error_log('Seismo briefing_builder_save_prompt: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Could not save prompt.'], JSON_UNESCAPED_UNICODE);
        }
    }

    public function savePromptLibrary(): void
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
            $content = $this->parseSystemPrompt($_POST['content'] ?? null);
            $config  = new SystemConfigRepository(getDbConnection());
            $library = self::loadPromptLibrary($config);
            $id      = trim((string)($_POST['id'] ?? ''));
            if ($id !== '') {
                $library = self::updatePromptLibraryEntry($library, $id, $content);
                $config->setJson(self::CONFIG_KEY_PROMPT_LIBRARY, $library);
                echo json_encode(['ok' => true, 'prompts' => $library], JSON_UNESCAPED_UNICODE);

                return;
            }

            $name = $this->parsePromptLibraryName($_POST['name'] ?? null);
            if (count($library) >= self::MAX_PROMPT_LIBRARY) {
                throw new \InvalidArgumentException(
                    'Prompt library is full (maximum ' . self::MAX_PROMPT_LIBRARY . ' prompts). Delete one first.'
                );
            }
            $library[] = [
                'id'      => bin2hex(random_bytes(8)),
                'name'    => $name,
                'content' => $content,
            ];
            $config->setJson(self::CONFIG_KEY_PROMPT_LIBRARY, $library);
            echo json_encode(['ok' => true, 'prompts' => $library], JSON_UNESCAPED_UNICODE);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            error_log('Seismo save_briefing_prompt: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Could not save prompt to library.'], JSON_UNESCAPED_UNICODE);
        }
    }

    public function deletePromptLibrary(): void
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
            $id = trim((string)($_POST['id'] ?? ''));
            if ($id === '') {
                throw new \InvalidArgumentException('Prompt id is required.');
            }
            $config  = new SystemConfigRepository(getDbConnection());
            $library = self::loadPromptLibrary($config);
            $before  = count($library);
            $library = array_values(array_filter(
                $library,
                static fn(array $row): bool => ($row['id'] ?? '') !== $id
            ));
            if (count($library) === $before) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Prompt not found.'], JSON_UNESCAPED_UNICODE);

                return;
            }
            $config->setJson(self::CONFIG_KEY_PROMPT_LIBRARY, $library);
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            error_log('Seismo delete_briefing_prompt: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Could not delete prompt.'], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * @return list<array{id: string, name: string, content: string}>
     */
    public static function ensurePromptLibrarySeeded(
        SystemConfigRepository $config,
        string $systemPrompt,
    ): array {
        $library = self::loadPromptLibrary($config);
        if ($library !== []) {
            return $library;
        }

        $library = [[
            'id'      => bin2hex(random_bytes(8)),
            'name'    => self::PROMPT_LIBRARY_SEED_NAME,
            'content' => $systemPrompt,
        ]];
        $config->setJson(self::CONFIG_KEY_PROMPT_LIBRARY, $library);

        return $library;
    }

    /**
     * @return list<array{id: string, name: string, content: string}>
     */
    public static function loadPromptLibrary(SystemConfigRepository $config): array
    {
        $raw = $config->getJson(self::CONFIG_KEY_PROMPT_LIBRARY, []);
        if (!array_is_list($raw)) {
            if ($raw !== []) {
                error_log('Seismo ai_briefing_prompts: expected JSON array, got object');
            }

            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id      = trim((string)($row['id'] ?? ''));
            $name    = trim((string)($row['name'] ?? ''));
            $content = (string)($row['content'] ?? '');
            if ($id === '' || $name === '' || $content === '') {
                continue;
            }
            $out[] = ['id' => $id, 'name' => $name, 'content' => $content];
        }

        return $out;
    }

    /**
     * @param list<array{id: string, name: string, content: string}> $library
     * @return list<array{id: string, name: string, content: string}>
     */
    /**
     * @param list<array{id: string, name: string, content: string}> $library
     * @return array{id: string, name: string, content: string}|null
     */
    public static function findPromptLibraryEntryByName(array $library, string $name): ?array
    {
        foreach ($library as $row) {
            if (($row['name'] ?? '') === $name) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param list<array{id: string, name: string, content: string}> $library
     * @return list<array{id: string, name: string, content: string}>
     */
    public static function upsertDefaultLibraryEntry(array $library, string $content): array
    {
        foreach ($library as $i => $row) {
            if (($row['name'] ?? '') !== self::PROMPT_LIBRARY_SEED_NAME) {
                continue;
            }
            $library[$i]['content'] = $content;

            return $library;
        }

        return $library;
    }

    /**
     * @param list<array{id: string, name: string, content: string}> $library
     * @return list<array{id: string, name: string, content: string}>
     */
    public static function updatePromptLibraryEntry(array $library, string $id, string $content): array
    {
        $updated = false;
        foreach ($library as $i => $row) {
            if (($row['id'] ?? '') !== $id) {
                continue;
            }
            $library[$i]['content'] = $content;
            $updated                = true;
            break;
        }
        if (!$updated) {
            throw new \InvalidArgumentException('Prompt not found.');
        }

        return $library;
    }

    public static function resolveStoredSystemPrompt(SystemConfigRepository $config): string
    {
        $stored = $config->get(self::CONFIG_KEY_SYSTEM_PROMPT);
        if ($stored !== null && trim($stored) !== '') {
            return $stored;
        }

        return self::DEFAULT_SYSTEM_PROMPT;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function parseSystemPrompt(mixed $raw): string
    {
        $systemPrompt = trim((string)$raw);
        if (strlen($systemPrompt) < self::MIN_SYSTEM_PROMPT_LEN) {
            throw new \InvalidArgumentException(
                'System prompt is too short (minimum ' . self::MIN_SYSTEM_PROMPT_LEN . ' characters).'
            );
        }
        if (strlen($systemPrompt) > self::MAX_SYSTEM_PROMPT_LEN) {
            throw new \InvalidArgumentException(
                'System prompt is too long (maximum ' . self::MAX_SYSTEM_PROMPT_LEN . ' characters).'
            );
        }

        return $systemPrompt;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function parsePromptLibraryName(mixed $raw): string
    {
        $name = trim((string)$raw);
        if ($name === '') {
            throw new \InvalidArgumentException('Prompt name is required.');
        }
        if (strlen($name) > self::MAX_PROMPT_NAME_LEN) {
            throw new \InvalidArgumentException(
                'Prompt name is too long (maximum ' . self::MAX_PROMPT_NAME_LEN . ' characters).'
            );
        }

        return $name;
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
        if ($n >= self::MIN_LOOKBACK_DAYS && $n <= self::MAX_LOOKBACK_DAYS) {
            return $n;
        }

        return self::FALLBACK_LOOKBACK_DAYS;
    }

    private function parseItemCount(mixed $raw): int
    {
        $n = (int)$raw;

        return in_array($n, self::ALLOWED_ITEM_COUNTS, true) ? $n : self::DEFAULT_ITEM_COUNT;
    }

    private function persistMaxContextEntriesFromPost(\PDO $pdo, mixed $raw): void
    {
        if ($raw === null) {
            return;
        }
        $s = trim((string)$raw);
        if ($s === '' || !ctype_digit($s)) {
            return;
        }

        $clamped = BriefingGeminiContext::clampMaxContextEntries((int)$s);
        (new SystemConfigRepository($pdo))->set(
            BriefingGeminiContext::CONFIG_KEY_MAX_ENTRIES,
            (string)$clamped,
        );
    }

    private function clampLimit(mixed $raw): int
    {
        $n = (int)$raw;
        if ($n < 1) {
            $n = self::DEFAULT_LIMIT;
        }

        return min($n, MagnituExportRepository::BRIEFING_MAX_LIMIT);
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
     * @return array{
     *     since: string,
     *     limit: int,
     *     lookbackDays: int,
     *     scoreFilter: BriefingScoreFilter,
     *     selection: BriefingSourceSelection
     * }
     * @throws \InvalidArgumentException
     */
    private function parseBriefingFiltersFromPost(): array
    {
        $selection = $this->parseModuleSelection($_POST['modules'] ?? null);
        $lookbackDays = $this->parseLookbackDays($_POST['lookback_days'] ?? null);
        $since        = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . $lookbackDays . ' days')
            ->format('Y-m-d\TH:i:s\Z');
        $limit = $this->clampLimit($_POST['limit'] ?? self::DEFAULT_LIMIT);

        $includeImportantBelow = (string)($_POST['include_important'] ?? '0') === '1';
        $disregardMagnitu      = (string)($_POST['disregard_magnitu'] ?? '0') === '1';
        $alertThreshold        = (new SystemConfigRepository(getDbConnection()))->getAlertThreshold();
        $scoreFilter           = new BriefingScoreFilter(
            $alertThreshold,
            $includeImportantBelow,
            $disregardMagnitu,
        );

        return [
            'since'        => $since,
            'limit'        => $limit,
            'lookbackDays' => $lookbackDays,
            'scoreFilter'  => $scoreFilter,
            'selection'    => $selection,
        ];
    }

    /**
     * @param array{
     *     since: string,
     *     limit: int,
     *     lookbackDays: int,
     *     scoreFilter: BriefingScoreFilter,
     *     selection: BriefingSourceSelection
     * } $filters
     * @return array{
     *     entries: list<array<string, mixed>>,
     *     scoresByKey: array<string, array<string, mixed>>,
     *     markdown: string,
     *     markdownChars: int,
     *     contextWarning: ?string,
     *     gatherStats: ?array{entries_before_score_filter: int, entries_after_score_filter: int},
     *     contextTruncated: int,
     *     maxContextEntries: int
     * }
     */
    private function beginBriefingJsonAction(string $phase): void
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        register_shutdown_function(static function () use ($phase): void {
            $err = error_get_last();
            if ($err === null) {
                return;
            }
            if (!in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }
            error_log(
                'Seismo briefing_builder_' . $phase . ' fatal: '
                . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']
            );
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function echoBriefingJson(array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            error_log('Seismo briefing_builder json_encode: ' . json_last_error_msg());
            http_response_code(500);
            echo json_encode(
                [
                    'ok'    => false,
                    'error' => 'Briefing response could not be encoded. Reduce max context entries or modules.',
                ],
                JSON_UNESCAPED_UNICODE,
            );

            return;
        }

        echo $json;
    }

    /**
     * @param array{
     *     since: string,
     *     limit: int,
     *     lookbackDays: int,
     *     scoreFilter: BriefingScoreFilter,
     *     selection: BriefingSourceSelection
     * } $filters
     * @param bool $enrichBodies Load LONGTEXT bodies for the capped pool (skip on prepare to avoid doubling peak memory).
     */
    private function gatherBriefingContext(PDO $pdo, array $filters, bool $enrichBodies = true): array
    {
        $gatherer = new BriefingEntryGatherer();
        [$entries, $scoresByKey] = $gatherer->gather(
            $pdo,
            $filters['since'],
            $filters['limit'],
            $filters['selection'],
            null,
            $filters['scoreFilter'],
        );
        $gatherer->sortByRelevanceDesc($entries, $scoresByKey);
        $entries = $gatherer->filterByModuleSelection($entries, $filters['selection']);

        $geminiContext = new BriefingGeminiContext(new SystemConfigRepository($pdo));
        $capped        = $geminiContext->capEntriesForModules(
            $entries,
            $scoresByKey,
            $gatherer,
            $filters['selection'],
        );
        $entries          = $capped['entries'];
        $contextTruncated = $capped['truncated'];
        $stratifiedCap    = $capped['stratified'];

        if ($enrichBodies) {
            $gatherer->enrichEntriesWithFullBodies($pdo, $filters['since'], $entries);
        }

        $entryBodyMaxChars = MarkdownBriefingFormatter::dynamicEntryBodyMaxChars(count($entries));
        $gatherMeta        = [
            'since'                => $filters['since'],
            'limit'                => $filters['limit'],
            'score_selection'      => MagnituScoreBands::describeBriefingGather($filters['scoreFilter']),
            'entry_body_max_chars' => $entryBodyMaxChars,
        ];

        $guard  = new BriefingModuleGuard($gatherer);
        $sealed = $guard->sealGeminiContext($entries, $scoresByKey, $gatherMeta, $filters['selection']);
        $entries       = $sealed['entries'];
        $markdown      = $sealed['markdown'];
        $markdownChars = $sealed['markdownChars'];

        $contextWarning = $this->contextSizeWarning($markdownChars);
        if ($contextTruncated > 0) {
            $capNote = $contextTruncated . ' additional '
                . ($contextTruncated === 1 ? 'entry was' : 'entries were')
                . ' omitted from the Gemini context cap (max '
                . $geminiContext->maxContextEntries()
                . ($stratifiedCap
                    ? '; fair share per enabled source module, then relevance'
                    : '; highest relevance, then newest')
                . ').';
            $contextWarning = $contextWarning !== null
                ? $contextWarning . ' ' . $capNote
                : $capNote;
        }

        return [
            'entries'           => $entries,
            'scoresByKey'       => $scoresByKey,
            'markdown'          => $markdown,
            'markdownChars'     => $markdownChars,
            'contextWarning'    => $contextWarning,
            'gatherStats'       => $gatherer->lastGatherStats(),
            'contextTruncated'  => $contextTruncated,
            'maxContextEntries'   => $geminiContext->maxContextEntries(),
            'entry_body_max_chars' => $entryBodyMaxChars,
        ];
    }

    /**
     * When Gemini omits used_entry_keys, match entry_type:entry_id tokens in the briefing
     * against gathered entries (order of first appearance, capped at $maxKeys).
     *
     * @param list<array<string, mixed>> $entries
     * @return list<string>
     */
    private function inferUsedEntryKeysFromBriefing(string $markdown, array $entries, int $maxKeys): array
    {
        if ($markdown === '' || $entries === [] || $maxKeys < 1) {
            return [];
        }

        $valid = [];
        foreach ($entries as $e) {
            $type = (string)($e['entry_type'] ?? '');
            $id   = (string)($e['entry_id'] ?? '');
            if ($type !== '' && $id !== '' && ctype_digit($id)) {
                $valid[$type . ':' . $id] = true;
            }
        }
        if ($valid === []) {
            return [];
        }

        $found = [];
        $patterns = [
            '/\[ID:\s*([a-z][a-z0-9_]*:\d+)\s*\]/i',
            '/\b([a-z][a-z0-9_]*:\d+)\b/',
        ];
        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $markdown, $matches)) {
                continue;
            }
            foreach ($matches[1] as $raw) {
                $key = strtolower(trim((string)$raw));
                if (!isset($valid[$key])) {
                    continue;
                }
                if (!in_array($key, $found, true)) {
                    $found[] = $key;
                }
                if (count($found) >= $maxKeys) {
                    return $found;
                }
            }
        }

        return $found;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param list<string> $usedEntryKeys
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    private function resolveAttributedEntries(array $entries, array $usedEntryKeys, int $expectedItemCount): array
    {
        $contextCount = count($entries);
        $warnings     = [];

        if (count($usedEntryKeys) > $expectedItemCount) {
            $warnings[] = 'Trimmed cited IDs to ' . $expectedItemCount
                . ' (model returned ' . count($usedEntryKeys) . ').';
            $usedEntryKeys = array_slice($usedEntryKeys, 0, $expectedItemCount);
        }

        $citedCount = count($usedEntryKeys);
        if ($citedCount > 0 && $citedCount < $expectedItemCount) {
            $warnings[] = 'Model cited ' . $citedCount . ' of ' . $expectedItemCount . ' requested entries.';
        }

        $attributed = BriefingEntryCardPresenter::filterByUsedKeys($entries, $usedEntryKeys);

        $meta = [
            'context_entry_count'    => $contextCount,
            'attributed_entry_count' => count($attributed),
            'used_entry_keys'        => $usedEntryKeys,
            'attribution_filtered'   => false,
            'item_count'             => $expectedItemCount,
            'cited_entry_count'      => $citedCount,
        ];

        if ($usedEntryKeys === []) {
            $meta['attribution_warning'] =
                'Gemini returned no used_entry_keys (requested ' . $expectedItemCount
                . ' items); source cards are omitted. Try again or use a prompt that cites entry_type:entry_id in each core item.';

            return [[], $meta];
        }

        if ($attributed === []) {
            $meta['attribution_warning'] =
                'None of the cited entry IDs matched gathered entries; source cards are omitted.';

            return [[], $meta];
        }

        $unmatched = count($usedEntryKeys) - count($attributed);
        if ($unmatched > 0) {
            $warnings[] = $unmatched . ' cited ID(s) did not match gathered entries.';
        }

        if ($warnings !== []) {
            $meta['attribution_warning'] = implode(' ', $warnings);
        }

        $meta['attribution_filtered'] = true;

        return [$attributed, $meta];
    }
}
