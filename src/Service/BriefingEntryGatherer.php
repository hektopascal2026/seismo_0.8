<?php

declare(strict_types=1);

namespace Seismo\Service;

use PDO;
use Seismo\Controller\MagnituController;
use Seismo\Repository\MagnituExportRepository;

/**
 * Shared pipeline for export briefing and AI Briefing Builder.
 *
 * Pulls per-family rows, shapes them to the Magnitu contract, attaches local
 * scores, optionally filters by predicted_label.
 */
final class BriefingEntryGatherer
{
    /**
     * @param array<int, string>|null $labelFilter When set, only entries whose
     *     Magnitu/recipe `predicted_label` is in the list are kept.
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    public function gather(
        PDO $pdo,
        ?string $since,
        int $limit,
        BriefingSourceSelection $selection,
        ?array $labelFilter,
    ): array {
        if (!$selection->isExportMode() && !$selection->hasAnyModule()) {
            return [[], []];
        }

        $repo    = new MagnituExportRepository($pdo);
        $entries = $this->collectShapedEntries($repo, $since, $limit, $selection);

        $pairs = [];
        foreach ($entries as $e) {
            $pairs[] = [(string)($e['entry_type'] ?? ''), (int)($e['entry_id'] ?? 0)];
        }
        $scoresByKey = $repo->scoresByEntryKey($pairs);

        if ($labelFilter !== null) {
            $entries = array_values(array_filter(
                $entries,
                static function (array $e) use ($scoresByKey, $labelFilter): bool {
                    $key   = ($e['entry_type'] ?? '') . ':' . ($e['entry_id'] ?? '');
                    $label = (string)($scoresByKey[$key]['predicted_label'] ?? '');

                    return $label !== '' && in_array($label, $labelFilter, true);
                }
            ));
        }

        return [$entries, $scoresByKey];
    }

    /**
     * Primary: relevance_score desc. Secondary: published_date desc,
     * then (entry_type, entry_id) for full determinism.
     *
     * @param array<int, array<string, mixed>> $entries
     * @param array<string, array<string, mixed>> $scoresByKey
     */
    public function sortByRelevanceDesc(array &$entries, array $scoresByKey): void
    {
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
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectShapedEntries(
        MagnituExportRepository $repo,
        ?string $since,
        int $limit,
        BriefingSourceSelection $selection,
    ): array {
        $entries = [];

        if ($selection->isExportMode()) {
            $type = $selection->exportType();
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

            return $entries;
        }

        if ($selection->moduleFeeds()) {
            foreach ($repo->listFeedItemsForModule($since, $limit, 'feeds') as $row) {
                $entries[] = MagnituController::shapeFeedItem($row);
            }
        }
        if ($selection->moduleMedia()) {
            foreach ($repo->listFeedItemsForModule($since, $limit, 'media') as $row) {
                $entries[] = MagnituController::shapeFeedItem($row);
            }
        }
        if ($selection->moduleScraper()) {
            foreach ($repo->listFeedItemsForModule($since, $limit, 'scraper') as $row) {
                $entries[] = MagnituController::shapeFeedItem($row);
            }
        }
        if ($selection->moduleEmail()) {
            foreach ($repo->listEmailsSince($since, $limit) as $row) {
                $entries[] = MagnituController::shapeEmail($row);
            }
        }
        if ($selection->moduleLex()) {
            foreach ($repo->listLexItemsSince($since, $limit) as $row) {
                $entries[] = MagnituController::shapeLexItem($row);
            }
        }
        if ($selection->moduleLeg()) {
            foreach ($repo->listCalendarEventsSince($since, $limit) as $row) {
                $entries[] = MagnituController::shapeCalendarEvent($row);
            }
        }

        return $entries;
    }
}
