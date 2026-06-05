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
                        (string)$subForFilter['match_type'],
                        (string)$subForFilter['match_value'],
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
                $pendingSenders = $subRepo->listPending(EmailSubscriptionRepository::MAX_LIMIT, 0);
            }
            if (!$satellite) {
                $categorySuggestions = $subRepo->listUsedCategories();
            }
            if ($view === 'sources') {
                foreach ($subscriptions as $row) {
                    $sid = (int)$row['id'];
                    $subscriptionLatest[$sid] = $entryRepo->peekLatestEmailForSubscription(
                        (string)$row['match_type'],
                        (string)$row['match_value']
                    );
                }
                foreach ($pendingSenders as $row) {
                    $sid = (int)$row['id'];
                    $pendingLatest[$sid] = $entryRepo->peekLatestEmailForSubscription(
                        (string)$row['match_type'],
                        (string)$row['match_value']
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
                    $editRowEmails = $ingestRepo->fetchRowsForSubscriptionMatch(
                        (string)$editRow['match_type'],
                        (string)$editRow['match_value'],
                        5
                    );
                    foreach ($editRowEmails as $email) {
                        $editRowSamples[] = [
                            'subject' => (string)($email['subject'] ?? ''),
                            'body' => (string)($email['text_body'] ?? $email['body_text'] ?? ''),
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
                'unsubscribe_url'        => (string)($_POST['unsubscribe_url'] ?? ''),
                'unsubscribe_mailto'     => (string)($_POST['unsubscribe_mailto'] ?? ''),
                'unsubscribe_one_click'  => ((string)($_POST['unsubscribe_one_click'] ?? '0')) === '1',
                'module_scope'           => $this->module->scope,
            ];
            if ($id > 0) {
                $existing = $repo->findById($id);
                if ($existing === null || EmailSubscriptionRepository::rowModuleScope($existing) !== $this->module->scope) {
                    throw new \InvalidArgumentException('Subscription not found in this module.');
                }
                $wasPending = EmailSubscriptionRepository::isPendingRow($existing);
                $repo->update($id, $payload);
                $_SESSION['success'] = $wasPending
                    ? 'Sender reviewed — subscription is now active.'
                    : 'Subscription updated.';
            } else {
                $newId = $repo->insert($payload);
                $_SESSION['success'] = 'Subscription added (#' . $newId . ').';
            }
        } catch (\Throwable $e) {
            error_log('Seismo ' . $this->module->saveAction . ': ' . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();
        }

        $this->redirect(['view' => 'sources']);
    }

    public function analyzeBoilerplate(): void
    {
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
            $emails = $ingestRepo->fetchRowsForSubscriptionMatch(
                (string)$sub['match_type'],
                (string)$sub['match_value'],
                5
            );

            if ($emails === []) {
                http_response_code(400);
                echo json_encode(['error' => 'No sample emails found in Seismo for this sender to analyze. Please fetch some emails first.']);
                exit;
            }

            $samples = [];
            foreach ($emails as $email) {
                $samples[] = [
                    'subject' => (string)($email['subject'] ?? ''),
                    'body' => (string)($email['text_body'] ?? $email['body_text'] ?? ''),
                ];
            }

            $configRepo = new SystemConfigRepository($pdo);
            $generator = new \Seismo\Service\EmailGeminiConfigGenerator($configRepo);
            $config = $generator->generateConfig($samples);

            echo json_encode(['success' => true, 'config' => $config, 'samples' => $samples]);
            exit;
        } catch (\Throwable $e) {
            error_log('Seismo analyzeBoilerplate failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
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
}
