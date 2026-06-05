<?php
/**
 * POST-only hide (dismiss) for feed_item and email entries.
 */

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Http\CsrfToken;
use Seismo\Repository\EntryHiddenRepository;

final class HideEntryController
{
    /**
     * @var list<string>
     */
    private const RETURN_QUERY_ALLOW = [
        'q', 'view', 'show_media', 'sort', 'limit', 'offset',
        'fc', 'fk', 'lx', 'etag',
        'efc', 'elx', 'eet', 'ecal', 'ejus',
        'none', 'filter_form',
    ];

    /**
     * @var list<string>
     */
    private const RETURN_ACTION_ALLOW = [
        'index',
        'filter',
        'magnitu',
        'feeds',
        'scraper',
        'mail',
        'newsletter',
    ];

    public function hide(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirectToIndex([]);
            return;
        }

        $returnRaw = trim((string)($_POST['return_query'] ?? ''));

        if (!CsrfToken::verifyRequest(rotateOnSuccess: false)) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirectFromReturnQuery($returnRaw);
            return;
        }

        $entryType = trim((string)($_POST['entry_type'] ?? ''));
        $entryId   = (int)($_POST['entry_id'] ?? 0);

        if (!in_array($entryType, EntryHiddenRepository::ALLOWED_ENTRY_TYPES, true) || $entryId <= 0) {
            $_SESSION['error'] = 'Invalid hide request.';
            $this->redirectFromReturnQuery($returnRaw);
            return;
        }

        try {
            $pdo  = getDbConnection();
            $repo = new EntryHiddenRepository($pdo);
            if ($repo->hide($entryType, $entryId)) {
                $_SESSION['success'] = 'Entry hidden.';
            } else {
                $_SESSION['success'] = 'Entry already hidden.';
            }
        } catch (\Throwable $e) {
            error_log('Seismo hide_entry: ' . $e->getMessage());
            $_SESSION['error'] = 'Could not hide entry. Try again.';
        }

        $this->redirectFromReturnQuery($returnRaw);
    }

    private function redirectFromReturnQuery(string $returnRaw): void
    {
        $params = [];
        if ($returnRaw !== '') {
            parse_str(ltrim($returnRaw, '?'), $params);
        }
        $this->redirectToIndex($this->sanitizeReturnParams($params));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function sanitizeReturnParams(array $params): array
    {
        $out = [];
        foreach (self::RETURN_QUERY_ALLOW as $key) {
            if (!array_key_exists($key, $params)) {
                continue;
            }
            $v = $params[$key];
            if (is_array($v)) {
                continue;
            }
            if (is_scalar($v)) {
                $out[$key] = $v;
            }
        }
        if (isset($params['filters']) && is_array($params['filters'])) {
            $clean = self::sanitizeReturnFilters($params['filters']);
            if ($clean !== []) {
                $out['filters'] = $clean;
            }
        }
        if (isset($params['action']) && is_scalar($params['action']) && !is_array($params['action'])) {
            $a = (string)$params['action'];
            if (in_array($a, self::RETURN_ACTION_ALLOW, true)) {
                $out['action'] = $a;
            }
        }

        return $out;
    }

    /**
     * @param array<mixed, mixed> $raw
     * @return array<string, mixed>
     */
    private static function sanitizeReturnFilters(array $raw): array
    {
        $out = [];
        foreach (['feed', 'lex', 'email'] as $k) {
            if (!isset($raw[$k]) || !is_array($raw[$k])) {
                continue;
            }
            $vals = [];
            foreach ($raw[$k] as $v) {
                if (!is_scalar($v)) {
                    continue;
                }
                $s = trim((string)$v);
                if ($s !== '' && strlen($s) <= 128) {
                    $vals[] = $s;
                }
            }
            $vals = array_values(array_unique($vals));
            if ($vals !== []) {
                $out[$k] = $vals;
            }
        }
        foreach (['calendar', 'jus'] as $k) {
            if (!isset($raw[$k]) || !is_scalar($raw[$k])) {
                continue;
            }
            if (trim((string)$raw[$k]) === '1') {
                $out[$k] = '1';
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function redirectToIndex(array $params): void
    {
        $action = $params['action'] ?? 'index';
        $params['action'] = is_string($action) && in_array($action, self::RETURN_ACTION_ALLOW, true)
            ? $action
            : 'index';
        unset($params['entry_type'], $params['entry_id']);
        $qs = http_build_query($params);
        header('Location: ?' . $qs, true, 303);
        exit;
    }
}
