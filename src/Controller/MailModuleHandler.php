<?php

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Http\CsrfToken;
use Seismo\Http\RefreshAjax;
use Seismo\Mail\MailModule;
use Seismo\Repository\EmailSubscriptionRepository;
use Seismo\Repository\EntryRepository;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Service\EmailSubscriptionReprocessService;
use Seismo\Service\RefreshAllService;

final class MailModuleHandler
{
    private const LIST_LIMIT = 50;

    public function __construct(private readonly MailModule $module)
    {
    }

    public function show(): void
    {
        $csrfField = CsrfToken::field();
        $basePath  = getBasePath();
        $satellite = isSatellite();
        $mailModule = $this->module;

        $viewParam = (string)($_GET['view'] ?? '');
        $view = ($viewParam === 'sources' || $viewParam === 'subscriptions') ? 'sources' : 'items';
        $editId = (int)($_GET['edit'] ?? 0);
        $subscriptionId = (int)($_GET['subscription'] ?? 0);

        $allItems             = [];
        $subscriptions        = [];
        $pendingSenders       = [];
        $driftingSubscriptions = [];
        $subscriptionLatest   = [];
        $pendingLatest        = [];
        $subscriptionFilter   = null;
        $editRow              = null;
        $reviewingPending     = false;
        $pageError            = null;
        $alertThreshold       = 0.60;
        $categorySuggestions  = [];

        try {
            $pdo = getDbConnection();
            $config = new SystemConfigRepository($pdo);
            $raw    = $config->get('alert_threshold');
            if ($raw !== null && $raw !== '' && is_numeric($raw)) {
                $alertThreshold = max(0.0, min(1.0, (float)$raw));
            }

            $entryRepo = new EntryRepository($pdo);
            $subRepo   = new EmailSubscriptionRepository($pdo);

            if ($view === 'items' && $subscriptionId > 0) {
                $subForFilter = $subRepo->findById($subscriptionId);
                if ($subForFilter !== null && EmailSubscriptionRepository::rowModuleScope($subForFilter) === $this->module->scope) {
                    $subscriptionFilter = $subForFilter;
                    $allItems = $entryRepo->getEmailModuleTimelineForSubscription(
                        $subscriptionId,
                        self::LIST_LIMIT,
                        0,
                        $this->module->scope,
                    );
                } else {
                    $allItems = $entryRepo->getEmailModuleTimeline(self::LIST_LIMIT, 0, $this->module->scope);
                }
            } else {
                $allItems = $entryRepo->getEmailModuleTimeline(self::LIST_LIMIT, 0, $this->module->scope);
            }

            $subscriptions = $subRepo->listActiveForModule($this->module->scope, EmailSubscriptionRepository::MAX_LIMIT, 0);
            if ($this->module->showsPendingSenders) {
                $pendingSenders = $subRepo->listPendingForModule($this->module->scope, EmailSubscriptionRepository::MAX_LIMIT, 0);
            }
            if ($this->module->scope === MailModule::SCOPE_NEWSLETTER) {
                $driftingSubscriptions = $subRepo->listDriftingForModule($this->module->scope);
            }
            if (!$satellite) {
                $categorySuggestions = $subRepo->listUsedCategories();
            }
            if ($view === 'sources') {
                $ingestRepo = new \Seismo\Repository\EmailIngestRepository($pdo);
                foreach ($subscriptions as $idx => $row) {
                    $sid = (int)$row['id'];
                    $subscriptionLatest[$sid] = $entryRepo->peekLatestEmailForSubscription(
                        $sid,
                        $this->module->scope,
                    );
                    $rule = $ingestRepo->fetchTemplateRuleForSubscription($row);
                    if ($rule !== null) {
                        $subscriptions[$idx]['digest_split_config'] = json_encode($rule, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    } else {
                        $subscriptions[$idx]['digest_split_config'] = null;
                    }
                }
                foreach ($pendingSenders as $row) {
                    $sid = (int)$row['id'];
                    $pendingLatest[$sid] = $entryRepo->peekLatestEmailForSubscription(
                        $sid,
                        $this->module->scope,
                    );
                }
            }
            $editRowSamples = [];
            if ($editId > 0) {
                $editRow = $subRepo->findById($editId);
                if ($editRow !== null && EmailSubscriptionRepository::rowModuleScope($editRow) !== $this->module->scope) {
                    $editRow = null;
                }
                if ($editRow !== null) {
                    if (EmailSubscriptionRepository::isPendingRow($editRow)) {
                        $reviewingPending = true;
                    }
                    $ingestRepo = new \Seismo\Repository\EmailIngestRepository($pdo);
                    $rule = $ingestRepo->fetchTemplateRuleForSubscription($editRow);
                    if ($rule !== null) {
                        $editRow['digest_split_config'] = json_encode($rule, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    } else {
                        $editRow['digest_split_config'] = '';
                    }
                    $editRowEmails = $ingestRepo->fetchRowsForSubscription($editRow, 5);
                    require_once SEISMO_ROOT . '/views/helpers.php';
                    $cleanup = json_decode((string)($editRow['cleanup_config'] ?? ''), true);
                    $kws = is_array($cleanup) && !empty($cleanup['webview_keywords']) ? (array)$cleanup['webview_keywords'] : [];
                    foreach ($editRowEmails as $email) {
                        $editRowSamples[] = [
                            'subject' => (string)($email['subject'] ?? ''),
                            'body' => (string)($email['text_body'] ?? $email['body_text'] ?? ''),
                            'webview_url' => seismo_email_web_view_url($email, $kws),
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('Seismo ' . $this->module->action . ': ' . $e->getMessage());
            $pageError = 'Could not load ' . $this->module->pageTitle . ' page. Check error_log for details.';
        }

        require_once SEISMO_ROOT . '/views/helpers.php';

        $showDaySeparators = true;
        $showFavourites    = true;
        $searchQuery       = '';
        $returnQuery       = $this->buildReturnQuery();
        $currentView       = 'newest';
        $emptyTimelineHint = 'default';
        $timelineFilter    = \Seismo\Repository\TimelineFilter::fromQueryArray([]);
        $filterPillOptions = ['feed_categories' => [], 'lex_sources' => [], 'email_tags' => [], 'newsletter_tags' => []];
        $dashboardError    = $pageError;

        $showModuleRefresh       = !$satellite;
        $moduleRefreshAction     = $this->module->refreshAction;
        $moduleRefreshLabel      = $this->module->refreshLabel;
        $moduleRefreshReturnView = $view;

        require SEISMO_ROOT . '/views/mail.php';
    }

    public function refreshMailIngest(): void
    {
        $finish = function (): void {
            RefreshAjax::respondOrRedirect(function (): void {
                $this->redirectAfterMailRefresh();
            });
        };

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Location: ?action=' . $this->module->action, true, 303);
            exit;
        }
        if (!CsrfToken::verifyRequest(rotateOnSuccess: false)) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $finish();

            return;
        }
        if (isSatellite()) {
            $_SESSION['error'] = 'Satellite mode: refresh runs on the mothership.';
            $finish();

            return;
        }

        set_time_limit(300);
        try {
            $pdo     = getDbConnection();
            $results = RefreshAllService::boot($pdo)->runMailModuleCoreFetcher(true);
        } catch (\Throwable $e) {
            error_log('Seismo refresh_mail_ingest: ' . $e->getMessage());
            $_SESSION['error'] = 'Refresh failed: ' . $e->getMessage();
            $finish();

            return;
        }

        RefreshAllService::applySessionFlashForAggregateResults($results, 'Mail (IMAP)');
        $finish();
    }

    private function redirectAfterMailRefresh(): void
    {
        $v = trim((string)($_POST['return_view'] ?? ''));
        if ($v === 'sources' || $v === 'subscriptions') {
            header('Location: ?' . http_build_query(['action' => $this->module->action, 'view' => 'sources']), true, 303);
        } else {
            header('Location: ?action=' . $this->module->action, true, 303);
        }
        exit;
    }

    public function saveSubscription(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirect(['view' => 'sources']);

            return;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirect(['view' => 'sources']);

            return;
        }
        if (isSatellite()) {
            $_SESSION['error'] = 'Satellite mode — email subscriptions are managed on the mothership only.';
            $this->redirect(['view' => 'sources']);

            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        try {
            $repo = new EmailSubscriptionRepository(getDbConnection());
            $payload = [
                'match_type'             => (string)($_POST['match_type'] ?? 'domain'),
                'match_value'            => (string)($_POST['match_value'] ?? ''),
                'display_name'           => (string)($_POST['display_name'] ?? ''),
                'category'               => (string)($_POST['category'] ?? ''),
                'disabled'               => ((string)($_POST['disabled'] ?? '0')) === '1',
                'show_in_magnitu'        => ((string)($_POST['show_in_magnitu'] ?? '0')) === '1',
                'strip_listing_boilerplate' => ((string)($_POST['strip_listing_boilerplate'] ?? '0')) === '1',
                'body_processor'         => (string)($_POST['body_processor'] ?? ''),
                'cleanup_config'         => (string)($_POST['cleanup_config'] ?? ''),
                'subject_filter'         => (string)($_POST['subject_filter'] ?? ''),
                'digest_split_config'    => (string)($_POST['digest_split_config'] ?? ''),
                'unsubscribe_url'        => (string)($_POST['unsubscribe_url'] ?? ''),
                'unsubscribe_mailto'     => (string)($_POST['unsubscribe_mailto'] ?? ''),
                'unsubscribe_one_click'  => ((string)($_POST['unsubscribe_one_click'] ?? '0')) === '1',
                'module_scope'           => $this->module->scope,
            ];
            $rawDigestConfig = (string)($_POST['digest_split_config'] ?? '');
            $digestForSave = $this->mergeDigestSplitConfigNoiseFeedback(
                $rawDigestConfig,
                (string)($_POST['digest_split_feedback'] ?? '')
            );
            $canonicalDigest = \Seismo\Core\Mail\DigestSplitConfigNormalizer::canonicalJson($digestForSave);
            $resolvedRules = null;
            if ($canonicalDigest !== null) {
                $decoded = json_decode($canonicalDigest, true);
                if (is_array($decoded) && !empty($decoded['is_digest'])) {
                    $resolvedRules = $decoded['split_rules'] ?? null;
                }
            }

            $pdo = getDbConnection();

            if ($id > 0) {
                $existing = $repo->findById($id);
                if ($existing === null || EmailSubscriptionRepository::rowModuleScope($existing) !== $this->module->scope) {
                    throw new \InvalidArgumentException('Subscription not found in this module.');
                }
                $wasPending = EmailSubscriptionRepository::isPendingRow($existing);

                $ingestRepo = new \Seismo\Repository\EmailIngestRepository($pdo);
                $oldRule = $ingestRepo->fetchTemplateRuleForSubscription($existing);
                $oldDigest = $oldRule !== null ? json_encode($oldRule) : '';

                // Save relational rules
                $this->saveRelationalSplitRules($pdo, $payload['match_value'], $payload['display_name'], $resolvedRules);

                // Omit/nullify digest_split_config from payload to update subscription
                $payload['digest_split_config'] = null;
                $repo->update($id, $payload);

                $_SESSION['success'] = $wasPending
                    ? $this->module->pendingConfirmSuccessMessage
                    : 'Subscription updated.';
                if ($wasPending) {
                    $backfilled = $repo->backfillEmailSubscriptionIds();
                    if ($backfilled > 0) {
                        $_SESSION['success'] .= ' Linked ' . $backfilled . ' stored message(s) to this subscription.';
                    }
                }

                $newRule = $ingestRepo->fetchTemplateRuleForSubscription($payload);
                $newDigest = $newRule !== null ? json_encode($newRule) : '';

                if ($newDigest !== '' && $newDigest !== $oldDigest) {
                    $reprocessed = (new EmailSubscriptionReprocessService($pdo))
                        ->reprocessSubscription($id);
                    $_SESSION['success'] .= ' Reprocessed ' . $reprocessed . ' stored message(s) with split config.';
                }
            } else {
                $payload['digest_split_config'] = null;
                $newId = $repo->insert($payload);
                $_SESSION['success'] = 'Subscription added (#' . $newId . ').';

                // Save relational rules
                $this->saveRelationalSplitRules($pdo, $payload['match_value'], $payload['display_name'], $resolvedRules);

                if ($canonicalDigest !== null) {
                    $reprocessed = (new EmailSubscriptionReprocessService($pdo))
                        ->reprocessSubscription($newId);
                    $_SESSION['success'] .= ' Reprocessed ' . $reprocessed . ' stored message(s) with split config.';
                }
            }
        } catch (\Throwable $e) {
            error_log('Seismo ' . $this->module->saveAction . ': ' . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();
        }

        \Seismo\Http\RefreshAjax::respondOrRedirect(function (): void {
            $this->redirect(['view' => 'sources']);
        });
    }

    public function analyzeBoilerplate(): void
    {
        set_time_limit(300);
        header('Content-Type: application/json');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit;
        }
        if (!CsrfToken::verifyRequest(rotateOnSuccess: false)) {
            http_response_code(403);
            echo json_encode(['error' => 'Session expired — please try again.']);
            exit;
        }
        if (isSatellite()) {
            http_response_code(403);
            echo json_encode(['error' => 'Satellite mode — AI configuration runs on the mothership only.']);
            exit;
        }

        $subscriptionId = (int)($_POST['id'] ?? 0);
        if ($subscriptionId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid subscription ID']);
            exit;
        }

        try {
            $pdo = getDbConnection();
            $subRepo = new EmailSubscriptionRepository($pdo);
            $sub = $subRepo->findById($subscriptionId);
            if ($sub === null || EmailSubscriptionRepository::rowModuleScope($sub) !== $this->module->scope) {
                http_response_code(404);
                echo json_encode(['error' => 'Subscription not found']);
                exit;
            }

            $ingestRepo = new \Seismo\Repository\EmailIngestRepository($pdo);
            $emails = $ingestRepo->fetchRowsForSubscription(
                $sub,
                \Seismo\Service\EmailGeminiConfigGenerator::GEMINI_SAMPLE_COUNT
            );

            if ($emails === []) {
                http_response_code(400);
                echo json_encode(['error' => 'No sample emails found in Seismo for this sender to analyze. Please fetch some emails first.']);
                exit;
            }

            $samples = $this->buildSplitAnalysisSamples($emails);
            $configRepo = new SystemConfigRepository($pdo);
            $generator = new \Seismo\Service\EmailGeminiConfigGenerator($configRepo);

            $isRefine = !empty($_POST['refine']);
            $currentConfig = [];
            if ($isRefine) {
                $configRaw = (string)($_POST['cleanup_config'] ?? '');
                $currentConfig = json_decode($configRaw, true) ?: [];
            }
            $initialKeywords = $currentConfig['webview_keywords'] ?? [];
            $samples = $this->buildSplitAnalysisSamples($emails, $initialKeywords);

            $configRepo = new SystemConfigRepository($pdo);
            $generator = new \Seismo\Service\EmailGeminiConfigGenerator($configRepo);

            if ($isRefine) {
                $feedbackRaw = (string)($_POST['feedback'] ?? '');
                $feedback = json_decode($feedbackRaw, true);
                if (!is_array($feedback) || !is_array($currentConfig)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid refine payload — feedback and cleanup_config must be JSON.']);
                    exit;
                }

                $res = $generator->refineCleanupConfig($samples, $currentConfig, $feedback);
            } else {
                $res = $generator->generateConfig($samples);
            }

            $cleanupConfig = [
                'strip_regexes' => $res['strip_regexes'],
                'webview_keywords' => $res['webview_keywords'],
                'title_extractor' => $res['title_extractor'],
            ];

            // Re-resolve the samples webview URLs using the newly generated/refined keywords
            $finalSamples = $this->buildSplitAnalysisSamples($emails, $cleanupConfig['webview_keywords']);

            echo json_encode([
                'success' => true,
                'refined' => $isRefine,
                'config' => $cleanupConfig,
                'analysis' => $res['analysis'],
                'verification' => $res['verification'],
                'digest_split_config' => $res['digest_split_config'],
                'samples' => $finalSamples,
            ]);
            exit;
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        } catch (\Throwable $e) {
            error_log('Seismo analyzeBoilerplate failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }

    public function analyzeSplitting(): void
    {
        set_time_limit(300);
        header('Content-Type: application/json');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit;
        }
        if (!CsrfToken::verifyRequest(rotateOnSuccess: false)) {
            http_response_code(403);
            echo json_encode(['error' => 'Session expired — please try again.']);
            exit;
        }
        if (isSatellite()) {
            http_response_code(403);
            echo json_encode(['error' => 'Satellite mode — AI configuration runs on the mothership only.']);
            exit;
        }

        $subscriptionId = (int)($_POST['id'] ?? 0);
        if ($subscriptionId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid subscription ID']);
            exit;
        }

        try {
            $pdo = getDbConnection();
            $subRepo = new EmailSubscriptionRepository($pdo);
            $sub = $subRepo->findById($subscriptionId);
            if ($sub === null || EmailSubscriptionRepository::rowModuleScope($sub) !== $this->module->scope) {
                http_response_code(404);
                echo json_encode(['error' => 'Subscription not found']);
                exit;
            }

            $ingestRepo = new \Seismo\Repository\EmailIngestRepository($pdo);
            $emails = $ingestRepo->fetchRowsForSubscription(
                $sub,
                \Seismo\Service\EmailGeminiConfigGenerator::GEMINI_SPLIT_SAMPLE_COUNT
            );

            if ($emails === []) {
                http_response_code(400);
                echo json_encode(['error' => 'No sample emails found in Seismo for this sender to analyze. Please fetch some emails first.']);
                exit;
            }

            $samples = $this->buildSplitAnalysisSamples($emails);
            $configRepo = new SystemConfigRepository($pdo);
            $generator = new \Seismo\Service\EmailGeminiConfigGenerator($configRepo);

            $isRefine = !empty($_POST['refine']);
            if ($isRefine) {
                $feedbackRaw = (string)($_POST['feedback'] ?? '');
                $configRaw = (string)($_POST['digest_split_config'] ?? '');
                $feedback = json_decode($feedbackRaw, true);
                $currentConfig = json_decode($configRaw, true);
                if (!is_array($feedback) || !is_array($currentConfig)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid refine payload — feedback and digest_split_config must be JSON.']);
                    exit;
                }

                $normalized = \Seismo\Core\Mail\DigestSplitConfigNormalizer::normalize($currentConfig, false);
                if ($normalized === null) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Current digest_split_config is invalid. Run initial analysis first.']);
                    exit;
                }

                $result = $generator->refineSplitConfig($samples, $normalized, $feedback);
            } else {
                $keepText = isset($_POST['keep_text']) ? (string)$_POST['keep_text'] : null;
                $result = $generator->generateSplitConfig($samples, $keepText);
            }

            echo json_encode($this->buildSplitAnalysisResponse($result, $emails, $samples, $isRefine));
            exit;
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        } catch (\Throwable $e) {
            error_log('Seismo analyzeSplitting failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }

    /**
     * @param list<array<string, mixed>> $emails
     * @param list<string> $customWebviewKeywords
     * @return list<array{subject: string, body: string, text_body: string, html_body: string, webview_url: ?string}>
     */
    private function buildSplitAnalysisSamples(array $emails, array $customWebviewKeywords = []): array
    {
        require_once SEISMO_ROOT . '/views/helpers.php';
        $samples = [];
        foreach ($emails as $email) {
            $samples[] = [
                'subject' => (string)($email['subject'] ?? ''),
                'body' => (string)($email['text_body'] ?? $email['body_text'] ?? ''),
                'text_body' => (string)($email['text_body'] ?? $email['body_text'] ?? ''),
                'html_body' => (string)($email['html_body'] ?? $email['body_html'] ?? ''),
                'webview_url' => seismo_email_web_view_url($email, $customWebviewKeywords),
            ];
        }

        return $samples;
    }

    /**
     * @param array{
     *     digest_split_config: ?string,
     *     analysis: ?array<string, mixed>,
     *     verification: array<string, mixed>
     * } $result
     * @param list<array<string, mixed>> $emails
     * @param list<array{subject: string, body: string, text_body: string, html_body: string}> $samples
     * @return array<string, mixed>
     */
    private function buildSplitAnalysisResponse(array $result, array $emails, array $samples, bool $refined): array
    {
        $digestSplitConfig = $result['digest_split_config'];
        $previewStories = [];

        if ($digestSplitConfig !== null) {
            $proposedConfig = json_decode($digestSplitConfig, true);
            if (is_array($proposedConfig)) {
                $splitter = new \Seismo\Core\Mail\EmailDigestSplitterService();
                $firstEmail = $emails[0];
                $previewStories = $splitter->split(
                    (string)($firstEmail['html_body'] ?? $firstEmail['body_html'] ?? ''),
                    (string)($firstEmail['text_body'] ?? $firstEmail['body_text'] ?? ''),
                    $proposedConfig
                );
            }
        }

        return [
            'success' => true,
            'refined' => $refined,
            'digest_split_config' => $digestSplitConfig,
            'analysis' => $result['analysis'],
            'verification' => $result['verification'],
            'preview_stories' => $previewStories,
            'samples' => $samples,
            'debug_log' => $result['debug_log'] ?? null,
        ];
    }

    public function deleteSubscription(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirect(['view' => 'sources']);

            return;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirect(['view' => 'sources']);

            return;
        }
        if (isSatellite()) {
            $_SESSION['error'] = 'Satellite mode — email subscriptions are managed on the mothership only.';
            $this->redirect(['view' => 'sources']);

            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['error'] = 'Invalid subscription.';

            $this->redirect(['view' => 'sources']);

            return;
        }

        try {
            $repo = new EmailSubscriptionRepository(getDbConnection());
            $existing = $repo->findById($id);
            if ($existing === null || EmailSubscriptionRepository::rowModuleScope($existing) !== $this->module->scope) {
                throw new \InvalidArgumentException('Subscription not found in this module.');
            }
            $repo->softDelete($id);
            $_SESSION['success'] = 'Subscription removed.';
        } catch (\Throwable $e) {
            error_log('Seismo ' . $this->module->deleteAction . ': ' . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();
        }

        $this->redirect(['view' => 'sources']);
    }

    public function disableSubscription(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirect(['view' => 'sources']);

            return;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirect(['view' => 'sources']);

            return;
        }
        if (isSatellite()) {
            $_SESSION['error'] = 'Satellite mode — email subscriptions are managed on the mothership only.';
            $this->redirect(['view' => 'sources']);

            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['error'] = 'Invalid subscription.';

            $this->redirect(['view' => 'sources']);

            return;
        }

        try {
            $repo = new EmailSubscriptionRepository(getDbConnection());
            $existing = $repo->findById($id);
            if ($existing === null || EmailSubscriptionRepository::rowModuleScope($existing) !== $this->module->scope) {
                throw new \InvalidArgumentException('Subscription not found in this module.');
            }
            $repo->setDisabled($id, true);
            $_SESSION['success'] = 'Subscription disabled.';
        } catch (\Throwable $e) {
            error_log('Seismo ' . $this->module->disableAction . ': ' . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();
        }

        $this->redirect(['view' => 'sources']);
    }

    public function reprocessSubscription(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirect(['view' => 'sources']);

            return;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirect(['view' => 'sources']);

            return;
        }
        if (isSatellite()) {
            $_SESSION['error'] = 'Satellite mode — email subscriptions are managed on the mothership only.';
            $this->redirect(['view' => 'sources']);

            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['error'] = 'Invalid subscription.';

            $this->redirect(['view' => 'sources']);

            return;
        }

        try {
            $repo = new EmailSubscriptionRepository(getDbConnection());
            $existing = $repo->findById($id);
            if ($existing === null || EmailSubscriptionRepository::rowModuleScope($existing) !== $this->module->scope) {
                throw new \InvalidArgumentException('Subscription not found in this module.');
            }
            $n = (new EmailSubscriptionReprocessService(getDbConnection()))->reprocessSubscription($id);
            $_SESSION['success'] = $n > 0
                ? 'Reprocessed ' . $n . ' stored message(s) with current body rules.'
                : 'No stored messages matched this subscription.';
        } catch (\Throwable $e) {
            error_log('Seismo ' . $this->module->reprocessAction . ': ' . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();
        }

        $this->redirect(['view' => 'items', 'subscription' => (string)$id]);
    }

    public function moveToOtherModule(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirect(['view' => 'sources']);

            return;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirect(['view' => 'sources']);

            return;
        }
        if (isSatellite()) {
            $_SESSION['error'] = 'Satellite mode — email subscriptions are managed on the mothership only.';
            $this->redirect(['view' => 'sources']);

            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['error'] = 'Invalid subscription.';

            $this->redirect(['view' => 'sources']);

            return;
        }

        $targetScope = $this->module->scope === MailModule::SCOPE_MAIL
            ? MailModule::SCOPE_NEWSLETTER
            : MailModule::SCOPE_MAIL;

        try {
            $repo = new EmailSubscriptionRepository(getDbConnection());
            $existing = $repo->findById($id);
            if ($existing === null || EmailSubscriptionRepository::isPendingRow($existing)) {
                throw new \InvalidArgumentException('Subscription not found or still pending review.');
            }
            if (EmailSubscriptionRepository::rowModuleScope($existing) !== $this->module->scope) {
                throw new \InvalidArgumentException('Subscription not found in this module.');
            }
            $repo->setModuleScope($id, $targetScope);
            $label = $targetScope === MailModule::SCOPE_NEWSLETTER ? 'Newsletter' : 'Mail';
            $_SESSION['success'] = 'Moved to ' . $label . '.';
        } catch (\Throwable $e) {
            error_log('Seismo move subscription: ' . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();
        }

        $this->redirect(['view' => 'sources']);
    }

    /**
     * @param array<string, scalar|null> $query
     */
    private function redirect(array $query): void
    {
        $q = array_merge(['action' => $this->module->action], $query);
        header('Location: ?' . http_build_query($q), true, 303);
        exit;
    }

    private function buildReturnQuery(): string
    {
        $p = $_GET;
        if (!is_array($p)) {
            $p = [];
        }
        $p['action'] = $this->module->action;

        return http_build_query($p);
    }

    /**
     * Merge AI Split preview noise checkboxes into digest_split_config on save.
     */
    private function mergeDigestSplitConfigNoiseFeedback(string $digestConfigJson, string $feedbackJson): string
    {
        $digestConfigJson = trim($digestConfigJson);
        $feedbackJson = trim($feedbackJson);
        if ($digestConfigJson === '' || $feedbackJson === '') {
            return $digestConfigJson;
        }

        $config = json_decode($digestConfigJson, true);
        $feedback = json_decode($feedbackJson, true);
        if (!is_array($config) || !is_array($feedback)) {
            return $digestConfigJson;
        }

        $normalized = \Seismo\Core\Mail\DigestSplitConfigNormalizer::normalize($config, rejectFragileSelectors: false);
        if ($normalized === null) {
            return $digestConfigJson;
        }

        $merged = \Seismo\Core\Mail\DigestSplitConfigNormalizer::mergeNoiseFeedback($normalized, $feedback);

        return json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $digestConfigJson;
    }

    public function reprocessAll(): void
    {
        $finish = function (): void {
            RefreshAjax::respondOrRedirect(function (): void {
                $this->redirect(['view' => 'sources']);
            });
        };

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Location: ?action=' . $this->module->action, true, 303);
            exit;
        }
        if (!CsrfToken::verifyRequest(rotateOnSuccess: false)) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $finish();

            return;
        }
        if (isSatellite()) {
            $_SESSION['error'] = 'Satellite mode: reprocess runs on the mothership.';
            $finish();

            return;
        }

        set_time_limit(300);
        try {
            $pdo = getDbConnection();
            $service = new EmailSubscriptionReprocessService($pdo);
            $total = $service->reprocessAllSubscriptions($this->module->scope);
            $_SESSION['success'] = 'Reprocessed ' . $total . ' stored message(s) across all ' . $this->module->pageTitle . ' subscriptions.';
        } catch (\Throwable $e) {
            error_log('Seismo ' . $this->module->action . ' reprocessAll failed: ' . $e->getMessage());
            $_SESSION['error'] = 'Reprocess failed: ' . $e->getMessage();
        }

        $finish();
    }

    private function saveRelationalSplitRules(\PDO $pdo, string $matchValue, string $displayName, ?array $rules): void
    {
        $matchValue = trim($matchValue);
        if ($matchValue === '') {
            return;
        }

        // 1. Insert/update newsletter_sender
        $senderStmt = $pdo->prepare('
            INSERT INTO newsletter_sender (email_address, sender_name)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE sender_name = VALUES(sender_name)
        ');
        $senderStmt->execute([$matchValue, trim($displayName)]);

        // Resolve sender ID
        $senderIdStmt = $pdo->prepare('SELECT id FROM newsletter_sender WHERE email_address = ?');
        $senderIdStmt->execute([$matchValue]);
        $senderId = (int)$senderIdStmt->fetchColumn();
        if ($senderId === 0) {
            return;
        }

        // 2. Insert/update newsletter_template
        $templateName = 'Default Template';
        $tplCheckStmt = $pdo->prepare('
            SELECT id FROM newsletter_template 
            WHERE sender_id = ? AND template_name = ?
        ');
        $tplCheckStmt->execute([$senderId, $templateName]);
        $templateId = $tplCheckStmt->fetchColumn();

        if (!$templateId) {
            $tplStmt = $pdo->prepare('
                INSERT INTO newsletter_template (sender_id, template_name, active_from) 
                VALUES (?, ?, CURRENT_TIMESTAMP)
            ');
            $tplStmt->execute([$senderId, $templateName]);
            $templateId = (int)$pdo->lastInsertId();
        } else {
            $templateId = (int)$templateId;
        }

        if ($templateId === 0) {
            return;
        }

        if ($rules === null) {
            // Delete the rule if it is cleared
            $delRuleStmt = $pdo->prepare('DELETE FROM template_rule WHERE template_id = ?');
            $delRuleStmt->execute([$templateId]);
        } else {
            // Write rule settings
            $splitMethod = trim((string)($rules['split_method'] ?? 'html_selector'));
            $storySelector = trim((string)($rules['story_selector'] ?? ''));
            $titleSelector = !empty($rules['title_selector']) ? trim((string)$rules['title_selector']) : null;
            $linkSelector = !empty($rules['link_selector']) ? trim((string)$rules['link_selector']) : null;
            $bodySelector = !empty($rules['body_selector']) ? trim((string)$rules['body_selector']) : null;

            $excludeSelectors = !empty($rules['exclude_selectors']) ? json_encode($rules['exclude_selectors'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
            $excludeTitles = !empty($rules['exclude_titles']) ? json_encode($rules['exclude_titles'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
            $glueRules = !empty($rules['glue_rules']) ? json_encode($rules['glue_rules'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

            $ruleStmt = $pdo->prepare('
                INSERT INTO template_rule (
                    template_id, split_method, story_selector, title_selector, link_selector, body_selector, 
                    exclude_selectors, exclude_titles, glue_rules
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    split_method = VALUES(split_method),
                    story_selector = VALUES(story_selector),
                    title_selector = VALUES(title_selector),
                    link_selector = VALUES(link_selector),
                    body_selector = VALUES(body_selector),
                    exclude_selectors = VALUES(exclude_selectors),
                    exclude_titles = VALUES(exclude_titles),
                    glue_rules = VALUES(glue_rules)
            ');
            $ruleStmt->execute([
                $templateId, $splitMethod, $storySelector, $titleSelector, $linkSelector, $bodySelector,
                $excludeSelectors, $excludeTitles, $glueRules
            ]);
        }
    }
}
