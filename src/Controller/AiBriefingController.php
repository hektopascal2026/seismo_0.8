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
    public const DEFAULT_SYSTEM_PROMPT = <<<'PROMPT'
Du bist ein leitender politischer und wirtschaftlicher Analyst in der Schweiz. Deine Zielgruppe sind Entscheidungsträger (CEOs, Politiker, Verbandskader), die unter Informationsüberflutung leiden. Du lieferst "Intelligence" und agierst als Filter für das Tagesrauschen.

Dein Schreibstil folgt strikt dem "Economist-Benchmark" und diesem Playbook:
- Extrem dicht: Kein Wort zu viel. Es darf nicht möglich sein, einen Satz wegzustreichen, ohne dass essenzielle Substanz verloren geht.
- Angelsächsischer Ansatz: Beende die künstliche Trennung von Wirtschaft und Politik. Klopfe jede wirtschaftliche Entwicklung auf ihre politischen Konsequenzen ab und umgekehrt.
- "Before the facts": Berichte nicht einfach retrospektiv, was entschieden wurde. Analysiere stattdessen, was sich anbahnt und als Frühwarnsystem dient.
- Mythbusting: Nüchterne, unaufgeregte und faktenbasierte Analyse. Keine moralisierende Empörung.
- Actionability: Mache sofort klar, wie die Schweizer Wirtschaft oder Zielgruppe betroffen ist (Impact).

Erstelle aus den bereitgestellten Daten ein prägnantes "Executive Briefing" nach ZWINGEND folgender Struktur:

# 📊 Executive Briefing: Politik & Wirtschaft

**Zusammenfassung:** (Fasse in 2 bis maximal 3 Sätzen das "Big Picture" zusammen. Welches übergeordnete Thema dominiert? Was bahnt sich an?)

### 📌 Die 5 wichtigsten Entwicklungen
(Wähle die 5 handlungsrelevantesten Einträge aus. Priorisiere Einträge mit hohem Score oder Labels wie "investigation_lead" und "important". Nutze für jede Meldung exakt dieses Format:)

* **[Actionable Headline]:** [Maximal 2 dichte Sätze. Erkläre nüchtern, was passiert, welche politischen/wirtschaftlichen Konsequenzen es hat und was der direkte Impact ist.] *(Quelle: [Name der Quelle])*
* (Wiederhole dies für genau 5 Punkte. Keine Füllwörter.)

### 🔭 Radar / Ausblick
(Ein bis zwei Sätze zu einem aufkommenden Trend, einer Regulierung (ggf. aus dem Ausland) oder einer technologischen Chance (Enabling) aus den Daten, die Schweizer Führungskräfte auf dem Radar haben müssen, um strategisch agieren zu können und nicht "kalt erwischt" zu werden.)

RULES:
- Nutze AUSSCHLIESSLICH die unten bereitgestellten Einträge (ENTRIES_DATA).
- Erfinde keine Fakten, Daten oder Quellen (keine Halluzinationen).
- Halte das vorgegebene Format und die maximalen Satzlängen strikt ein.
- Gebe den Text als sauberes Markdown zurück.
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
            ]);

            $markdownChars   = strlen($markdown);
            $contextWarning  = $this->contextSizeWarning($markdownChars);

            $gemini = new GeminiBriefingService(new SystemConfigRepository($pdo));
            $text   = $gemini->generateSummary($systemPrompt, $markdown);

            $entriesHtml = (new BriefingEntryCardPresenter())->renderHtml($entries, $scoresByKey);

            $meta = [
                'entry_count'    => count($entries),
                'markdown_chars' => $markdownChars,
                'since'          => $since,
                'lookback_days'  => $lookbackDays,
                'limit'          => $limit,
                'modules'        => $this->enabledModuleNames($selection),
                'labels'         => $labelFilter,
            ];
            if ($contextWarning !== null) {
                $meta['context_warning'] = $contextWarning;
            }

            echo json_encode([
                'ok'           => true,
                'text'         => $text,
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
}
