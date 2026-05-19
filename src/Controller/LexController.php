<?php

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Config\LexConfigStore;
use Seismo\Http\CsrfToken;
use Seismo\Plugin\LexEu\LexEuPlugin;
use Seismo\Plugin\LexFedlex\LexFedlexPlugin;
use Seismo\Plugin\LexLegifrance\LexLegifrancePlugin;
use Seismo\Repository\LexItemRepository;
use Seismo\Service\RefreshAllService;

final class LexController
{
    private const LIST_LIMIT = 50;

    public function show(): void
    {
        $csrfField = CsrfToken::field();

        $lexItems = [];
        $lexCfg = [];
        $enabledLexSources = [];
        $activeSources = [];
        $lastBySource = [];
        $pageError = null;

        try {
            $pdo = getDbConnection();
            $lexCfg = (new LexConfigStore())->load();
            $enabledLexSources = array_values(array_filter(
                LexItemRepository::LEX_PAGE_SOURCES,
                static function (string $s) use ($lexCfg): bool {
                    return !empty($lexCfg[$s]['enabled']);
                }
            ));

            $sourcesSubmitted = isset($_GET['sources_submitted']);
            if ($sourcesSubmitted) {
                $activeSources = isset($_GET['sources']) ? (array)$_GET['sources'] : [];
            } else {
                $activeSources = $enabledLexSources;
            }
            $activeSources = array_values(array_intersect($activeSources, $enabledLexSources));

            $repo = new LexItemRepository($pdo);
            if ($activeSources !== []) {
                $lexItems = $repo->listBySources($activeSources, self::LIST_LIMIT, 0);
            }

            $lastBySource = $repo->getLastFetchedBySources(LexItemRepository::LEX_PAGE_SOURCES);
        } catch (\Throwable $e) {
            error_log('Seismo lex: ' . $e->getMessage());
            $pageError = 'Could not load legislation list. Check error_log for details.';
        }

        $lastFetchedBySource = $lastBySource;

        $basePath = getBasePath();
        $satellite = isSatellite();
        $chCfg = is_array($lexCfg['ch'] ?? null) ? $lexCfg['ch'] : [];
        $euCfg = is_array($lexCfg['eu'] ?? null) ? $lexCfg['eu'] : [];
        $deCfg = is_array($lexCfg['de'] ?? null) ? $lexCfg['de'] : [];
        $frCfg = is_array($lexCfg['fr'] ?? null) ? $lexCfg['fr'] : [];

        require_once SEISMO_ROOT . '/views/helpers.php';
        require SEISMO_ROOT . '/views/lex.php';
    }

    public function refreshAllLex(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirectToLex();

            return;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirectToLex();

            return;
        }
        if (isSatellite()) {
            $_SESSION['error'] = 'Satellite mode: refresh runs on the mothership.';
            $this->redirectToLex();

            return;
        }

        set_time_limit(300);
        try {
            $pdo     = getDbConnection();
            $results = RefreshAllService::boot($pdo)->runAllLexItemPlugins(true);
        } catch (\Throwable $e) {
            error_log('Seismo refresh_lex_all: ' . $e->getMessage());
            $_SESSION['error'] = 'Lex refresh failed: ' . $e->getMessage();
            $this->redirectToLex();

            return;
        }

