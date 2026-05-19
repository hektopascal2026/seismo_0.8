<?php
/**
 * Public read-only export endpoints.
 *
 *   GET ?action=export_entries   — JSON dump of entries + scores since cursor.
 *   GET ?action=export_briefing  — Markdown digest of the same window.
 *
 * Both routes are authenticated by the `export:api_key` only — the Magnitu
 * write key is rejected here (two-key model, see `BearerAuth`). A compromised
 * briefing key therefore can't corrupt scores or labels.
 *
 * Same family reads as {@see \Seismo\Controller\MagnituController} (includes
 * Leg / `calendar_event`).
 */

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Formatter\JsonExportFormatter;
use Seismo\Formatter\MarkdownBriefingFormatter;
use Seismo\Http\BearerAuth;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Repository\MagnituExportRepository;

final class ExportController
{
    /** Briefings focus on signal — skip `background` / `noise` / unscored by default. */
    private const DEFAULT_BRIEFING_LABELS = ['investigation_lead', 'important'];

    public function entries(): void
    {
        $pdo    = getDbConnection();
        $config = new SystemConfigRepository($pdo);
        if (!BearerAuth::guardExport($config)) {
            return;
        }

        $since = self::stringParam($_GET['since'] ?? null);
        $limit = self::clampInt($_GET['limit'] ?? 500, 1, MagnituExportRepository::MAX_LIMIT);
        $type  = self::stringParam($_GET['type']  ?? 'all') ?? 'all';

        [$entries, $scoresByKey] = $this->gatherEntriesAndScores($pdo, $since, $limit, $type, null);

        $body = JsonExportFormatter::format($entries, $scoresByKey, [
            'since' => $since,
            'type'  => $type,
            'limit' => $limit,
        ]);
        header('Content-Type: ' . JsonExportFormatter::CONTENT_TYPE);
        // Bearer-authenticated export — intermediaries must not cache.
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        echo $body;
    }

    public function briefing(): void
    {
        $pdo    = getDbConnection();
        $config = new SystemConfigRepository($pdo);
        if (!BearerAuth::guardExport($config)) {
            return;
        }

        $since = self::stringParam($_GET['since'] ?? null);
        $limit = self::clampInt($_GET['limit'] ?? 100, 1, MagnituExportRepository::MAX_LIMIT);

        $labelFilter = self::parseLabelFilter($_GET['labels'] ?? null) ?? self::DEFAULT_BRIEFING_LABELS;

        [$entries, $scoresByKey] = $this->gatherEntriesAndScores($pdo, $since, $limit, 'all', $labelFilter);

        // Primary: relevance_score desc. Secondary: published_date desc,
        // then (entry_type, entry_id) for full determinism — two rows with
        // the same score and timestamp would otherwise flip between runs.
        usort($entries, static function (array $a, array $b) use ($scoresByKey): int {
            $ka = ($a['entry_type'] ?? '') . ':' . ($a['entry_id'] ?? '');
            $kb = ($b['entry_type'] ?? '') . ':' . ($b['entry_id'] ?? '');
            $sa = (float)($scoresByKey[$ka]['relevance_score'] ?? 0);
            $sb = (float)($scoresByKey[$kb]['relevance_score'] ?? 0);
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }
            $da = (string)($a['published_date'] ?? '');
            $db = (string)($b['published_date'] ?? '');
            if ($da !== $db) {
                return strcmp($db, $da);
            }
            $ta = (string)($a['entry_type'] ?? '');
            $tb = (string)($b['entry_type'] ?? '');
            if ($ta !== $tb) {
                return strcmp($ta, $tb);
            }
            return ((int)($a['entry_id'] ?? 0)) <=> ((int)($b['entry_id'] ?? 0));
        });

        $body = MarkdownBriefingFormatter::format($entries, $scoresByKey, [
            'since'        => $since,
            'limit'        => $limit,
            'label_filter' => $labelFilter,
            'total'        => count($entries),
        ]);
        header('Content-Type: ' . MarkdownBriefingFormatter::CONTENT_TYPE);
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        echo $body;
    }

    /**
     * Shared pipeline: pull per-family rows, shape them to the Magnitu
     * contract (for shape consistency with magnitu_entries), attach local
     * scores, optionally filter by label.
     *
     * @param array<int, string>|null $labelFilter When set, only entries whose
     *     Magnitu/recipe `predicted_label` is in the list are kept.
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function gatherEntriesAndScores(
        \PDO $pdo,
        ?string $since,
        int $limit,
        string $type,
        ?array $labelFilter,
    ): array {
        $repo = new MagnituExportRepository($pdo);

        $entries = [];
        if ($type === 'all' || $type === 'feed_item') {
            foreach ($repo->listFeedItemsSince($since, $limit) as $row) {
                $entries[] = MagnituController::shapeFeedItem($row);
            }
        }
        if ($type === 'all' || $type === 'email') {
            foreach ($repo->listEmailsSince($since, $limit) as $row) {
                $entries[] = MagnituController::shapeEmail($row);
            }
        }
        if ($type === 'all' || $type === 'lex_item') {
            foreach ($repo->listLexItemsSince($since, $limit) as $row) {
                $entries[] = MagnituController::shapeLexItem($row);
            }
        }
        if ($type === 'all' || $type === 'calendar_event') {
            foreach ($repo->listCalendarEventsSince($since, $limit) as $row) {
                $entries[] = MagnituController::shapeCalendarEvent($row);
            }
        }

        $pairs = [];
        foreach ($entries as $e) {
            $pairs[] = [(string)($e['entry_type'] ?? ''), (int)($e['entry_id'] ?? 0)];
        }
        $scoresByKey = $repo->scoresByEntryKey($pairs);

        if ($labelFilter !== null) {
            $entries = array_values(array_filter(
                $entries,
                static function (array $e) use ($scoresByKey, $labelFilter): bool {
                    $key = ($e['entry_type'] ?? '') . ':' . ($e['entry_id'] ?? '');
                    $label = (string)($scoresByKey[$key]['predicted_label'] ?? '');
                    return $label !== '' && in_array($label, $labelFilter, true);
                }
            ));
        }

        return [$entries, $scoresByKey];
    }

    // ------------------------------------------------------------------
    // Param helpers
    // ------------------------------------------------------------------

    private static function stringParam(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $v = trim($value);
        return $v === '' ? null : $v;
    }

    private static function clampInt(mixed $value, int $min, int $max): int
    {
        $n = (int)$value;
        if ($n < $min) {
            return $min;
        }
        if ($n > $max) {
            return $max;
        }
        return $n;
    }

    /**
     * @return array<int, string>|null
     */
    private static function parseLabelFilter(mixed $raw): ?array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $parts = array_map('trim', explode(',', $raw));
        $parts = array_values(array_filter(
            $parts,
            static fn (string $p): bool => in_array($p, ['investigation_lead', 'important', 'background', 'noise'], true)
        ));
        return $parts === [] ? null : $parts;
    }
}
