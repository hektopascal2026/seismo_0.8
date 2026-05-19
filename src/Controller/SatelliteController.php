<?php
/**
 * Mothership-only satellite registry (Settings → Satellites).
 *
 * Persists rows in {@see SystemConfigRepository} under `satellites_registry`
 * and exports `satellite-<slug>.json` for the external `seismo-generator` CLI.
 */

declare(strict_types=1);

namespace Seismo\Controller;

use PDO;
use Seismo\Http\CsrfToken;
use Seismo\Repository\SystemConfigRepository;

final class SatelliteController
{
    private const KEY_REGISTRY            = 'satellites_registry';
    private const KEY_SUGGESTED_REFRESH   = 'satellites_suggested_refresh_key';
    private const EXPORT_SCHEMA_VERSION   = 1;

    public function add(): void
    {
        if (!$this->guardMothershipPost()) {
            return;
        }

        $slug = $this->normaliseSlug((string)($_POST['slug'] ?? ''));
        $displayName = trim((string)($_POST['display_name'] ?? ''));
        $profile = $this->normaliseSlug((string)($_POST['magnitu_profile'] ?? $slug));
        $accent = trim((string)($_POST['brand_accent'] ?? ''));

        if ($slug === '') {
            $_SESSION['error'] = 'Slug is required (letters, numbers, dashes).';
            $this->redirect();

            return;
        }
        if ($displayName === '') {
            $displayName = 'Seismo ' . ucfirst($slug);
        } else {
            $displayName = $this->canonicalSatelliteDisplayName($displayName);
        }
        if ($accent !== '' && !preg_match('/^#[0-9a-fA-F]{3,8}$/', $accent)) {
            $_SESSION['error'] = 'Accent colour must be a hex value like #4a90e2.';
            $this->redirect();

            return;
        }

        $pdo = getDbConnection();
        $config = new SystemConfigRepository($pdo);
        $registry = $this->loadRegistry($config);
        foreach ($registry as $sat) {
            if (($sat['slug'] ?? '') === $slug) {
                $_SESSION['error'] = "Satellite '{$slug}' already exists. Use rotate key or remove first.";
                $this->redirect();

                return;
            }
        }

        $registry[] = [
            'slug' => $slug,
            'display_name' => $displayName,
            'magnitu_profile' => $profile !== '' ? $profile : $slug,
            'brand_accent' => $accent,
            'api_key' => $this->generateKey(),
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        $this->saveRegistry($config, $registry);

        $_SESSION['success'] = "Satellite '{$slug}' added. Download its JSON to feed into seismo-generator.";
        $this->redirect($slug);
    }

    public function remove(): void
    {
        if (!$this->guardMothershipPost()) {
            return;
        }

        $slug = $this->normaliseSlug((string)($_POST['slug'] ?? ''));
        $pdo = getDbConnection();
        $config = new SystemConfigRepository($pdo);
        $registry = $this->loadRegistry($config);
        $filtered = array_values(array_filter(
            $registry,
            static fn($s) => ($s['slug'] ?? '') !== $slug
        ));
        $this->saveRegistry($config, $filtered);
        $_SESSION['success'] = "Satellite '{$slug}' removed from registry. The satellite's own database is untouched.";
        $this->redirect();
    }

    public function rotateKey(): void
    {
        if (!$this->guardMothershipPost()) {
            return;
        }

        $slug = $this->normaliseSlug((string)($_POST['slug'] ?? ''));
        $pdo = getDbConnection();
        $config = new SystemConfigRepository($pdo);
        $registry = $this->loadRegistry($config);
        $found = false;
        foreach ($registry as &$sat) {
            if (($sat['slug'] ?? '') === $slug) {
                $sat['api_key'] = $this->generateKey();
                $sat['rotated_at'] = gmdate('Y-m-d\TH:i:s\Z');
                $found = true;
                break;
            }
        }
        unset($sat);

        if (!$found) {
            $_SESSION['error'] = "Satellite '{$slug}' not found.";
            $this->redirect();

            return;
        }

        $this->saveRegistry($config, $registry);
        $_SESSION['success'] = "API key rotated for '{$slug}'. Download new JSON and re-deploy the satellite.";
        $this->redirect($slug);
    }

    public function rotateRefreshKey(): void
    {
        if (!$this->guardMothershipPost()) {
            return;
        }

        $config = new SystemConfigRepository(getDbConnection());
        $config->set(self::KEY_SUGGESTED_REFRESH, $this->generateKey());
        $_SESSION['success'] = 'Generated a new suggested SEISMO_REMOTE_REFRESH_KEY. Paste it into mothership + satellite config.local.php.';
        $this->redirect();
    }

    public function downloadJson(): void
    {
        if (isSatellite()) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Satellite registry is only available on the mothership.\n";
            exit;
        }

        $slug = $this->normaliseSlug((string)($_GET['slug'] ?? ''));
        $pdo = getDbConnection();
        $config = new SystemConfigRepository($pdo);
        $registry = $this->loadRegistry($config);
        $sat = null;
        foreach ($registry as $row) {
            if (($row['slug'] ?? '') === $slug) {
                $sat = $row;
                break;
            }
        }
        if ($sat === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Satellite '{$slug}' not found.\n";
            exit;
        }

        $refreshKey = $this->getRemoteRefreshKey();
        if ($refreshKey === '') {
            $suggested = $config->get(self::KEY_SUGGESTED_REFRESH);
            $refreshKey = $suggested !== null && $suggested !== ''
                ? $suggested
                : '<SET SEISMO_REMOTE_REFRESH_KEY IN MOTHERSHIP config.local.php>';
        }

        $payload = [
            'schema_version' => self::EXPORT_SCHEMA_VERSION,
            'slug' => $sat['slug'],
            'display_name' => $sat['display_name'],
            'mothership_url' => $this->detectMothershipUrl(),
            'mothership_db' => $this->detectMothershipDbName($pdo),
            'mothership_remote_refresh_key' => $refreshKey,
            'magnitu' => [
                'api_key' => $sat['api_key'],
                'profile_slug' => $sat['magnitu_profile'] ?? $sat['slug'],
            ],
            'brand' => [
                'accent' => $sat['brand_accent'] ?? '',
                'title' => $sat['display_name'],
            ],
            'filters' => [
                'labels' => ['investigation_lead', 'important'],
            ],
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Could not encode JSON.\n";
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="satellite-' . $slug . '.json"');
        header('Content-Length: ' . (string)strlen($json));
        echo $json;
        exit;
    }

    private function guardMothershipPost(): bool
    {
        if (isSatellite()) {
            $_SESSION['error'] = 'Satellite registry is only available on the mothership.';
            $this->redirect();

            return false;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirect();

            return false;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirect();

            return false;
        }

        return true;
    }

    private function redirect(?string $highlightSlug = null): void
    {
        $q = ['action' => 'settings', 'tab' => 'satellite'];
        if ($highlightSlug !== null && $highlightSlug !== '') {
            $q['highlight'] = $highlightSlug;
        }
        header('Location: ' . getBasePath() . '/index.php?' . http_build_query($q), true, 303);
        exit;
    }

    /** @return list<array<string, mixed>> */
    private function loadRegistry(SystemConfigRepository $config): array
    {
        $raw = $config->get(self::KEY_REGISTRY);
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /** @param list<array<string, mixed>> $registry */
    private function saveRegistry(SystemConfigRepository $config, array $registry): void
    {
        $config->set(
            self::KEY_REGISTRY,
            json_encode(array_values($registry), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private function generateKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function normaliseSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = (string)preg_replace('/[^a-z0-9-]+/', '-', $slug);
        $slug = (string)preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');

        return substr($slug, 0, 40);
    }

    /**
     * Stored display names are canonical "Seismo {suffix}". Accept suffix-only input
     * as well as a full pasted title starting with "Seismo " (any case).
     */
    private function canonicalSatelliteDisplayName(string $input): string
    {
        $input = preg_replace('/\s+/u', ' ', trim($input));
        if ($input === '') {
            return '';
        }
        if (preg_match('/^seismo(?:\s+|$)/iu', $input)) {
            return $input;
        }

        return 'Seismo ' . $input;
    }

    private function getRemoteRefreshKey(): string
    {
        return defined('SEISMO_REMOTE_REFRESH_KEY') ? (string)SEISMO_REMOTE_REFRESH_KEY : '';
    }

    /**
     * Absolute public base URL for this install (no trailing slash), for JSON export.
     * Uses {@see getBasePath()} so subfolder installs work.
     */
    private function detectMothershipUrl(): string
    {
        $scheme = 'http';
        $httpsFlag = (string)($_SERVER['HTTPS'] ?? '');
        if ($httpsFlag !== '' && strtolower($httpsFlag) !== 'off') {
            $scheme = 'https';
        } elseif (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            $scheme = 'https';
        }
        $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
        $bp = getBasePath();

        return $scheme . '://' . $host . ($bp === '' ? '' : $bp);
    }

    private function detectMothershipDbName(PDO $pdo): string
    {
        try {
            return (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        } catch (\Throwable) {
            return '';
        }
    }
}
