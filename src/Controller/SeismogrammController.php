<?php

declare(strict_types=1);

namespace Seismo\Controller;

use PDO;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Service\GeminiResearcherException;
use Seismo\Service\Seismogramm\SeismogrammRequestContext;
use Seismo\Service\Seismogramm\SeismogrammOrchestrator;
use Seismo\Service\Seismogramm\SeismogrammContracts;
use Seismo\Service\Seismogramm\SeismogrammPipelineMeta;
use Seismo\Http\CsrfToken;
use Seismo\Repository\MagnituExportRepository;
use Seismo\Service\ResearcherEntryCardPresenter;
use Seismo\Service\ResearcherSourceSelection;
use Seismo\Service\Seismogramm\SeismogrammPresetProfile;

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
            $maxContextEntries = (new SeismogrammRequestContext())->readMaxContextEntries($configRepo);
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
            $capContext = $this->resolveCapContext($pdo, $requestContext, $filters, $_POST);
            $filters = $capContext['filters'];

            $gathered = $requestContext->gatherContext($pdo, $filters, false);
            $selectionPool = SeismogrammPresetProfile::filterSelectionPool(
                (string)$filters['preset'],
                $gathered['entries'],
            );
            if ($filters['preset'] === SeismogrammPresetProfile::BLINDSPOT && $selectionPool === []) {
                throw GeminiResearcherException::badResponse(
                    'Blindspot requires Lex or Leg entries in the gathered pool. Enable Lex and Leg sources and widen the lookback window.',
                );
            }

            $meta = array_merge(
                $requestContext->contextCapMetaFromGathered($gathered),
                [
                    'preset' => $filters['preset'],
                    'selection_pool_count' => count($selectionPool),
                    'markdown_chars' => $gathered['markdownChars'],
                    'context_warning' => $gathered['contextWarning'] ?? null,
                ]
            );

            echo json_encode(['ok' => true, 'meta' => $meta]);
        } catch (GeminiResearcherException $e) {
            http_response_code($e->httpStatus ?? 400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
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

        $preset = SeismogrammPresetProfile::BRIEFING;
        $capContext = [
            'filters'               => [],
            'original_cap'          => 0,
            'effective_cap'         => 0,
            'rate_limit_user_retry' => false,
        ];
        $requestContext = null;

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
            $capContext = $this->resolveCapContext($pdo, $requestContext, $filters, $_POST);
            $filters = $capContext['filters'];
            $requestContext->persistMaxContextEntries($pdo, $_POST['max_context_entries'] ?? null, $filters);

            $preset = $filters['preset'];
            $customAdvanced = (bool)($filters['customAdvanced'] ?? false);
            $moduleSelection = $filters['selection'];

            $itemCount = $requestContext->parseItemCount($_POST['item_count'] ?? null);
            $systemPrompt = $this->resolveSystemPrompt($config, $preset, $customAdvanced, $_POST);
            $researchQuery = trim((string)($_POST['research_query'] ?? ''));
            $briefingPersona = trim((string)($_POST['briefing_persona'] ?? ''));

            if ($researchQuery !== '') {
                $systemPrompt = str_replace('{researchQuery}', $researchQuery, $systemPrompt);
            }

            if ($briefingPersona !== '') {
                $systemPrompt = str_replace('{briefingPersona}', $briefingPersona, $systemPrompt);
            }

            $this->validateResolvedPrompt($preset, $systemPrompt);

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

            $formatterMeta = $gathered['formatterMeta'] ?? [];
            $useContextCache = (bool)($filters['useContextCache'] ?? false);

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
                $formatterMeta,
                $preset,
                $useContextCache,
                $moduleSelection instanceof ResearcherSourceSelection ? $moduleSelection : null,
                $filters['selectionMode'] ?? null,
                (bool)($filters['proSelectionMode'] ?? false)
            );

            $pipelineMeta = $orchestrator->lastPipelineMeta();
            $selectionMode = (string)($pipelineMeta['selection_mode'] ?? 'standard');

            // Attributed cards HTML
            $entriesHtml = '';
            if ($result->usedEntryKeys !== []) {
                $attributed = ResearcherEntryCardPresenter::filterByUsedKeys($entries, $result->usedEntryKeys);
                if ($attributed !== []) {
                    $entriesHtml = (new ResearcherEntryCardPresenter())->renderHtml($attributed, $scoresByKey);
                }
            }

            $usage = $result->usage;

            $meta = SeismogrammPipelineMeta::enrich(array_merge(
                $requestContext->contextCapMetaFromGathered($gathered),
                $pipelineMeta,
                $this->rateLimitMetaFromCapContext($capContext),
                [
                    'preset'            => $preset,
                    'lookback_days'     => $filters['lookbackDays'],
                    'cited_entry_count' => count($result->usedEntryKeys),
                    'used_entry_keys'   => $result->usedEntryKeys,
                    'gemini_usage'      => $usage,
                ],
            ));

            $costEstimate = $usage !== []
                ? SeismogrammPipelineMeta::buildCostEstimate($usage, $selectionMode)
                : null;

            echo json_encode([
                'ok' => true,
                'text' => $result->markdown,
                'meta' => $meta,
                'entries_html' => $entriesHtml,
                'cost_estimate' => $costEstimate,
            ], JSON_UNESCAPED_UNICODE);
        } catch (GeminiResearcherException $e) {
            $status = $e->httpStatus ?? 400;
            http_response_code($status);
            $payload = [
                'ok'    => false,
                'error' => $this->formatRateLimitErrorMessage($e, $preset),
            ];
            if ($e->isRateLimitExceeded()) {
                $payload = array_merge(
                    $payload,
                    $this->rateLimitFailureExtras(
                        $preset,
                        (int)($capContext['effective_cap'] ?? 0),
                        $requestContext,
                    ),
                );
            }
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
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
            $baseMode = trim((string)($_POST['base_mode'] ?? 'Briefing'));
            $config = new SystemConfigRepository(getDbConnection());
            
            $style = match ($baseMode) {
                'Blindspot' => SeismogrammContracts::DEFAULT_BLINDSPOT_PROMPT,
                'Research' => SeismogrammContracts::DEFAULT_RESEARCH_PROMPT,
                default => SeismogrammContracts::DEFAULT_BRIEFING_PROMPT,
            };
            
            $helper = new \Seismo\Service\ResearcherPromptHelperService($config);
            $prompt = $helper->reformulate($intent, $style);
            
            // Clean up prompt markdown code blocks if the LLM wrapped it
            if (preg_match('/^```(?:markdown|html|xml|json)?\s*(.*?)\s*```$/is', $prompt, $matches)) {
                $prompt = trim($matches[1]);
            }
            
            echo json_encode(['ok' => true, 'prompt' => $prompt], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            error_log('Seismo seismogramm_prompt_helper: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Could not generate prompt: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
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
            $name = trim((string)($_POST['name'] ?? ''));
            $content = trim((string)($_POST['content'] ?? ''));
            $id = trim((string)($_POST['id'] ?? ''));
            $knobsJson = trim((string)($_POST['knobs'] ?? ''));

            if ($name === '') {
                throw new \InvalidArgumentException('Preset name is required.');
            }

            if (in_array($name, ['Briefing', 'Blindspot', 'Research'], true)) {
                throw new \InvalidArgumentException('The default presets cannot be overwritten.');
            }

            $knobs = [];
            if ($knobsJson !== '') {
                $decoded = json_decode($knobsJson, true);
                if (is_array($decoded)) {
                    $knobs = $decoded;
                }
            }

            $pdo = getDbConnection();
            $config = new SystemConfigRepository($pdo);
            $raw = $config->getJson(self::CONFIG_KEY_PROMPT_LIBRARY, []);
            $library = is_array($raw) ? $raw : [];

            $foundIndex = -1;
            if ($id !== '') {
                foreach ($library as $idx => $row) {
                    if (($row['id'] ?? '') === $id) {
                        $foundIndex = $idx;
                        break;
                    }
                }
            } else {
                foreach ($library as $idx => $row) {
                    if (($row['name'] ?? '') === $name) {
                        $foundIndex = $idx;
                        break;
                    }
                }
            }

            if ($foundIndex !== -1) {
                $rowName = $library[$foundIndex]['name'] ?? '';
                if (in_array($rowName, ['Briefing', 'Blindspot', 'Research'], true)) {
                    throw new \InvalidArgumentException('The default presets cannot be overwritten.');
                }
                $library[$foundIndex]['name'] = $name;
                $library[$foundIndex]['content'] = $content;
                $library[$foundIndex]['knobs'] = $knobs;
                $library[$foundIndex]['is_custom'] = true;
            } else {
                $newId = bin2hex(random_bytes(8));
                $library[] = [
                    'id' => $newId,
                    'name' => $name,
                    'content' => $content,
                    'is_custom' => true,
                    'knobs' => $knobs
                ];
            }

            $config->setJson(self::CONFIG_KEY_PROMPT_LIBRARY, $library);

            echo json_encode(['ok' => true, 'presets' => $library], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            error_log('Seismo savePromptLibrary error: ' . $e->getMessage());
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
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
                throw new \InvalidArgumentException('Preset ID is required.');
            }

            $pdo = getDbConnection();
            $config = new SystemConfigRepository($pdo);
            $raw = $config->getJson(self::CONFIG_KEY_PROMPT_LIBRARY, []);
            $library = is_array($raw) ? $raw : [];

            $toDeleteIndex = -1;
            foreach ($library as $idx => $row) {
                if (($row['id'] ?? '') === $id) {
                    $toDeleteIndex = $idx;
                    break;
                }
            }

            if ($toDeleteIndex === -1) {
                throw new \InvalidArgumentException('Preset not found.');
            }

            $rowName = $library[$toDeleteIndex]['name'] ?? '';
            if (in_array($rowName, ['Briefing', 'Blindspot', 'Research'], true)) {
                throw new \InvalidArgumentException('The default presets cannot be deleted.');
            }

            unset($library[$toDeleteIndex]);
            $library = array_values($library);

            $config->setJson(self::CONFIG_KEY_PROMPT_LIBRARY, $library);

            echo json_encode(['ok' => true, 'presets' => $library], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            error_log('Seismo deletePromptLibrary error: ' . $e->getMessage());
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * @return array{filters: array<string, mixed>, original_cap: int, effective_cap: int, rate_limit_user_retry: bool}
     */
    private function resolveCapContext(
        PDO $pdo,
        SeismogrammRequestContext $requestContext,
        array $filters,
        array $post,
    ): array {
        $preset = (string)($filters['preset'] ?? SeismogrammPresetProfile::BRIEFING);
        $retryPosted = $requestContext->isRateLimitUserRetryPosted($post);

        if ($retryPosted && !SeismogrammPresetProfile::allowsRateLimitUserRetry($preset)) {
            throw new GeminiResearcherException(
                'Smaller-pool retry is not available for Research. Shorten the lookback window, reduce sources, or wait a few minutes.',
                400,
            );
        }

        $configRepo = new SystemConfigRepository($pdo);
        $configuredMax = $requestContext->readMaxContextEntries($configRepo);
        $postedRetryCap = isset($post['rate_limit_retry_cap']) && $post['rate_limit_retry_cap'] !== ''
            ? (int)$post['rate_limit_retry_cap']
            : null;

        return $requestContext->applyContextCapForRequest(
            $filters,
            $configuredMax,
            $post['max_context_entries'] ?? null,
            $retryPosted && SeismogrammPresetProfile::allowsRateLimitUserRetry($preset),
            $postedRetryCap,
        );
    }

    private function resolveSystemPrompt(
        SystemConfigRepository $config,
        string $preset,
        bool $customAdvanced,
        array $post,
    ): string {
        if ($customAdvanced) {
            return trim((string)($post['system_prompt'] ?? ''));
        }

        $library = $config->getJson(self::CONFIG_KEY_PROMPT_LIBRARY, []);
        if (is_array($library)) {
            foreach ($library as $row) {
                if (($row['name'] ?? '') === $preset) {
                    return trim((string)($row['content'] ?? ''));
                }
            }
        }

        return match (SeismogrammPresetProfile::normalizePreset($preset)) {
            SeismogrammPresetProfile::BLINDSPOT => SeismogrammContracts::DEFAULT_BLINDSPOT_PROMPT,
            SeismogrammPresetProfile::RESEARCH => SeismogrammContracts::DEFAULT_RESEARCH_PROMPT,
            default => SeismogrammContracts::DEFAULT_BRIEFING_PROMPT,
        };
    }

    private function validateResolvedPrompt(string $preset, string $systemPrompt): void
    {
        if (str_contains($systemPrompt, '{researchQuery}')) {
            throw new GeminiResearcherException('Research requires a topic query.', 400);
        }

        $preset = SeismogrammPresetProfile::normalizePreset($preset);
        if (in_array($preset, [SeismogrammPresetProfile::BRIEFING, SeismogrammPresetProfile::BLINDSPOT], true)
            && str_contains($systemPrompt, '{briefingPersona}')) {
            throw new GeminiResearcherException('This preset requires a persona/goal.', 400);
        }
    }

    /**
     * @param array{filters: array<string, mixed>, original_cap: int, effective_cap: int, rate_limit_user_retry: bool} $capContext
     * @return array<string, mixed>
     */
    private function rateLimitMetaFromCapContext(array $capContext): array
    {
        if (!($capContext['rate_limit_user_retry'] ?? false)) {
            return [];
        }

        return [
            'rate_limit_user_retry'      => true,
            'rate_limit_retry_original_cap' => (int)$capContext['original_cap'],
            'effective_cap'            => (int)$capContext['effective_cap'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rateLimitFailureExtras(
        string $preset,
        int $attemptedCap,
        ?SeismogrammRequestContext $requestContext,
    ): array {
        $extras = ['rate_limit_exceeded' => true];

        if (!SeismogrammPresetProfile::allowsRateLimitUserRetry($preset)) {
            $extras['rate_limit_retry_available'] = false;

            return $extras;
        }

        $retryCap = $attemptedCap > 0 && $requestContext !== null
            ? $requestContext->halveContextCap($attemptedCap)
            : 0;

        $extras['rate_limit_retry_available'] = $retryCap >= \Seismo\Service\ResearcherGeminiContext::MIN_MAX_CONTEXT_ENTRIES
            && ($attemptedCap <= 0 || $retryCap < $attemptedCap);
        if ($extras['rate_limit_retry_available']) {
            $extras['rate_limit_retry_cap'] = $retryCap;
        }

        return $extras;
    }

    private function formatRateLimitErrorMessage(GeminiResearcherException $e, string $preset): string
    {
        if (!$e->isRateLimitExceeded()) {
            return $e->getMessage();
        }

        if (!SeismogrammPresetProfile::allowsRateLimitUserRetry($preset)) {
            return 'Gemini rate limit exceeded. Research scans a large pool — try a shorter lookback window, '
                . 'fewer sources, or wait a few minutes before running again.';
        }

        return 'Gemini rate limit exceeded. Wait a few minutes, or retry with a smaller entry pool using the button below.';
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
                    if (in_array($name, ['Briefing', 'Blindspot'], true)
                        && strpos($row['content'] ?? '', '{briefingPersona}') === false) {
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