        RefreshAllService::applySessionFlashForAggregateResults($results, 'Lex legislation sources');
        $this->redirectToLex();
    }

    public function refreshFedlex(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirectToLex();

            return;
        }

        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirectToLex();

            return;
        }

        try {
            $pdo = getDbConnection();
            $result = RefreshAllService::boot($pdo)->runPlugin('fedlex', true);
        } catch (\Throwable $e) {
            error_log('Seismo refresh_fedlex: ' . $e->getMessage());
            $_SESSION['error'] = 'Fedlex refresh failed: ' . $e->getMessage();
            $this->redirectToLex();

            return;
        }

        if ($result->isOk()) {
            $_SESSION['success'] = 'Fedlex refresh finished: ' . $result->count . ' row(s) processed.';
        } elseif ($result->status === 'skipped') {
            $_SESSION['error'] = $result->message ?? 'Fedlex refresh skipped.';
        } else {
            $_SESSION['error'] = 'Fedlex refresh failed: ' . ($result->message ?? 'unknown error');
        }

        $this->redirectToLex();
    }

    public function saveLexCh(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirectToLex();

            return;
        }

        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirectToLex();

            return;
        }

        $store = new LexConfigStore();
        $isEnabled = $this->postEnabledClosure();

        try {
            $full = $store->load();
            $ch = is_array($full['ch'] ?? null) ? $full['ch'] : [];
            $ch['enabled'] = $isEnabled('ch_enabled', (bool)($ch['enabled'] ?? true));
            $ch['language'] = LexFedlexPlugin::normalizeFedlexLanguage(
                (string)($_POST['ch_language'] ?? $ch['language'] ?? 'DEU')
            );
            $ch['lookback_days'] = max(1, (int)($_POST['ch_lookback_days'] ?? $ch['lookback_days'] ?? 90));
            $ch['limit'] = max(1, (int)($_POST['ch_limit'] ?? $ch['limit'] ?? 100));
            $ch['notes'] = trim((string)($_POST['ch_notes'] ?? $ch['notes'] ?? ''));
            if (($_POST['ch_fedlex_settings_form'] ?? '') === '1') {
                $ch['ingest_vernehmlassungen'] = isset($_POST['ch_ingest_vernehmlassungen']);
            }

            $rtRaw = trim((string)($_POST['ch_resource_types'] ?? ''));
            if ($rtRaw !== '') {
                $ids = array_filter(array_map('intval', preg_split('/[\s,]+/', $rtRaw)));
                $existingTypes = [];
                foreach (($ch['resource_types'] ?? []) as $rt) {
                    if (is_array($rt) && isset($rt['id'])) {
                        $existingTypes[(int)$rt['id']] = $rt['label'] ?? '';
                    }
                }
                $newTypes = [];
                foreach ($ids as $id) {
                    $newTypes[] = ['id' => $id, 'label' => $existingTypes[$id] ?? 'Type ' . $id];
                }
                $ch['resource_types'] = $newTypes;
            }

            $store->saveChBlock($ch);
            $_SESSION['success'] = 'Swiss Fedlex settings saved.';
        } catch (\Throwable $e) {
            error_log('Seismo save_lex_ch: ' . $e->getMessage());
            $_SESSION['error'] = 'Could not save Fedlex settings.';
        }

        $this->redirectToLex();
    }

    public function refreshLexEu(): void
    {
        $this->runLexPluginRefresh('lex_eu', 'EUR-Lex');
    }

    public function refreshRechtBund(): void
    {
        $this->runLexPluginRefresh('recht_bund', 'recht.bund.de');
    }

    public function refreshLegifrance(): void
    {
        $this->runLexPluginRefresh('legifrance', 'Légifrance');
    }

    public function saveLexEu(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirectToLex();

            return;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirectToLex();

            return;
        }

        $store = new LexConfigStore();
        $isEnabled = $this->postEnabledClosure();

        try {
            $full = $store->load();
            $eu = is_array($full['eu'] ?? null) ? $full['eu'] : [];
            $eu['enabled'] = $isEnabled('eu_enabled', (bool)($eu['enabled'] ?? true));
            $eu['endpoint'] = trim((string)($_POST['eu_endpoint'] ?? $eu['endpoint'] ?? ''));
            if ($eu['endpoint'] === '') {
                $eu['endpoint'] = (string)($store->defaultConfig()['eu']['endpoint'] ?? '');
            }
            $eu['language'] = LexEuPlugin::normalizeLanguage((string)($_POST['eu_language'] ?? $eu['language'] ?? 'ENG'));
            $eu['document_class'] = trim((string)($_POST['eu_document_class'] ?? $eu['document_class'] ?? 'cdm:legislation_secondary'));
            LexEuPlugin::documentClassToIri($eu['document_class']);
            $eu['lookback_days'] = max(1, (int)($_POST['eu_lookback_days'] ?? $eu['lookback_days'] ?? 90));
            $eu['limit'] = max(1, min((int)($_POST['eu_limit'] ?? $eu['limit'] ?? 100), 200));
            $eu['notes'] = trim((string)($_POST['eu_notes'] ?? $eu['notes'] ?? ''));

            $store->savePluginBlock('eu', $eu);
            $_SESSION['success'] = 'EUR-Lex settings saved.';
        } catch (\Throwable $e) {
            error_log('Seismo save_lex_eu: ' . $e->getMessage());
            $_SESSION['error'] = 'Could not save EUR-Lex settings: ' . $e->getMessage();
        }

        $this->redirectToLex();
    }

    public function saveLexDe(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirectToLex();

            return;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirectToLex();

            return;
        }

        $store = new LexConfigStore();
        $isEnabled = $this->postEnabledClosure();

        try {
            $full = $store->load();
            $de = is_array($full['de'] ?? null) ? $full['de'] : [];
            $de['enabled'] = $isEnabled('de_enabled', (bool)($de['enabled'] ?? true));
            $de['feed_url'] = trim((string)($_POST['de_feed_url'] ?? $de['feed_url'] ?? ''));
            $de['lookback_days'] = max(1, (int)($_POST['de_lookback_days'] ?? $de['lookback_days'] ?? 90));
            $de['limit'] = max(1, min((int)($_POST['de_limit'] ?? $de['limit'] ?? 100), 200));

            $exRaw = trim((string)($_POST['de_exclude_document_types'] ?? ''));
            if ($exRaw === '') {
                $de['exclude_document_types'] = [];
            } else {
                $chunks = preg_split('/[\s,;]+/u', $exRaw) ?: [];
                $excludeList = [];
                foreach ($chunks as $chunk) {
                    if (!is_string($chunk)) {
                        continue;
                    }
                    $t = trim($chunk);
                    if ($t === '') {
                        continue;
                    }
                    if (mb_strlen($t) > 64) {
                        $t = mb_substr($t, 0, 64);
                    }
                    $excludeList[] = $t;
                }
                $de['exclude_document_types'] = array_values(array_unique($excludeList));
            }

            $de['notes'] = trim((string)($_POST['de_notes'] ?? $de['notes'] ?? ''));

            $store->savePluginBlock('de', $de);
            $_SESSION['success'] = 'recht.bund.de settings saved.';
        } catch (\Throwable $e) {
            error_log('Seismo save_lex_de: ' . $e->getMessage());
            $_SESSION['error'] = 'Could not save DE settings.';
        }

        $this->redirectToLex();
    }

    public function saveLexFr(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirectToLex();

            return;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirectToLex();

            return;
        }

        $store = new LexConfigStore();
        $isEnabled = $this->postEnabledClosure();

        try {
            $full = $store->load();
            $fr = is_array($full['fr'] ?? null) ? $full['fr'] : [];
            $fr['enabled'] = $isEnabled('fr_enabled', (bool)($fr['enabled'] ?? false));
            $fr['client_id'] = trim((string)($_POST['fr_client_id'] ?? $fr['client_id'] ?? ''));

            $secretIn = (string)($_POST['fr_client_secret'] ?? '');
            $trimSecret = trim($secretIn);
            $looksPlaceholder = $trimSecret === '' || preg_match('/^[•●·\s]+$/u', $trimSecret) === 1;
            if (!$looksPlaceholder) {
                $fr['client_secret'] = $secretIn;
            }

            $fr['oauth_token_url'] = trim((string)($_POST['fr_oauth_token_url'] ?? $fr['oauth_token_url'] ?? ''));
            if ($fr['oauth_token_url'] === '') {
                $fr['oauth_token_url'] = (string)($store->defaultConfig()['fr']['oauth_token_url'] ?? '');
            }
            $fr['api_base_url'] = trim((string)($_POST['fr_api_base_url'] ?? $fr['api_base_url'] ?? ''));
            if ($fr['api_base_url'] === '') {
                $fr['api_base_url'] = (string)($store->defaultConfig()['fr']['api_base_url'] ?? '');
            }

            $fond = strtoupper(trim((string)($_POST['fr_fond'] ?? $fr['fond'] ?? 'JORF')));
            if (!in_array($fond, LexLegifrancePlugin::ALLOWED_FONDS, true)) {
                throw new \InvalidArgumentException('Unsupported Légifrance fond.');
            }
            $fr['fond'] = $fond;

            $natRaw = trim((string)($_POST['fr_natures'] ?? ''));
            if ($natRaw !== '') {
                $fr['natures'] = array_values(array_filter(array_map(
                    static fn (string $s): string => strtoupper(trim($s)),
                    preg_split('/[\s,]+/', $natRaw) ?: []
                )));
            } elseif (!isset($fr['natures']) || !is_array($fr['natures'])) {
                $fr['natures'] = ['LOI', 'ORDONNANCE', 'DECRET'];
            }

            $fr['lookback_days'] = max(1, (int)($_POST['fr_lookback_days'] ?? $fr['lookback_days'] ?? 90));
            $fr['limit'] = max(1, min((int)($_POST['fr_limit'] ?? $fr['limit'] ?? 100), 200));
            $fr['notes'] = trim((string)($_POST['fr_notes'] ?? $fr['notes'] ?? ''));

            $store->savePluginBlock('fr', $fr);
            $_SESSION['success'] = 'Légifrance settings saved.';
        } catch (\Throwable $e) {
            error_log('Seismo save_lex_fr: ' . $e->getMessage());
            $_SESSION['error'] = 'Could not save Légifrance settings: ' . $e->getMessage();
        }

        $this->redirectToLex();
    }

    private function runLexPluginRefresh(string $pluginId, string $label): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirectToLex();

            return;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirectToLex();

            return;
        }

        try {
            $pdo = getDbConnection();
            $result = RefreshAllService::boot($pdo)->runPlugin($pluginId, true);
        } catch (\Throwable $e) {
            error_log('Seismo refresh ' . $pluginId . ': ' . $e->getMessage());
            $_SESSION['error'] = $label . ' refresh failed: ' . $e->getMessage();
            $this->redirectToLex();

            return;
        }

        if ($result->isOk()) {
            $_SESSION['success'] = $label . ' refresh finished: ' . $result->count . ' row(s) processed.';
        } elseif ($result->status === 'skipped') {
            $_SESSION['error'] = $result->message ?? ($label . ' refresh skipped.');
        } else {
            $_SESSION['error'] = $label . ' refresh failed: ' . ($result->message ?? 'unknown error');
        }

        $this->redirectToLex();
    }

    /**
     * @return callable(string, bool): bool
     */
    private function postEnabledClosure(): callable
    {
        return static function (string $field, bool $default = false): bool {
            if (!array_key_exists($field, $_POST)) {
                return $default;
            }
            $raw = $_POST[$field];
            if (is_array($raw)) {
                return $raw !== [];
            }
            $value = strtolower(trim((string)$raw));

            return in_array($value, ['1', 'true', 'yes', 'on'], true);
        };
    }

    private function redirectToLex(): void
    {
        $base = getBasePath();
        header('Location: ' . $base . '/index.php?action=lex', true, 303);
        exit;
    }
}
