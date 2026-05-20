<?php
/**
 * Mothership-only satellite registry (Settings → Satellites).
 *
 * Persists rows in {@see SystemConfigRepository} under `satellites_registry`.
 * Provisioning (MariaDB + path stub) is done via `bin/seismo-satellite-provision.sh`.
 */

declare(strict_types=1);

namespace Seismo\Controller;

use PDO;
use Seismo\Http\CsrfToken;
use Seismo\Repository\SystemConfigRepository;

final class SatelliteController
{
    private const KEY_REGISTRY = 'satellites_registry';

    public function add(): void
    {
        if (!$this->guardMothershipPost()) {
            return;
        }

        $slug = seismoNormaliseSatelliteSlug((string)($_POST['slug'] ?? ''));
        $displayName = trim((string)($_POST['display_name'] ?? ''));
        $profile = seismoNormaliseSatelliteSlug((string)($_POST['magnitu_profile'] ?? $slug));
        $accent = trim((string)($_POST['brand_accent'] ?? ''));

        if ($slug === '') {
            $_SESSION['error'] = 'Slug is required (letters, numbers, dashes).';
            $this->redirect();

            return;
        }
        if (in_array($slug, seismoReservedSatelliteSlugs(), true)) {
            $_SESSION['error'] = "Slug '{$slug}' is reserved — choose another.";
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
            'mount_path' => '/' . $slug,
            'db_name' => 'seismo_' . $slug,
            'magnitu_profile' => $profile !== '' ? $profile : $slug,
            'brand_accent' => $accent,
            'api_key' => $this->generateKey(),
            'status' => 'pending',
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        $this->saveRegistry($config, $registry);
        if (isset($_POST['remote_refresh']) && (string)$_POST['remote_refresh'] === '1') {
            seismoEnsureRemoteRefreshKey();
        }

        $_SESSION['success'] = "Satellite '{$slug}' registered. Run bin/seismo-satellite-provision.sh {$slug} on the VPS to create its database and path.";
        $this->redirect($slug);
    }

    public function remove(): void
    {
        if (!$this->guardMothershipPost()) {
            return;
        }

        $slug = seismoNormaliseSatelliteSlug((string)($_POST['slug'] ?? ''));
        $pdo = getDbConnection();
        $config = new SystemConfigRepository($pdo);
        $registry = $this->loadRegistry($config);
        $filtered = array_values(array_filter(
            $registry,
            static fn($s) => ($s['slug'] ?? '') !== $slug
        ));
        $this->saveRegistry($config, $filtered);
        $_SESSION['success'] = "Satellite '{$slug}' removed from registry. Its scores database and /{$slug}/ folder are untouched — delete those manually if needed.";
        $this->redirect();
    }

    public function rotateKey(): void
    {
        if (!$this->guardMothershipPost()) {
            return;
        }

        $slug = seismoNormaliseSatelliteSlug((string)($_POST['slug'] ?? ''));
        $pdo = getDbConnection();
        $config = new SystemConfigRepository($pdo);
        $registry = $this->loadRegistry($config);
        $found = false;
        $scoresDb = 'seismo_' . $slug;
        foreach ($registry as &$sat) {
            if (($sat['slug'] ?? '') === $slug) {
                $sat['api_key'] = $this->generateKey();
                $sat['rotated_at'] = gmdate('Y-m-d\TH:i:s\Z');
                $scoresDb = (string)($sat['db_name'] ?? $scoresDb);
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
        $_SESSION['success'] = "API key rotated for '{$slug}'. Update Magnitu and set api_key in `{$scoresDb}.system_config` if already provisioned.";
        $this->redirect($slug);
    }

    public function rotateRefreshKey(): void
    {
        if (!$this->guardMothershipPost()) {
            return;
        }

        $config = new SystemConfigRepository(getDbConnection());
        $config->set(seismoRemoteRefreshConfigKey(), $this->generateKey());
        seismoRemoteRefreshKey(true);
        $_SESSION['success'] = 'Remote refresh key updated. All path satellites use it immediately — no config file edit needed.';
        $this->redirect();
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
}
