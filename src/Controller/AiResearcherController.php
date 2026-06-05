<?php

declare(strict_types=1);

namespace Seismo\Controller;

use PDO;
use DateTimeImmutable;
use DateTimeZone;
use Seismo\Formatter\MarkdownResearcherFormatter;
use Seismo\Http\CsrfToken;
use Seismo\Repository\MagnituExportRepository;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Core\MagnituScoreBands;
use Seismo\Service\ResearcherEntryCardPresenter;
use Seismo\Service\ResearcherEntryGatherer;
use Seismo\Service\ResearcherGeminiContext;
use Seismo\Service\ResearcherModuleGuard;
use Seismo\Service\ResearcherScoreFilter;
use Seismo\Service\ResearcherSourceSelection;
use Seismo\Service\ResearcherPromptHelperService;
use Seismo\Service\GeminiResearcherException;
use Seismo\Service\GeminiResearcherGenerationMeta;
use Seismo\Service\GeminiResearcherGenerationOptions;
use Seismo\Service\GeminiResearcherService;

/**
 * AI Researcher — session UI + Gemini summary.
 *
 * On path satellites, {@see getDbConnection()} is the desk catalog (`seismo_<slug>`);
 * entry rows still come from the mothership via {@see entryTable()}, while Magnitu
 * labels and relevance use local `entry_scores`.
 */
final class AiResearcherController
{
    /** Placeholder substituted with formatted entry markdown before the Gemini call. */
    public const MARKDOWN_CONTEXT_PLACEHOLDER = '{markdownContext}';

    /** Saved system prompt in local `system_config` (per mothership or satellite desk). */
    public const CONFIG_KEY_SYSTEM_PROMPT = 'researcher:system_prompt';

    /** Named prompt library (`system_config` JSON list, per desk). */
    public const CONFIG_KEY_PROMPT_LIBRARY = 'ai_researcher_prompts';

    private const PROMPT_LIBRARY_SEED_NAME = 'Default';

    private const PROMPT_LIBRARY_SWISSMEM_NAME = 'Swissmem';

    private const MAX_PROMPT_LIBRARY = 50;

    private const MAX_PROMPT_NAME_LEN = 80;

    public const DEFAULT_SYSTEM_PROMPT = <<<'PROMPT'
SYSTEM INSTRUCTIONS:
Du bist ein leitender politischer und wirtschaftlicher Intelligence-Analyst in der Schweiz. Deine Aufgabe ist es, für C-Level-Entscheider (CEOs, Verwaltungsräte) die absolut wichtigsten und strategisch relevantesten Signale aus den vorliegenden Daten herauszufiltern und kompakt aufzubereiten.

Dein Schreibstil folgt strikt dem "Economist-Benchmark": Extrem dicht, analytisch, nüchtern und auf den Punkt. Keine Floskeln.

DEINE KERN-LOGIK (TRIAGE & RELEVANZ):
In ENTRIES_DATA befinden sich diverse Einträge (Feeds, Medien-Artikel, Scraper-News, E-Mails, Parlaments- und Gesetzgebungs-Updates).
- Bewerte alle Einträge rein nach ihrer strategischen Tragweite, ihrem systemischen Risiko und ihrer Relevanz für Schweizer Unternehmen.
- Ignoriere irrelevantes Tagesrauschen, weiche Themen oder reine PR-Meldungen.
- Wähle die vom System vorgegebene Anzahl an Einträgen aus, die den höchsten Impact auf den Schweizer Wirtschaftsstandort und hiesige Unternehmen haben.

SYSTEM-ABLAUF (ZWEI PHASEN — ZWINGEND EINHALTEN):

PHASE 1 — AUSWAHL (nur JSON):
- Wähle aus ENTRIES_DATA die vom USER PROMPT und "Number of items" geforderte Anzahl der wichtigsten und relevantesten Einträge.
- Gib nur valides JSON zurück: used_entry_keys (Reihenfolge = spätere Briefing-Reihenfolge) und selection_reasoning (kurze Begründung pro ID: Welches strategische Signal macht diesen Eintrag heute wichtiger als andere).
- Kein Markdown, keine Überschriften, kein Analysten-Text in Phase 1.

PHASE 2 — BRIEFING (nur Markdown für SELECTED_ENTRY_KEYS):
- Du siehst nur die ausgewählten Volltexte.
- Decke jeden Eintrag in SELECTED_ENTRY_KEYS genau einmal ab, in dieser Reihenfolge — ein Bullet pro Eintrag.
- Zitiere jeden Eintrag zusätzlich mit der System-ID in Klammern, z.B. *(Quelle: [Name der Quelle])* (entry_type:entry_id).
- Erfinde keine Fakten oder externen Quellen, die nicht in SELECTED_ENTRIES_DATA stehen.

Verwende in Phase 2 ZWINGEND folgende Struktur (keine Zusammenfassung, kein Radar/Ausblick):

# 📊 Executive Briefing: [Prägnanter, strategischer Titel]

### 📌 Die wichtigsten Entwicklungen

* **[Actionable Headline]:** [3-4 Sätze: 1. Konkreter Auslöser/Fakt aus der Quelle. 2. Politisch-wirtschaftliche Einordnung. 3. Harter Impact auf Schweizer Unternehmen / den Werkplatz.] *(Quelle: [Name der Quelle])* (entry_type:entry_id)
* (Pro SELECTED_ENTRY_KEYS-Eintrag ein Bullet; nach jedem Bullet eine Leerzeile.)

Inhaltliche Regeln (beide Phasen):
- Erfinde keine Fakten oder Quellen.
- Streiche jedes Adjektiv ohne informativen Mehrwert.
PROMPT;

