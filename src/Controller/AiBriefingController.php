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

Erstelle aus den bereitgestellten Daten ein "Executive Briefing" nach ZWINGEND folgender Struktur:

# 📊 Executive Briefing: (ein kurzer prägnanter Titel, der klar macht, warum man das Briefing lesen soll)

**Zusammenfassung:** (Ein flüssiger Absatz, 3-4 Sätze. Was ist der makroökonomische oder politische rote Faden der wichtigsten Entwicklungen? VERBOTEN sind Meta-Einleitungen wie "Die heutigen Meldungen zeichnen ein Bild...". Steig im ersten Satz direkt in die harte Analyse ein.)

### 📌 Die wichtigsten Entwicklungen
(Wähle die strategisch relevantesten Einträge. Filtere weiche Themen gnadenlos heraus. Nutze exakt dieses Format:)

* **[Actionable Headline]:** [Ein kompakter, flüssig lesbarer Absatz (3-4 Sätze). 1. Nenne den konkreten aktuellen Auslöser. 2. Ordne die Dynamik dahinter politisch-wirtschaftlich ein. 3. Beschreibe den direkten, harten Impact auf Schweizer Unternehmen oder den Werkplatz. Nutze elegante, logische Übergänge.] *(Quelle: [Name der Quelle])*
* (Pro Top-Entwicklung ein Bullet; nach jedem Absatz eine Zeile leer.)

### 🔭 Radar / Ausblick
(Ein weitsichtiger Absatz, 2-3 Sätze zu einem aufkommenden strategischen Trend aus den Daten (z.B. neue EU-Regulierung mit Spillover-Effekt, geopolitische Shifts). VERBOTEN sind gesellschaftliche Kuriositäten. Es muss ein Thema sein, das CEOs für die strategische Planung auf dem Radar brauchen.)

Inhaltliche Regeln:
- Erfinde keine Fakten oder Quellen.
- Streiche jedes Adjektiv, das keinen informativen Mehrwert bietet.
PROMPT;

    /** Allowed “number of items” values in the Briefing Builder UI. */
    public const ALLOWED_ITEM_COUNTS = [5, 7, 10, 12, 15];

    public const DEFAULT_ITEM_COUNT = 5;

    private const MIN_SYSTEM_PROMPT_LEN = 20;

    private const MAX_SYSTEM_PROMPT_LEN = 8000;

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

        $geminiConfigured = false;
        $systemPrompt     = self::DEFAULT_SYSTEM_PROMPT;
        $savedPrompts     = [];

        try {
            $config = new SystemConfigRepository(getDbConnection());
            $key    = $config->get(SettingsController::KEY_GEMINI_API_KEY);
            $geminiConfigured = $key !== null && trim($key) !== '';
            $systemPrompt     = self::resolveStoredSystemPrompt($config);
            $savedPrompts     = self::ensurePromptLibrarySeeded($config, $systemPrompt);
        } catch (\Throwable $e) {
            error_log('Seismo briefing_builder show: ' . $e->getMessage());
        }

        $defaultLookbackDays = self::DEFAULT_LOOKBACK_DAYS;
        $defaultLimit        = self::DEFAULT_LIMIT;
        $maxLimit            = MagnituExportRepository::MAX_LIMIT;
        $defaultItemCount    = self::DEFAULT_ITEM_COUNT;
        $itemCountOptions    = self::ALLOWED_ITEM_COUNTS;

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

        $limit     = $this->clampLimit($_POST['limit'] ?? self::DEFAULT_LIMIT);
        $itemCount = $this->parseItemCount($_POST['item_count'] ?? null);

        try {
            $systemPrompt = $this->parseSystemPrompt($_POST['system_prompt'] ?? null);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);

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
            $result = $gemini->generateSummary($systemPrompt, $markdown, $itemCount);

            $entriesHtml     = '';
            $attributionMeta = [
                'context_entry_count'    => count($entries),
                'attributed_entry_count' => 0,
                'used_entry_keys'        => $result->usedEntryKeys,
                'attribution_filtered'   => false,
                'item_count'             => $itemCount,
                'cited_entry_count'      => count($result->usedEntryKeys),
            ];

            if ($result->attributionParsed) {
                [$cardsEntries, $attributionMeta] = $this->resolveAttributedEntries(
                    $entries,
                    $result->usedEntryKeys,
                    $itemCount,
                );
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
            $name    = $this->parsePromptLibraryName($_POST['name'] ?? null);
            $content = $this->parseSystemPrompt($_POST['content'] ?? null);
            $config  = new SystemConfigRepository(getDbConnection());
            $library = self::loadPromptLibrary($config);
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
            $warnings[] = $unmatched . ' cited ID(s) did not match gathered entries.';
        }

        if ($warnings !== []) {
            $meta['attribution_warning'] = implode(' ', $warnings);
        }

        $meta['attribution_filtered'] = true;

        return [$attributed, $meta];
    }
}
