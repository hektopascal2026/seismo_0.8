<?php

declare(strict_types=1);

namespace Seismo\Controller;

use PDO;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Service\Seismogramm\SeismogrammRequestContext;
use Seismo\Service\Seismogramm\SeismogrammOrchestrator;
use Seismo\Service\Seismogramm\SeismogrammContracts;
use Seismo\Http\CsrfToken;
use Seismo\Repository\MagnituExportRepository;
use Seismo\Service\ResearcherEntryCardPresenter;

final class SeismogrammController
{
    private const CONFIG_KEY_PROMPT_LIBRARY = 'ai_researcher_prompts'; // Shared with legacy Researcher

    public function show(): void
    {
        $csrfField = CsrfToken::field();
        $basePath  = getBasePath();

        $geminiConfigured = false;
        $savedPrompts = [];
        $initialActivePromptTabId = null;

        try {
            $pdo = getDbConnection();
            $config = new SystemConfigRepository($pdo);
            $key = $config->get(SettingsController::KEY_GEMINI_API_KEY);
            $geminiConfigured = $key !== null && trim($key) !== '';
            
            $savedPrompts = $this->ensurePresetsSeeded($config);
        } catch (\Throwable $e) {
            error_log('Seismo SeismogrammController show error: ' . $e->getMessage());
        }

        foreach ($savedPrompts as $row) {
            if ($row['name'] === 'Briefing') {
                $initialActivePromptTabId = $row['id'];
                break;
            }
        }

        $defaultLookbackDays = 7;
        $defaultLimit = 200;
        $maxLimit = MagnituExportRepository::BRIEFING_MAX_LIMIT;
        $defaultItemCount = 5;
        $itemCountOptions = [5, 7, 10, 12, 15];
        $alertThreshold = 0.60;
        $maxContextEntries = 100;

        try {
            $pdo = getDbConnection();
            $configRepo = new SystemConfigRepository($pdo);
            $alertThreshold = $configRepo->getAlertThreshold();
            $maxContextEntries = (int)($configRepo->get('researcher:max_context_entries') ?? '100');
        } catch (\Throwable $e) {
            error_log('Seismo SeismogrammController show config error: ' . $e->getMessage());
        }

        $alertThresholdPct = (int)round($alertThreshold * 100);

        require_once SEISMO_ROOT . '/views/helpers.php';
        require SEISMO_ROOT . '/views/seismogramm.php';
    }

    public function prepare(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            return;
        }

        if (!CsrfToken::verifyRequest(false)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']);
            return;
        }

