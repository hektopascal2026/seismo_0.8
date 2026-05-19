<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

use Google\Client as GoogleClient;
use Google\Service\Gmail;
use Seismo\Repository\SystemConfigRepository;

/**
 * Google OAuth for Gmail read-only ingest (Slice 11).
 */
final class GmailOAuthService
{
    public const OAUTH_SCOPE = Gmail::GMAIL_READONLY;

    public const SESSION_STATE_KEY = 'mail_google_oauth_state';

    public function __construct(private SystemConfigRepository $config)
    {
    }

    public function redirectUri(): string
    {
        return self::requestScheme() . '://' . self::requestHost()
            . getBasePath() . '/index.php?action=mail_google_oauth_callback';
    }

    /**
     * Scheme + host must match what Google sees and what the admin copies into Cloud Console.
     * Shared hosts often terminate TLS in front of PHP — honour X-Forwarded-Proto like SettingsController.
     */
    private static function requestScheme(): string
    {
        $httpsFlag = (string)($_SERVER['HTTPS'] ?? '');
        if ($httpsFlag !== '' && strtolower($httpsFlag) !== 'off') {
            return 'https';
        }
        if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return 'https';
        }

        return 'http';
    }

    private static function requestHost(): string
    {
        return (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
    }

    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public function isConnected(): bool
    {
        $token = trim((string)($this->config->get(MailConfigKeys::GOOGLE_REFRESH_TOKEN) ?? ''));

        return $token !== '';
    }

    public function createAuthUrl(string $state): string
    {
        $client = $this->buildClient();
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);
        $client->setState($state);

        return $client->createAuthUrl();
    }

    /**
     * Exchange authorization code; persist refresh token and connected email.
     */
    public function handleAuthorizationCode(string $code): void
    {
        $client = $this->buildClient();
        $token  = $client->fetchAccessTokenWithAuthCode($code);
        if (isset($token['error'])) {
            throw new \RuntimeException(
                'Google OAuth failed: ' . (string)($token['error_description'] ?? $token['error'])
            );
        }
        if (empty($token['refresh_token'])) {
            throw new \RuntimeException(
                'Google did not return a refresh token. Disconnect the app in your Google Account '
                . 'permissions, then connect again (consent must be re-granted).'
            );
        }

        $client->setAccessToken($token);
        $this->config->set(MailConfigKeys::GOOGLE_REFRESH_TOKEN, (string)$token['refresh_token']);
        $this->config->set(MailConfigKeys::TRANSPORT, MailConfigKeys::TRANSPORT_GMAIL_API);

        $gmail   = new Gmail($client);
        $profile = $gmail->users->getProfile('me');
        $email   = trim((string)($profile->getEmailAddress() ?? ''));
        if ($email !== '') {
            $this->config->set(MailConfigKeys::GOOGLE_EMAIL, $email);
        }
        $historyId = (string)($profile->getHistoryId() ?? '');
        if ($historyId !== '') {
            $this->config->set(MailConfigKeys::GMAIL_HISTORY_ID, $historyId);
        }
    }

    public function disconnect(): void
    {
        foreach ([
            MailConfigKeys::GOOGLE_REFRESH_TOKEN,
            MailConfigKeys::GOOGLE_EMAIL,
            MailConfigKeys::GMAIL_HISTORY_ID,
        ] as $key) {
            $this->config->delete($key);
        }
        if ($this->config->get(MailConfigKeys::TRANSPORT) === MailConfigKeys::TRANSPORT_GMAIL_API) {
            $this->config->delete(MailConfigKeys::TRANSPORT);
        }
    }

    public function createAuthorizedGmailService(): Gmail
    {
        if (!$this->isConnected()) {
            throw new \RuntimeException('Gmail is not connected — use Settings → Mail → Connect Google account.');
        }

        $client = $this->buildClient();
        $refresh = trim((string)($this->config->get(MailConfigKeys::GOOGLE_REFRESH_TOKEN) ?? ''));
        $client->refreshToken($refresh);
        $access = $client->getAccessToken();
        if (!is_array($access) || isset($access['error'])) {
            $msg = is_array($access) ? (string)($access['error_description'] ?? $access['error'] ?? 'token refresh failed') : 'token refresh failed';
            throw new \RuntimeException('Gmail token refresh failed: ' . $msg . ' — reconnect Google in Settings → Mail.');
        }

        return new Gmail($client);
    }

    private function buildClient(): GoogleClient
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException(
                'Google OAuth client is not configured — set Client ID and Client Secret in Settings → Mail.'
            );
        }
        $client = new GoogleClient();
        $client->setApplicationName('Seismo');
        $client->setClientId($this->clientId());
        $client->setClientSecret($this->clientSecret());
        $client->setRedirectUri($this->redirectUri());
        $client->setScopes([self::OAUTH_SCOPE]);
        $client->setAccessType('offline');

        return $client;
    }

    private function clientId(): string
    {
        return trim((string)($this->config->get(MailConfigKeys::GOOGLE_CLIENT_ID) ?? ''));
    }

    private function clientSecret(): string
    {
        return trim((string)($this->config->get(MailConfigKeys::GOOGLE_CLIENT_SECRET) ?? ''));
    }
}