    public const SWISSMEM_PRESET_PROMPT = <<<'PROMPT'
SYSTEM INSTRUCTIONS:
Du bist Redakteur eines kompakten Branchen-Monitors für die Schweizer Tech-Industrie (Maschinen-, Elektro- und Metall-Industrie sowie verwandte Technologiebranchen). Deine Leser sind Entscheidungsträger, die wissen wollen, was bei relevanten Unternehmen und ihren Führungspersonen passiert — nicht eine ausführliche Impact-Analyse.

Dein Schreibstil:
- Prägnantes, klares Deutsch; sachlich und lesbar (angelehnt an Economist-Klarheit, ohne Berater-Ton).
- Entwicklungsfokus: Was ist neu passiert? Was tut, kündigt, investiert, stellt um oder sagt das Unternehmen bzw. die genannte Führungsperson laut Quelle?
- Bevorzuge konkrete Fakten aus der Quelle: Zahlen, Projekte, Verträge, Personalien, Standorte, Produkte, öffentliche Statements, Verbands- oder Unternehmensmitteilungen.
- Kontext nur knapp, wenn er in der Quelle steht oder zum Verständnis des Vorgangs nötig ist. Keine erfundene Einordnung.
- VERBOTEN: generische Impact-Floskeln («Unternehmen müssen beobachten», «Entscheider sollten reagieren»), Spekulationen über Folgen, die nicht in der Quelle stehen, und Makro-Monologe ohne Bezug zum genannten Akteur.

Relevanz & Triage:
- Ignoriere allgemeines Tagesrauschen ohne konkreten Schweizer MEM-Bezug.
- Bevorzuge Meldungen mit klarer Unternehmens- oder Personenhandlung (nicht reine Marktkommentare ohne Akteur).

ZWEI-STUFIGER FILTER & ZWINGENDE VERIFIKATION (WICHTIG):
Jeder ausgewählte Beitrag MUSS explizit ein echtes Schweizer Tech-Industrieunternehmen (z. B. ABB, Bühler, Stadler, VAT, Schindler, Siemens, SFS, Kuhn Rikon, RUAG, VAT Group etc.) oder einen namentlich genannten Wirtschaftsvertreter aus dem Schweizer MEM-Tech-Sektor im Text erwähnen.
Falls ein Beitrag fälschlicherweise durch die Quellenauswahl gerutscht ist, aber kein solches Unternehmen/Person erwähnt (z. B. bei allgemeiner Steuer-News über "VAT" oder Artikeln, die lediglich allgemeine Floskeln wie "the next step" enthalten), darfst du diesen Beitrag UNTER KEINEN UMSTÄNDEN für das Briefing auswählen!

SYSTEM-ABLAUF (ZWEI PHASEN — ZWINGEND EINHALTEN):

PHASE 1 — AUSWAHL (nur JSON, kein Researcher-Text):
- Wähle aus ENTRIES_DATA die vom USER PROMPT und "Number of items" geforderte Anzahl an Einträgen.
- Wähle AUSSCHLIESSLICH Beiträge, die das Verifikationskriterium erfüllen und eine konkrete Entwicklung bei einem Unternehmen oder einer genannten Person enthalten.
- Gib nur JSON zurück: used_entry_keys (Reihenfolge = spätere Briefing-Reihenfolge) und selection_reasoning (Pflicht).
- selection_reasoning: pro gewähltem entry_type:entry_id genau ein kurzer Satz (max. zwei), welche Entwicklung/ welches Statement im Eintrag zählt; Reihenfolge = used_entry_keys; nenne die ID explizit (z. B. feed_item:123). Optional ein Satz, warum naheliegende Kandidaten ausgeschlossen wurden.
- Schreibe in Phase 1 KEIN Markdown, keine Überschriften, kein Briefing-Fliesstext.

PHASE 2 — BRIEFING (nur Markdown für die bereits gewählten SELECTED_ENTRY_KEYS):
- Die Auswahl ist abgeschlossen. Wiederhole oder formatiere selection_reasoning aus Phase 1 NICHT — kein Abschnitt «Selektions-Begründungen», keine Auswahl-Bullets.
- Decke jeden Eintrag in SELECTED_ENTRY_KEYS genau einmal ab, in dieser Reihenfolge — ein Entwicklungs-Bullet pro Eintrag.
- Berichte, was das Unternehmen oder die Führungsperson laut Quelle tut oder sagt; Impact nur erwähnen, wenn die Quelle ihn explizit benennt.
- Zitiere jeden Eintrag zusätzlich mit der System-ID in Klammern, z. B. (feed_item:123), neben dem lesbaren Quellennamen.
- Kein JSON, kein Meta-Chat.

Verwende in Phase 2 ZWINGEND folgende Struktur (Zusammenfassung, Radar/Ausblick und Selektions-Begründungen entfallen):

# 📊 Swissmem Monitor: [kurzer Titel — welche Unternehmens-/Branchenentwicklungen heute im Fokus stehen]

[Optional: genau ein Satz als Leitsatz — welches gemeinsame Thema die ausgewählten Meldungen verbindet, ohne Einzelheiten vorwegzunehmen.]

### 📌 Entwicklungen bei MEM-Unternehmen und Führungspersonen

* **[Kurze Headline — wer, was]:** [2-4 Sätze: 1. Was ist passiert (Ereignis/Entscheidung/Meldung). 2. Was das Unternehmen oder die genannte Person konkret tut, plant, verkündet oder sagt — mit Namen und Details aus der Quelle. 3. Nur bei Bedarf ein knapper Kontext-Satz, wenn er in der Quelle steht.] *(Quelle: [Name der Quelle])* (entry_type:entry_id)
* (Pro SELECTED_ENTRY_KEYS-Eintrag genau ein Bullet; nach jedem Bullet eine Leerzeile.)

Inhaltliche Regeln (beide Phasen):
- Erfinde keine Fakten, Zitate oder Quellen.
- Streiche jedes Adjektiv ohne informativen Mehrwert.
PROMPT;