        try {
            $pdo = getDbConnection();
            $requestContext = new SeismogrammRequestContext();
            $filters = $requestContext->parseFiltersFromPost($_POST, $pdo);
            $requestContext->persistMaxContextEntries($pdo, $_POST['max_context_entries'] ?? null);

            $gathered = $requestContext->gatherContext($pdo, $filters, false);
            $meta = array_merge(
                $requestContext->contextCapMetaFromGathered($gathered),
                [
                    'markdown_chars' => $gathered['markdownChars'],
                    'context_warning' => $gathered['contextWarning'] ?? null,
                ]
            );

            echo json_encode(['ok' => true, 'meta' => $meta]);
        } catch (\Throwable $e) {
            error_log('SeismogrammController prepare error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Could not prepare context.']);
        }
    }

    public function generate(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            return;
        }

        if (!CsrfToken::verifyRequest(false)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']);
            return;
        }

        try {
            $pdo = getDbConnection();
            $config = new SystemConfigRepository($pdo);
            $apiKey = $config->get(SettingsController::KEY_GEMINI_API_KEY);
            if ($apiKey === null || trim($apiKey) === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Gemini API key is not configured in Settings.']);
                return;
            }

            $requestContext = new SeismogrammRequestContext();
            $filters = $requestContext->parseFiltersFromPost($_POST, $pdo);
            $requestContext->persistMaxContextEntries($pdo, $_POST['max_context_entries'] ?? null);

            $itemCount = $requestContext->parseItemCount($_POST['item_count'] ?? null);
            $systemPrompt = trim((string)($_POST['system_prompt'] ?? ''));
            $researchQuery = trim((string)($_POST['research_query'] ?? ''));
            $briefingPersona = trim((string)($_POST['briefing_persona'] ?? ''));

            // Inject the research query into the prompt if present
            if ($researchQuery !== '') {
                $systemPrompt = str_replace('{researchQuery}', $researchQuery, $systemPrompt);
            }

            // Inject the briefing persona into the prompt if present
            if ($briefingPersona !== '') {
                $systemPrompt = str_replace('{briefingPersona}', $briefingPersona, $systemPrompt);
            }

            $model = $config->get('gemini:model') ?? 'gemini-3.5-flash';
            $maxOutputTokens = (int)($config->get('gemini:max_output_tokens') ?? '65536');

            // Gather context with full body texts
            $gathered = $requestContext->gatherContext($pdo, $filters, true);
            $entries = $gathered['entries'];
            $scoresByKey = $gathered['scoresByKey'];
            $xmlContext = $gathered['markdown'];

            if ($entries === []) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'No entries matched your filters.']);
                return;
            }

            $selectionMode = trim((string)($_POST['selection_mode'] ?? 'standard'));

            $orchestrator = new SeismogrammOrchestrator();
            $result = $orchestrator->generateBriefing(
                $apiKey,
                $model,
                $systemPrompt,
                $xmlContext,
                $itemCount,
                $maxOutputTokens,
                $entries,
                $scoresByKey,
                $gathered,
                $selectionMode
            );

            // Attributed cards HTML
            $entriesHtml = '';
            if ($result->usedEntryKeys !== []) {
                $attributed = ResearcherEntryCardPresenter::filterByUsedKeys($entries, $result->usedEntryKeys);
                if ($attributed !== []) {
                    $entriesHtml = (new ResearcherEntryCardPresenter())->renderHtml($attributed, $scoresByKey);
                }
            }

            $meta = array_merge(
                $requestContext->contextCapMetaFromGathered($gathered),
                [
                    'lookback_days' => $filters['lookbackDays'],
                    'cited_entry_count' => count($result->usedEntryKeys),
                    'used_entry_keys' => $result->usedEntryKeys,
                ]
            );

            $usage = $result->usage;
            $costEstimate = null;
            if ($usage !== []) {
                $promptTokens = $usage['prompt_tokens'] ?? 0;
                $outputTokens = $usage['output_tokens'] ?? 0;
                $apiCalls     = $usage['api_calls'] ?? 0;
                $usd = \Seismo\Service\GeminiResearcherFlashPricing::estimateStandardUsd($promptTokens, $outputTokens);
                $costEstimate = [
                    'pipeline' => $selectionMode,
                    'prompt_tokens' => $promptTokens,
                    'output_tokens' => $outputTokens,
                    'api_calls' => $apiCalls,
                    'estimated_usd_display' => \Seismo\Service\GeminiResearcherFlashPricing::formatUsd($usd),
                ];
            }

            echo json_encode([
                'ok' => true,
                'text' => $result->markdown,
                'meta' => $meta,
                'entries_html' => $entriesHtml,
                'cost_estimate' => $costEstimate,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            error_log('SeismogrammController generate error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Briefing generation failed: ' . $e->getMessage()]);
        }
    }

    public function saveDefaultPrompt(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
    }

    public function promptHelper(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'prompt' => 'Helper prompt draft']);
    }

    public function savePromptLibrary(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
    }

    public function deletePromptLibrary(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
    }

    private function ensurePresetsSeeded(SystemConfigRepository $config): array
    {
        $raw = $config->getJson(self::CONFIG_KEY_PROMPT_LIBRARY, []);
        $library = is_array($raw) ? $raw : [];

        $presets = [
            'Briefing' => SeismogrammContracts::DEFAULT_BRIEFING_PROMPT,
            'Blindspot' => SeismogrammContracts::DEFAULT_BLINDSPOT_PROMPT,
            'Research' => SeismogrammContracts::DEFAULT_RESEARCH_PROMPT,
        ];

        $changed = false;
        foreach ($presets as $name => $content) {
            $found = false;
            foreach ($library as &$row) {
                if (($row['name'] ?? '') === $name) {
                    $found = true;
                    // If Briefing preset is old and lacks the placeholder, update it.
                    if ($name === 'Briefing' && strpos($row['content'] ?? '', '{briefingPersona}') === false) {
                        $row['content'] = $content;
                        $changed = true;
                    }
                    break;
                }
            }
            unset($row);
            if (!$found) {
                $library[] = [
                    'id' => bin2hex(random_bytes(8)),
                    'name' => $name,
                    'content' => $content
                ];
                $changed = true;
            }
        }

        if ($changed) {
            $config->setJson(self::CONFIG_KEY_PROMPT_LIBRARY, $library);
        }

        return $library;
    }
}
