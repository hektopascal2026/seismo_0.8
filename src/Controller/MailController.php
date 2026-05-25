<?php

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Http\CsrfToken;
use Seismo\Http\RefreshAjax;
use Seismo\Repository\EmailSubscriptionRepository;
use Seismo\Repository\EntryRepository;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Service\EmailSubscriptionReprocessService;
use Seismo\Service\RefreshAllService;

final class MailController
{
    private const LIST_LIMIT = 50;

    public function show(): void
    {
        $csrfField = CsrfToken::field();
        $basePath  = getBasePath();
        $satellite = isSatellite();

        $viewParam = (string)($_GET['view'] ?? '');
        // Align with Feeds/Scraper (`view=sources`); keep `subscriptions` for old bookmarks.
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
                if ($subForFilter !== null) {
                    $subscriptionFilter = $subForFilter;
                    $allItems = $entryRepo->getEmailModuleTimelineForSubscription(
                        (string)$subForFilter['match_type'],
                        (string)$subForFilter['match_value'],
                        self::LIST_LIMIT,
                        0
                    );
                } else {
                    $allItems = $entryRepo->getEmailModuleTimeline(self::LIST_LIMIT, 0);
                }
            } else {
                $allItems = $entryRepo->getEmailModuleTimeline(self::LIST_LIMIT, 0);
            }

            $subscriptions  = $subRepo->listActive(EmailSubscriptionRepository::MAX_LIMIT, 0);
            $pendingSenders = $subRepo->listPending(EmailSubscriptionRepository::MAX_LIMIT, 0);
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
            if ($editId > 0) {
                $editRow = $subRepo->findById($editId);
                if ($editRow !== null && EmailSubscriptionRepository::isPendingRow($editRow)) {
                    $reviewingPending = true;
                }
            }
        } catch (\Throwable $e) {
            error_log('Seismo mail: ' . $e->getMessage());
            $pageError = 'Could not load mail page. Check error_log for details.';
        }

        require_once SEISMO_ROOT . '/views/helpers.php';

        $showDaySeparators = true;
        $showFavourites    = true;
        $searchQuery       = '';
        $returnQuery       = $this->buildReturnQuery();
        $currentView       = 'newest';
        $emptyTimelineHint = 'default';
        $timelineFilter    = \Seismo\Repository\TimelineFilter::fromQueryArray([]);
        $filterPillOptions = ['feed_categories' => [], 'lex_sources' => [], 'email_tags' => []];
        $dashboardError    = $pageError;

        $showModuleRefresh       = !$satellite;
        $moduleRefreshAction     = 'refresh_mail_ingest';
        $moduleRefreshLabel      = 'Refresh Mail';
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
            header('Location: ?action=mail', true, 303);
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
            header('Location: ?' . http_build_query(['action' => 'mail', 'view' => 'sources']), true, 303);
        } else {
            header('Location: ?action=mail', true, 303);
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
                'unsubscribe_url'        => (string)($_POST['unsubscribe_url'] ?? ''),
                'unsubscribe_mailto'     => (string)($_POST['unsubscribe_mailto'] ?? ''),
                'unsubscribe_one_click'  => ((string)($_POST['unsubscribe_one_click'] ?? '0')) === '1',
            ];
            if ($id > 0) {
                $wasPending = false;
                $existing   = $repo->findById($id);
                if ($existing !== null && EmailSubscriptionRepository::isPendingRow($existing)) {
                    $wasPending = true;
                }
                $repo->update($id, $payload);
                $_SESSION['success'] = $wasPending
                    ? 'Sender reviewed — subscription is now active.'
                    : 'Subscription updated.';
            } else {
                $newId = $repo->insert($payload);
                $_SESSION['success'] = 'Subscription added (#' . $newId . ').';
            }
        } catch (\Throwable $e) {
            error_log('Seismo mail_subscription_save: ' . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();
        }

        $this->redirect(['view' => 'sources']);
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
            $repo->softDelete($id);
            $_SESSION['success'] = 'Subscription removed.';
        } catch (\Throwable $e) {
            error_log('Seismo mail_subscription_delete: ' . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();
        }

        $this->redirect(['view' => 'sources']);
    }

    /**
     * Disable subscription (one-click unsubscribe style).
     */
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
            $repo->setDisabled($id, true);
            $_SESSION['success'] = 'Subscription disabled.';
        } catch (\Throwable $e) {
            error_log('Seismo mail_subscription_disable: ' . $e->getMessage());
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
            $n = (new EmailSubscriptionReprocessService(getDbConnection()))->reprocessSubscription($id);
            $_SESSION['success'] = $n > 0
                ? 'Reprocessed ' . $n . ' stored message(s) with current body rules.'
                : 'No stored messages matched this subscription.';
        } catch (\Throwable $e) {
            error_log('Seismo mail_subscription_reprocess: ' . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();
        }

        $this->redirect(['view' => 'items', 'subscription' => (string)$id]);
    }

    /**
     * @param array<string, scalar|null> $query
     */
    private function redirect(array $query): void
    {
        $q = array_merge(['action' => 'mail'], $query);
        header('Location: ?' . http_build_query($q), true, 303);
        exit;
    }

    private function buildReturnQuery(): string
    {
        $p = $_GET;
        if (!is_array($p)) {
            $p = [];
        }
        $p['action'] = 'mail';

        return http_build_query($p);
    }
}
