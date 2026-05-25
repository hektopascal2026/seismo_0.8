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
use Seismo\Repository\MagnituExportRepository;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Service\BriefingEntryGatherer;
use Seismo\Service\BriefingSourceSelection;

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

        $gatherer = new BriefingEntryGatherer();
        [$entries, $scoresByKey] = $gatherer->gather(
            $pdo,
            $since,
            $limit,
            BriefingSourceSelection::forExport($type),
            null,
        );

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

        $gatherer = new BriefingEntryGatherer();
        [$entries, $scoresByKey] = $gatherer->gather(
            $pdo,
            $since,
            $limit,
            BriefingSourceSelection::forExport('all'),
            $labelFilter,
        );
        $gatherer->sortByRelevanceDesc($entries, $scoresByKey);

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
        if ($n < 1) {
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