    /** Allowed “number of items” values in the Researcher UI. */
    public const ALLOWED_ITEM_COUNTS = [5, 7, 10, 12];

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
    private const MODULE_KEYS = ['feeds', 'media', 'email', 'newsletter', 'scraper', 'lex', 'lex_ch', 'leg', 'mem'];

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
            $storedDefault       = $config->get(self::CONFIG_KEY_SYSTEM_PROMPT);
            $defaultPromptStored = $storedDefault !== null && trim($storedDefault) !== '';
            $savedPrompts        = self::withBuiltinPromptContents(
                self::ensurePromptLibrarySeeded($config)
            );
            $systemPrompt = self::DEFAULT_SYSTEM_PROMPT;
        } catch (\Throwable $e) {
            error_log('Seismo researcher show: ' . $e->getMessage());
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
        $maxContextEntries   = ResearcherGeminiContext::DEFAULT_MAX_ENTRIES;
        $maxContextDefault   = ResearcherGeminiContext::DEFAULT_MAX_ENTRIES;
        $maxContextMin       = ResearcherGeminiContext::MIN_MAX_CONTEXT_ENTRIES;
        $maxContextMax       = ResearcherGeminiContext::MAX_MAX_CONTEXT_ENTRIES;

        try {
            $configRepo       = new SystemConfigRepository(getDbConnection());
            $alertThreshold   = $configRepo->getAlertThreshold();
            $maxContextEntries = (new ResearcherGeminiContext($configRepo))->maxContextEntries();
        } catch (\Throwable $e) {
            error_log('Seismo researcher show alert_threshold: ' . $e->getMessage());
        }

        require_once SEISMO_ROOT . '/views/helpers.php';
        require SEISMO_ROOT . '/views/researcher.php';
    }

    /**
     * Gather and filter entries only — returns counts for the UI before Gemini runs.
     */
    public function prepare(): void
    {
        $this->beginResearcherJsonAction('prepare');

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
            $filters = $this->parseResearcherFiltersFromPost();
            $this->persistMaxContextEntriesFromPost($pdo, $_POST['max_context_entries'] ?? null);
            $gathered = $this->gatherResearcherContext($pdo, $filters, enrichBodies: false);
            $entryCount = count($gathered['entries']);
            if ($entryCount === 0) {
                http_response_code(400);
                echo json_encode(
                    ['ok' => false, 'error' => 'No entries matched your filters.'],
                    JSON_UNESCAPED_UNICODE
                );

                return;
            }

            $meta = array_merge(
                $this->contextCapMetaFromGathered($gathered),
                ['markdown_chars' => $gathered['markdownChars']],
            );
            if ($gathered['gatherStats'] !== null) {
                $meta['gather_stats'] = $gathered['gatherStats'];
            }
            if ($gathered['contextWarning'] !== null) {
                $meta['context_warning'] = $gathered['contextWarning'];
            }

            echo json_encode(['ok' => true, 'meta' => $meta], JSON_UNESCAPED_UNICODE);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            error_log('Seismo researcher_prepare: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Could not load entries.'], JSON_UNESCAPED_UNICODE);
        }
    }

    public function generate(): void
    {
        $this->beginResearcherJsonAction('generate');

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
            $filters = $this->parseResearcherFiltersFromPost();
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);

            return;
        }

        $itemCount = $this->parseItemCount($_POST['item_count'] ?? null);

        try {
            $systemPrompt = $this->resolveSystemPromptFromPost($_POST);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);

            return;
        }

        $generationOptions = GeminiResearcherGenerationOptions::fromPost($_POST);
        $gathered          = null;
        $gemini            = null;
        $itemCountForMeta  = $itemCount;

        try {
            $pdo = getDbConnection();
            $this->persistMaxContextEntriesFromPost($pdo, $_POST['max_context_entries'] ?? null);
            $gathered = $this->gatherResearcherContext($pdo, $filters);
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

            $gemini = new GeminiResearcherService(new SystemConfigRepository($pdo));
            $recipeKeywords = [];
            if ($filters['useRecipeSnippets'] ?? false) {
                $configRepo = new SystemConfigRepository($pdo);
                $recipeJson = $configRepo->get('recipe_json');
                if ($recipeJson !== null && $recipeJson !== '') {
                    $recipe = json_decode($recipeJson, true) ?: [];
                    $recipeKeywords = array_keys($recipe['keywords'] ?? []);
                }
            }

            $researcherMeta = [
                'since'                => $since,
                'limit'                => $limit,
                'score_selection'      => MagnituScoreBands::describeResearcherGather($scoreFilter),
                'total'                  => $contextEntryCount,
                'entry_body_max_chars'   => $gathered['entry_body_max_chars'] ?? MarkdownResearcherFormatter::ENTRY_BODY_DEFAULT_CHARS,
                'use_recipe_snippets'  => $filters['useRecipeSnippets'] ?? false,
                'recipe_keywords'      => $recipeKeywords,
            ];
            $result = $gemini->generateSummary(
                $systemPrompt,
                $markdown,
                $itemCount,
                $contextEntryCount,
                $entries,
                $scoresByKey,
                $researcherMeta,
                $selection,
                $generationOptions,
            );

            $entriesHtml     = '';
            $usedEntryKeys   = $result->usedEntryKeys;
            $keysInferred    = false;
            if ($usedEntryKeys === []) {
                $usedEntryKeys = $this->inferUsedEntryKeysFromResearcher($result->markdown, $entries, $itemCount);
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
                    $inferredMsg = 'Citations inferred from researcher text (Gemini omitted or invalid used_entry_keys).';
                    $attributionMeta['attribution_warning'] = isset($attributionMeta['attribution_warning'])
                        ? $attributionMeta['attribution_warning'] . ' ' . $inferredMsg
                        : $inferredMsg;
                }
                if ($cardsEntries !== []) {
                    $entriesHtml = (new ResearcherEntryCardPresenter())->renderHtml($cardsEntries, $scoresByKey);
                }
            } else {
                $attributionMeta['attribution_warning'] =
                    'Attribution JSON could not be parsed; researcher text is shown without source cards.';
            }

            $meta = array_merge(
                $this->contextCapMetaFromGathered($gathered),
                [
                    'markdown_chars'   => $markdownChars,
                    'since'            => $since,
                    'lookback_days'    => $lookbackDays,
                    'limit'            => $limit,
                    'modules'          => $this->enabledModuleNames($selection),
                    'alert_threshold'  => $scoreFilter->alertThreshold,
                    'include_important_below_threshold' => $scoreFilter->includeImportantBelowThreshold,
                    'disregard_magnitu' => $scoreFilter->disregardMagnitu,
                    'score_selection'  => MagnituScoreBands::describeResearcherGather($scoreFilter),
                    'citation_slots'      => $citationSlots,
                ],
            );
            $meta = array_merge($meta, $attributionMeta, $gemini->lastGenerationMeta());
            $meta = GeminiResearcherGenerationMeta::normalize(
                $meta,
                $generationOptions,
                $this->researcherPipelineContext($contextEntryCount, $itemCount),
            );
            if ($contextWarning !== null) {
                $meta['context_warning'] = $contextWarning;
            }
            if (!empty($meta['rate_limit_fallback'])) {
                $fallbackNote = 'Retried automatically after a Gemini rate limit (smaller batched context).';
                $meta['context_warning'] = isset($meta['context_warning'])
                    ? $meta['context_warning'] . ' ' . $fallbackNote
                    : $fallbackNote;
            }
            if (!empty($meta['citation_gap'])) {
                $citationNote = 'Researcher text omitted some entry_type:entry_id citations; source cards follow selection order.';
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

            $this->echoResearcherJson([
                'ok'           => true,
                'text'         => $result->markdown,
                'meta'         => $meta,
                'entries_html' => $entriesHtml,
            ]);
        } catch (GeminiResearcherException $e) {
            http_response_code(502);
            $this->echoResearcherJson([
                'ok'    => false,
                'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Gemini researcher failed.',
                'meta'  => $this->normalizeResearcherResponseMeta(
                    $this->buildResearcherFailureMeta(
                        $gathered,
                        $gemini,
                        $generationOptions,
                        $itemCountForMeta,
                        $filters['selection'] ?? null,
                    ),
                    $generationOptions,
                    $gathered,
                    $itemCountForMeta,
                ),
            ]);
        } catch (\Throwable $e) {
            error_log('Seismo researcher_generate: ' . $e->getMessage());
            http_response_code(500);
            $this->echoResearcherJson([
                'ok'    => false,
                'error' => 'Could not generate researcher.',
                'meta'  => $this->normalizeResearcherResponseMeta(
                    $this->buildResearcherFailureMeta(
                        $gathered,
                        $gemini ?? null,
                        $generationOptions,
                        $itemCountForMeta,
                        $filters['selection'] ?? null,
                    ),
                    $generationOptions,
                    $gathered,
                    $itemCountForMeta,
                ),
            ]);
        }
    }

    public function promptHelper(): void
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
            $intent = trim((string)($_POST['intent'] ?? ''));
            $config = new SystemConfigRepository(getDbConnection());
            $style  = self::DEFAULT_SYSTEM_PROMPT;
            $helper = new ResearcherPromptHelperService($config);
            $prompt = $helper->reformulate($intent, $style);
            $prompt = $this->parseSystemPrompt($prompt);
            echo json_encode(['ok' => true, 'prompt' => $prompt], JSON_UNESCAPED_UNICODE);
        } catch (GeminiResearcherException $e) {
            http_response_code($e->httpStatus ?? 400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            error_log('Seismo researcher_prompt_helper: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Could not generate prompt.'], JSON_UNESCAPED_UNICODE);
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
            throw new \InvalidArgumentException('The default prompt cannot be overwritten.');
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            error_log('Seismo researcher_save_prompt: ' . $e->getMessage());
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
                foreach ($library as $row) {
                    if (($row['id'] ?? '') === $id && ($row['name'] ?? '') === self::PROMPT_LIBRARY_SEED_NAME) {
                        throw new \InvalidArgumentException('The default prompt cannot be overwritten.');
                    }
                }
                $library = self::updatePromptLibraryEntry($library, $id, $content);
                $config->setJson(self::CONFIG_KEY_PROMPT_LIBRARY, $library);
                echo json_encode(['ok' => true, 'prompts' => $library], JSON_UNESCAPED_UNICODE);

                return;
            }

            $name = $this->parsePromptLibraryName($_POST['name'] ?? null);
            if ($name === self::PROMPT_LIBRARY_SEED_NAME) {
                throw new \InvalidArgumentException('Cannot create or update a prompt with the reserved name "' . self::PROMPT_LIBRARY_SEED_NAME . '".');
            }
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
            error_log('Seismo save_researcher_prompt: ' . $e->getMessage());
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

            $toDelete = null;
            foreach ($library as $row) {
                if (($row['id'] ?? '') === $id) {
                    $toDelete = $row;
                    break;
                }
            }
            if ($toDelete !== null && ($toDelete['name'] ?? '') === self::PROMPT_LIBRARY_SEED_NAME) {
                throw new \InvalidArgumentException('The default prompt cannot be deleted.');
            }

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
            error_log('Seismo delete_researcher_prompt: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Could not delete prompt.'], JSON_UNESCAPED_UNICODE);
        }
    }

    public static function ensurePromptLibrarySeeded(SystemConfigRepository $config): array
    {
        $library = self::loadPromptLibrary($config);

        $hasDefault  = false;
        $hasSwissmem = false;
        foreach ($library as $row) {
            $name = $row['name'] ?? '';
            if ($name === self::PROMPT_LIBRARY_SEED_NAME) {
                $hasDefault = true;
            }
            if ($name === self::PROMPT_LIBRARY_SWISSMEM_NAME) {
                $hasSwissmem = true;
            }
        }

        $changed = false;
        if (!$hasDefault) {
            $library[] = [
                'id'      => bin2hex(random_bytes(8)),
                'name'    => self::PROMPT_LIBRARY_SEED_NAME,
                'content' => self::DEFAULT_SYSTEM_PROMPT,
            ];
            $changed = true;
        }

        if (!$hasSwissmem) {
            $library[] = [
                'id'      => bin2hex(random_bytes(8)),
                'name'    => self::PROMPT_LIBRARY_SWISSMEM_NAME,
                'content' => self::SWISSMEM_PRESET_PROMPT,
            ];
            $changed = true;
        }

        $ordered      = self::orderPromptLibraryDefaultFirst($library);
        $orderChanged = $ordered !== $library;
        $library      = $ordered;

        if ($changed || $orderChanged) {
            $config->setJson(self::CONFIG_KEY_PROMPT_LIBRARY, $library);
        }

        return $library;
    }

    /**
     * Reset shipped Default + Swissmem prompt text and the desk default system prompt.
     * Other library tabs (custom names/IDs) are left unchanged.
     *
     * @return array{default_updated: bool, swissmem_updated: bool, default_added: bool, swissmem_added: bool}
     */
    public static function restoreBuiltinResearcherPrompts(SystemConfigRepository $config): array
    {
        $config->set(self::CONFIG_KEY_SYSTEM_PROMPT, self::DEFAULT_SYSTEM_PROMPT);

        $library       = self::loadPromptLibrary($config);
        $hasDefault    = false;
        $hasSwissmem   = false;
        $defaultAdded  = false;
        $swissmemAdded = false;

        foreach ($library as $i => $row) {
            $name = (string)($row['name'] ?? '');
            if ($name === self::PROMPT_LIBRARY_SEED_NAME) {
                $library[$i]['content'] = self::DEFAULT_SYSTEM_PROMPT;
                $hasDefault             = true;
            }
            if ($name === self::PROMPT_LIBRARY_SWISSMEM_NAME) {
                $library[$i]['content'] = self::SWISSMEM_PRESET_PROMPT;
                $hasSwissmem            = true;
            }
        }

        if (!$hasDefault) {
            $library[] = [
                'id'      => bin2hex(random_bytes(8)),
                'name'    => self::PROMPT_LIBRARY_SEED_NAME,
                'content' => self::DEFAULT_SYSTEM_PROMPT,
            ];
            $hasDefault   = true;
            $defaultAdded = true;
        }

        if (!$hasSwissmem) {
            $library[] = [
                'id'      => bin2hex(random_bytes(8)),
                'name'    => self::PROMPT_LIBRARY_SWISSMEM_NAME,
                'content' => self::SWISSMEM_PRESET_PROMPT,
            ];
            $hasSwissmem   = true;
            $swissmemAdded = true;
        }

        $config->setJson(self::CONFIG_KEY_PROMPT_LIBRARY, self::orderPromptLibraryDefaultFirst($library));

        return [
            'default_updated'  => $hasDefault && !$defaultAdded,
            'swissmem_updated' => $hasSwissmem && !$swissmemAdded,
            'default_added'    => $defaultAdded,
            'swissmem_added'   => $swissmemAdded,
        ];
    }

    /**
     * Inject shipped builtin prompt text for library tabs backed by code constants.
     *
     * @param list<array{id: string, name: string, content: string}> $library
     * @return list<array{id: string, name: string, content: string}>
     */
    public static function withBuiltinPromptContents(array $library): array
    {
        foreach ($library as $i => $row) {
            if (($row['name'] ?? '') === self::PROMPT_LIBRARY_SEED_NAME) {
                $library[$i]['content'] = self::DEFAULT_SYSTEM_PROMPT;
            }
        }

        return $library;
    }

    /**
     * @param list<array{id: string, name: string, content: string}> $library
     * @return list<array{id: string, name: string, content: string}>
     */
    private static function orderPromptLibraryDefaultFirst(array $library): array
    {
        $ordered      = [];
        $defaultEntry = null;
        foreach ($library as $row) {
            if (($row['name'] ?? '') === self::PROMPT_LIBRARY_SEED_NAME) {
                $defaultEntry = $row;
            } else {
                $ordered[] = $row;
            }
        }
        if ($defaultEntry !== null) {
            array_unshift($ordered, $defaultEntry);
        }

        return $ordered;
    }

    /**
     * @return list<array{id: string, name: string, content: string}>
     */
    public static function loadPromptLibrary(SystemConfigRepository $config): array
    {
        $raw = $config->getJson(self::CONFIG_KEY_PROMPT_LIBRARY, []);
        if (!array_is_list($raw)) {
            if ($raw !== []) {
                error_log('Seismo ai_researcher_prompts: expected JSON array, got object');
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

        return self::orderPromptLibraryDefaultFirst($out);
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
        return self::DEFAULT_SYSTEM_PROMPT;
    }

    /**
     * @param array<string, mixed> $post
     * @throws \InvalidArgumentException
     */
    private function resolveSystemPromptFromPost(array $post): string
    {
        $libraryId = trim((string)($post['prompt_library_id'] ?? ''));
        if ($libraryId !== '') {
            $config  = new SystemConfigRepository(getDbConnection());
            $library = self::loadPromptLibrary($config);
            foreach ($library as $row) {
                if (($row['id'] ?? '') === $libraryId
                    && ($row['name'] ?? '') === self::PROMPT_LIBRARY_SEED_NAME
                ) {
                    return self::DEFAULT_SYSTEM_PROMPT;
                }
            }
        }

        return $this->parseSystemPrompt($post['system_prompt'] ?? null);
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
    private function parseModuleSelection(mixed $raw): ResearcherSourceSelection
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
            throw new \InvalidArgumentException('Select at least one source module (Feeds, Media, Scraper, Mail, Newsletter, Lex, or Leg).');
        }

        return ResearcherSourceSelection::forModules(
            isset($picked['feeds']),
            isset($picked['media']),
            isset($picked['scraper']),
            isset($picked['email']),
            isset($picked['newsletter']),
            isset($picked['lex']),
            isset($picked['leg']),
            isset($picked['lex_ch']),
            isset($picked['mem']),
        );
    }

    /**
     * @return list<string>
     */
    private function enabledModuleNames(ResearcherSourceSelection $selection): array
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
        if ($selection->moduleNewsletter()) {
            $names[] = 'newsletter';
        }
        if ($selection->moduleLex()) {
            $names[] = 'lex';
        }
        if ($selection->moduleLexCh()) {
            $names[] = 'lex_ch';
        }
        if ($selection->moduleLeg()) {
            $names[] = 'leg';
        }
        if ($selection->moduleMem()) {
            $names[] = 'mem';
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

        $clamped = ResearcherGeminiContext::clampMaxContextEntries((int)$s);
        (new SystemConfigRepository($pdo))->set(
            ResearcherGeminiContext::CONFIG_KEY_MAX_ENTRIES,
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
     *     scoreFilter: ResearcherScoreFilter,
     *     selection: ResearcherSourceSelection
     * }
     * @throws \InvalidArgumentException
     */
    private function parseResearcherFiltersFromPost(): array
    {
        $selection = $this->parseModuleSelection($_POST['modules'] ?? null);
        $lookbackDays = $this->parseLookbackDays($_POST['lookback_days'] ?? null);
        $since        = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . $lookbackDays . ' days')
            ->format('Y-m-d\TH:i:s\Z');
        $limit = $this->clampLimit($_POST['limit'] ?? self::DEFAULT_LIMIT);

        $includeImportantBelow = (string)($_POST['include_important'] ?? '0') === '1';
        $disregardMagnitu      = (string)($_POST['disregard_magnitu'] ?? '0') === '1';
        $useRecipeSnippets     = (string)($_POST['use_recipe_snippets'] ?? '0') === '1';
        $alertThreshold        = (new SystemConfigRepository(getDbConnection()))->getAlertThreshold();
        $scoreFilter           = new ResearcherScoreFilter(
            $alertThreshold,
            $includeImportantBelow,
            $disregardMagnitu,
        );

        return [
            'since'             => $since,
            'limit'             => $limit,
            'lookbackDays'      => $lookbackDays,
            'scoreFilter'       => $scoreFilter,
            'selection'         => $selection,
            'useRecipeSnippets' => $useRecipeSnippets,
        ];
    }

    /**
     * @param array{
     *     since: string,
     *     limit: int,
     *     lookbackDays: int,
     *     scoreFilter: ResearcherScoreFilter,
     *     selection: ResearcherSourceSelection
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
    private function beginResearcherJsonAction(string $phase): void
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
                'Seismo researcher_' . $phase . ' fatal: '
                . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']
            );
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function echoResearcherJson(array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            error_log('Seismo researcher json_encode: ' . json_last_error_msg());
            http_response_code(500);
            echo json_encode(
                [
                    'ok'    => false,
                    'error' => 'Researcher response could not be encoded. Reduce max context entries or modules.',
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
     *     scoreFilter: ResearcherScoreFilter,
     *     selection: ResearcherSourceSelection
     * } $filters
     * @param bool $enrichBodies Load LONGTEXT bodies for the capped pool (skip on prepare to avoid doubling peak memory).
     */
    private function gatherResearcherContext(PDO $pdo, array $filters, bool $enrichBodies = true): array
    {
        $gatherer = new ResearcherEntryGatherer();
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

        $geminiContext = new ResearcherGeminiContext(new SystemConfigRepository($pdo));
        $entriesEligibleBeforeCap = count($entries);
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

        $entryBodyMaxChars = MarkdownResearcherFormatter::dynamicEntryBodyMaxChars(count($entries));
        $recipeKeywords = [];
        if ($filters['useRecipeSnippets'] ?? false) {
            $configRepo = new SystemConfigRepository($pdo);
            $recipeJson = $configRepo->get('recipe_json');
            if ($recipeJson !== null && $recipeJson !== '') {
                $recipe = json_decode($recipeJson, true) ?: [];
                $recipeKeywords = array_keys($recipe['keywords'] ?? []);
            }
        }

        $gatherMeta        = [
            'since'                => $filters['since'],
            'limit'                => $filters['limit'],
            'score_selection'      => MagnituScoreBands::describeResearcherGather($filters['scoreFilter']),
            'entry_body_max_chars' => $entryBodyMaxChars,
            'use_recipe_snippets'  => $filters['useRecipeSnippets'] ?? false,
            'recipe_keywords'      => $recipeKeywords,
        ];

        $guard  = new ResearcherModuleGuard($gatherer);
        $sealed = $guard->sealGeminiContext($entries, $scoresByKey, $gatherMeta, $filters['selection']);
        $entries       = $sealed['entries'];
        $markdown      = $sealed['markdown'];
        $markdownChars = $sealed['markdownChars'];

        $contextWarning = $this->contextSizeWarning($markdownChars);
        if ($contextTruncated > 0) {
            $capNote = count($entries) . ' sent to Gemini; '
                . $contextTruncated . ' additional '
                . ($contextTruncated === 1 ? 'entry was' : 'entries were')
                . ' omitted (cap '
                . $geminiContext->maxContextEntries()
                . ', '
                . $entriesEligibleBeforeCap . ' eligible before cap'
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
            'contextTruncated'         => $contextTruncated,
            'entriesEligibleBeforeCap' => $entriesEligibleBeforeCap,
            'maxContextEntries'        => $geminiContext->maxContextEntries(),
            'entry_body_max_chars'     => $entryBodyMaxChars,
        ];
    }

    /**
     * Clear context-cap counts for API meta (prepare + generate).
     *
     * @param array{
     *     entries: list<array<string, mixed>>,
     *     contextTruncated: int,
     *     entriesEligibleBeforeCap: int,
     *     maxContextEntries: int
     * } $gathered
     * @return array<string, int>
     */
    /**
     * @param array<string, mixed>|null $gathered
     */
    private function buildResearcherFailureMeta(
        ?array $gathered,
        ?GeminiResearcherService $gemini,
        GeminiResearcherGenerationOptions $generationOptions,
        int $itemCount,
        ?ResearcherSourceSelection $selection,
    ): array {
        $meta = [
            'generation_failed'    => true,
            'selection_mode'       => $generationOptions->selectionMode(),
            'tournament_mode'      => $generationOptions->tournamentMode(),
            'pro_selection_mode'   => $generationOptions->proSelectionMode,
            'item_count'           => $itemCount,
        ];

        if ($gathered !== null) {
            $meta = array_merge($meta, $this->contextCapMetaFromGathered($gathered));
            if ($selection !== null) {
                $meta['modules'] = $this->enabledModuleNames($selection);
            }
            if (isset($gathered['contextWarning']) && $gathered['contextWarning'] !== null) {
                $meta['context_warning'] = (string)$gathered['contextWarning'];
            }
        }

        if ($gemini !== null) {
            $meta = array_merge($meta, $gemini->lastGenerationMeta());
        }

        return $meta;
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed>|null $gathered
     * @return array<string, mixed>
     */
    private function normalizeResearcherResponseMeta(
        array $meta,
        GeminiResearcherGenerationOptions $generationOptions,
        ?array $gathered,
        int $itemCount,
    ): array {
        $poolCount = $gathered !== null ? count($gathered['entries']) : (int)($meta['pool_entry_count'] ?? 0);

        return GeminiResearcherGenerationMeta::normalize(
            $meta,
            $generationOptions,
            $this->researcherPipelineContext($poolCount, $itemCount),
        );
    }

    /**
     * @return array{pool_entry_count: int, context_entry_count: int, item_count: int, selection_target: int}
     */
    private function researcherPipelineContext(int $poolCount, int $itemCount): array
    {
        $poolCount = max(0, $poolCount);

        return [
            'pool_entry_count'    => $poolCount,
            'context_entry_count' => $poolCount,
            'item_count'          => $itemCount,
            'selection_target'    => min($itemCount, max(1, $poolCount)),
        ];
    }

    /**
     * @param array<string, mixed> $gathered
     */
    private function contextCapMetaFromGathered(array $gathered): array
    {
        $sent    = count($gathered['entries']);
        $omitted = (int)($gathered['contextTruncated'] ?? 0);
        $eligible = (int)($gathered['entriesEligibleBeforeCap'] ?? ($sent + $omitted));
        $max     = (int)($gathered['maxContextEntries'] ?? 0);

        $meta = [
            'entries_sent_to_gemini'      => $sent,
            'entries_omitted_by_cap'      => $omitted,
            'entries_eligible_before_cap' => $eligible,
            'max_context_entries'         => $max,
            // Legacy keys (same semantics as above).
            'entry_count'                 => $sent,
        ];
        if ($omitted > 0) {
            $meta['context_truncated'] = $omitted;
        }

        return $meta;
    }

    /**
     * When Gemini omits used_entry_keys, match entry_type:entry_id tokens in the researcher
     * against gathered entries (order of first appearance, capped at $maxKeys).
     *
     * @param list<array<string, mixed>> $entries
     * @return list<string>
     */
    private function inferUsedEntryKeysFromResearcher(string $markdown, array $entries, int $maxKeys): array
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

        $attributed = ResearcherEntryCardPresenter::filterByUsedKeys($entries, $usedEntryKeys);

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
