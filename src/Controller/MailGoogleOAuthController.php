<?php

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Core\Mail\GmailApiInboxClient;
use Seismo\Core\Mail\GmailOAuthService;
use Seismo\Core\Mail\MailConfigKeys;
use Seismo\Http\CsrfToken;
use Seismo\Repository\EmailIngestRepository;
use Seismo\Repository\SystemConfigRepository;
/**
 * Gmail OAuth connect / disconnect / catch-up (Slice 11).
 */
final class MailGoogleOAuthController
{
    public function startConnect(): void
    {
        $this->guardMothershipPost();
        $config = new SystemConfigRepository(getDbConnection());
        $oauth  = new GmailOAuthService($config);
        if (!$oauth->isConfigured()) {
            $_SESSION['error'] = 'Enter Google OAuth Client ID and Client Secret in Settings → Mail, save, then connect.';
            $this->redirectMail();
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $state = bin2hex(random_bytes(16));
        $_SESSION[GmailOAuthService::SESSION_STATE_KEY] = $state;

        header('Location: ' . $oauth->createAuthUrl($state), true, 302);
        exit;
    }

    public function callback(): void
    {
        if (isSatellite()) {
            $_SESSION['error'] = 'Satellite mode — connect Gmail on the mothership only.';
            header('Location: ' . getBasePath() . '/index.php?action=settings&tab=general', true, 303);
            exit;
        }

        $error = trim((string)($_GET['error'] ?? ''));
        if ($error !== '') {
            $_SESSION['error'] = 'Google sign-in was cancelled or denied: ' . $error;
            $this->redirectMail();
        }

        $code  = trim((string)($_GET['code'] ?? ''));
        $state = trim((string)($_GET['state'] ?? ''));
        if ($code === '') {
            $_SESSION['error'] = 'Google OAuth callback missing authorization code.';
            $this->redirectMail();
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $expected = (string)($_SESSION[GmailOAuthService::SESSION_STATE_KEY] ?? '');
        unset($_SESSION[GmailOAuthService::SESSION_STATE_KEY]);
        if ($expected === '' || !hash_equals($expected, $state)) {
            $_SESSION['error'] = 'Invalid OAuth state — try Connect Google account again.';
            $this->redirectMail();
        }

        try {
            $config = new SystemConfigRepository(getDbConnection());
            (new GmailOAuthService($config))->handleAuthorizationCode($code);
            $_SESSION['success'] = 'Gmail connected. Run Refresh all or Catch up inbox to import messages.';
        } catch (\Throwable $e) {
            error_log('Seismo mail_google_oauth_callback: ' . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();
        }

        $this->redirectMail();
    }

    public function disconnect(): void
    {
        $this->guardMothershipPost();
        try {
            $config = new SystemConfigRepository(getDbConnection());
            (new GmailOAuthService($config))->disconnect();
            $_SESSION['success'] = 'Gmail disconnected. Existing email cards are unchanged.';
        } catch (\Throwable $e) {
            error_log('Seismo mail_google_disconnect: ' . $e->getMessage());
            $_SESSION['error'] = 'Could not disconnect Gmail.';
        }
        $this->redirectMail();
    }

    public function catchUp(): void
    {
        $this->guardMothershipPost();
        try {
            $pdo    = getDbConnection();
            $config = new SystemConfigRepository($pdo);
            $oauth  = new GmailOAuthService($config);
            if (!$oauth->isConnected()) {
                $_SESSION['error'] = 'Connect Gmail first.';
                $this->redirectMail();
            }
            $rows = (new GmailApiInboxClient($oauth, $config))->fetch(true);
            $n    = (new EmailIngestRepository($pdo))->upsertGmailBatch($rows);
            $_SESSION['success'] = 'Catch up complete — ' . $n . ' message(s) processed.';
        } catch (\Throwable $e) {
            error_log('Seismo mail_gmail_catchup: ' . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();
        }
        $this->redirectMail();
    }

    private function guardMothershipPost(): void
    {
        if (isSatellite()) {
            $_SESSION['error'] = 'Satellite mode — mail is configured on the mothership only.';
            header('Location: ' . getBasePath() . '/index.php?action=settings&tab=general', true, 303);
            exit;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirectMail();
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirectMail();
        }
    }

    private function redirectMail(): void
    {
        header('Location: ' . getBasePath() . '/index.php?action=settings&tab=mail', true, 303);
        exit;
    }
}
