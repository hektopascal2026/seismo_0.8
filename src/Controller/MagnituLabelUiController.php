<?php
/**
 * In-app Magnitu training labels (Magnitu-mini parity) — session UI + CSRF.
 *
 * Lists unlabeled entries from the same export shape as {@see MagnituController}
 * and persists rows to the local {@see MagnituLabelRepository} (never via
 * Bearer `magnitu_labels` from the browser).
 *
 * Three small behaviours worth knowing about:
 *
 *   1. **CSRF is verified WITHOUT rotation** on `label_save`. A labeler in the
 *      groove clicks several cards within a few hundred ms; with the default
 *      rotating verifier, the second/third POSTs were assembled with the
 *      pre-rotation token and PHP returns 403 by the time it processes them.
 *      The session cookie is still required and still unguessable; rotation
 *      bought defence-in-depth at the cost of breaking the UI.
 *   2. **The session lock is released right after CSRF verify.** Without this,
 *      PHP's file session handler serialises every concurrent label POST
 *      from the same browser tab.
 *   3. **The queue is paginated via `?offset=`** so labelers can keep going
 *      past the newest 280-per-family window after the initial page is done.
 */

declare(strict_types=1);

namespace Seismo\Controller;

use PDOException;
use Seismo\Http\CsrfToken;
use Seismo\Repository\MagnituExportRepository;
use Seismo\Repository\MagnituLabelRepository;

final class MagnituLabelUiController
{
    private const ALLOWED_LABELS = ['investigation_lead', 'important', 'background', 'noise'];

    /** Per-family fetch cap when building the labeling queue. */
    private const PER_FAMILY = 280;

    /** Max entries shipped to the browser after filtering + shuffle. */
    private const QUEUE_CAP = 320;

    public function show(): void
    {
        $csrfField  = CsrfToken::field();
        $pageError  = null;
        $filter     = $this->normaliseFilter($_GET['type'] ?? 'all');
        $offset     = $this->normaliseOffset($_GET['offset'] ?? 0);
        $nextOffset = $offset + self::PER_FAMILY;
        $queueJson  = '[]';

        try {
            $pdo       = getDbConnection();
            $export    = new MagnituExportRepository($pdo);
            $labelRepo = new MagnituLabelRepository($pdo);
            $labeled   = $labelRepo->listLabeledKeys();
            $raw       = $this->gatherEntries($export, $filter, $offset);
            $unlabeled = [];
            foreach ($raw as $e) {
                $k = $e['entry_type'] . ':' . $e['entry_id'];
                if (!isset($labeled[$k])) {
                    $unlabeled[] = $e;
                }
            }
            shuffle($unlabeled);
            if (count($unlabeled) > self::QUEUE_CAP) {
                $unlabeled = array_slice($unlabeled, 0, self::QUEUE_CAP);
            }
            $enc = json_encode($unlabeled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
            $queueJson = $enc !== false ? $enc : '[]';
        } catch (\Throwable $e) {
            error_log('Seismo label UI: ' . $e->getMessage());
            $pageError = 'Could not load entries. Check error_log for details.';
        }

        require_once SEISMO_ROOT . '/views/helpers.php';
        require SEISMO_ROOT . '/views/label.php';
    }

    /**
     * POST — saves one label (FormData / x-www-form-urlencoded + `_csrf`).
     */
    public function save(): void
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

        // We have nothing more to read from / write to $_SESSION. Releasing
        // the lock here means rapid consecutive label POSTs from the same
        // browser tab don't serialise behind one another — see class
        // docblock note #2.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $entryType = (string)($_POST['entry_type'] ?? '');
        $entryId   = (int)($_POST['entry_id'] ?? 0);
        $label     = (string)($_POST['label'] ?? '');
        $reasoning = trim((string)($_POST['reasoning'] ?? ''));
        $reasoning = $reasoning === '' ? null : $reasoning;

        if (
            !in_array($entryType, MagnituLabelRepository::LABELED_ENTRY_TYPES, true)
            || $entryId <= 0
            || !in_array($label, self::ALLOWED_LABELS, true)
        ) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid entry or label.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        try {
            $repo = new MagnituLabelRepository(getDbConnection());
            $repo->upsert($entryType, $entryId, $label, $reasoning, gmdate('Y-m-d H:i:s'));
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Database error.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        // Token is not rotated; no need to ship a fresh one to the client.
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    }

    private function normaliseFilter(mixed $raw): string
    {
        $t = is_string($raw) ? trim($raw) : '';
        if (in_array($t, ['all', 'lex_item', 'feed_item'], true)) {
            return $t;
        }

        return 'all';
    }

    private function normaliseOffset(mixed $raw): int
    {
        if (is_string($raw) || is_int($raw)) {
            $n = (int)$raw;
            if ($n < 0) {
                return 0;
            }
            // Soft cap so a malicious URL can't trigger a huge OFFSET scan.
            return min($n, 100000);
        }

        return 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gatherEntries(MagnituExportRepository $export, string $filter, int $offset): array
    {
        $entries = [];
        $lim     = self::PER_FAMILY;
        $one     = 500;

        if ($filter === 'all' || $filter === 'feed_item') {
            $cap = $filter === 'all' ? $lim : $one;
            foreach ($export->listFeedItemsForLabeling($cap, $offset) as $row) {
                $entries[] = MagnituController::shapeFeedItem($row);
            }
        }
        if ($filter === 'all' || $filter === 'lex_item') {
            $cap = $filter === 'all' ? $lim : $one;
            foreach ($export->listLexItemsForLabeling($cap, $offset) as $row) {
                $entries[] = MagnituController::shapeLexItem($row);
            }
        }
        if ($filter === 'all') {
            foreach ($export->listEmailsForLabeling($lim, $offset) as $row) {
                $entries[] = MagnituController::shapeEmail($row);
            }
            foreach ($export->listCalendarEventsForLabeling($lim, $offset) as $row) {
                $entries[] = MagnituController::shapeCalendarEvent($row);
            }
        }

        return $entries;
    }
}
