<?php

declare(strict_types=1);

namespace Seismo\Service;

use PDO;
use Seismo\Controller\MagnituController;
use Seismo\Core\MagnituScoreBands;
use Seismo\Repository\EntryRepository;
use Seismo\Repository\MagnituExportRepository;

/**
 * Shared pipeline for export briefing and AI Briefing Builder.
 *
 * Pulls per-family rows, shapes them to the Magnitu contract, attaches local
 * scores. Export uses optional {@see $labelFilter}; the Briefing Builder uses
 * {@see BriefingScoreFilter} (Highlights tier + optional Important band).
 */
final class BriefingEntryGatherer
{
    /** @var array{entries_before_score_filter: int, entries_after_score_filter: int}|null */
    private ?array $lastGatherStats = null;

    /**
     * Stats from the latest {@see gatherForBriefingBuilder()} run (builder mode only).
     *
     * @return array{entries_before_score_filter: int, entries_after_score_filter: int}|null
     */
    public function lastGatherStats(): ?array
    {
        return $this->lastGatherStats;
    }

    /**
     * @param array<int, string>|null $labelFilter Export only: Magnitu `predicted_label` list.
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    public function gather(
        PDO $pdo,
        ?string $since,
        int $limit,
        BriefingSourceSelection $selection,
        ?array $labelFilter,
        ?BriefingScoreFilter $scoreFilter = null,
    ): array {
        if (!$selection->isExportMode() && !$selection->hasAnyModule()) {
            return [[], []];
        }

        if ($scoreFilter !== null && !$selection->isExportMode()) {
            return $this->gatherForBriefingBuilder($pdo, $since, $limit, $selection, $scoreFilter);
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
     * Score-first gather: same relevance rules as Highlights, scoped by lookback + modules.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function gatherForBriefingBuilder(
        PDO $pdo,
        ?string $since,
        int $limit,
        BriefingSourceSelection $selection,
        BriefingScoreFilter $scoreFilter,
    ): array {
        $this->lastGatherStats = null;

        if ($scoreFilter->disregardMagnitu) {
            return $this->gatherForBriefingBuilderWithoutMagnitu($pdo, $since, $limit, $selection);
        }

        $repo      = new MagnituExportRepository($pdo);
        $entryRepo = new EntryRepository($pdo);

        $scoreRows = $entryRepo->listBriefingScoreCandidates(
            $scoreFilter->alertThreshold,
            $scoreFilter->includeImportantBelowThreshold,
            MagnituExportRepository::MAX_LIMIT,
        );

        $entries = $this->collectShapedEntries($repo, $since, $limit, $selection);
        /** @var array<string, array<string, mixed>> $byKey */
        $byKey = [];
        foreach ($entries as $e) {
            $k = ($e['entry_type'] ?? '') . ':' . ($e['entry_id'] ?? '');
            if ($k !== ':') {
                $byKey[$k] = $e;
            }
        }

        /** @var array<string, list<int>> $missingIdsByType */
        $missingIdsByType = [
            'feed_item'       => [],
            'email'           => [],
            'lex_item'        => [],
            'calendar_event'  => [],
        ];

        foreach ($scoreRows as $row) {
            $type = (string)($row['entry_type'] ?? '');
            $id   = (int)($row['entry_id'] ?? 0);
            if ($id <= 0 || $type === '') {
                continue;
            }
            $key = $type . ':' . $id;
            if (isset($byKey[$key])) {
                continue;
            }
            if (!isset($missingIdsByType[$type])) {
                continue;
            }
            $missingIdsByType[$type][$id] = $id;
        }

        foreach ($this->hydrateMissingEntries($repo, $since, $missingIdsByType) as $e) {
            if (!BriefingLookback::entryInWindow($e, $since)) {
                continue;
            }
            $k = ($e['entry_type'] ?? '') . ':' . ($e['entry_id'] ?? '');
            if ($k !== ':') {
                $byKey[$k] = $e;
            }
        }

        $entries = array_values($byKey);

        $pairs = [];
        foreach ($entries as $e) {
            $pairs[] = [(string)($e['entry_type'] ?? ''), (int)($e['entry_id'] ?? 0)];
        }
        $scoresByKey = $repo->scoresByEntryKey($pairs);

        $entriesBeforeScoreFilter = count($entries);
        $entries = array_values(array_filter(
            $entries,
            function (array $e) use ($scoresByKey, $selection, $scoreFilter, $since): bool {
                if (!$this->entryMatchesModuleSelection($e, $selection)) {
                    return false;
                }
                if (!BriefingLookback::entryInWindow($e, $since)) {
                    return false;
                }
                $key = ($e['entry_type'] ?? '') . ':' . ($e['entry_id'] ?? '');
                $rel = (float)($scoresByKey[$key]['relevance_score'] ?? 0);

                return MagnituScoreBands::passesBriefingPool(
                    $rel,
                    $scoreFilter->alertThreshold,
                    $scoreFilter->includeImportantBelowThreshold,
                );
            }
        ));

        $this->lastGatherStats = [
            'entries_before_score_filter' => $entriesBeforeScoreFilter,
            'entries_after_score_filter'  => count($entries),
        ];

        $this->sortByRelevanceDesc($entries, $scoresByKey);

        return [$entries, $scoresByKey];
    }

    /**
     * Module + lookback only — no Magnitu score pool or relevance threshold.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function gatherForBriefingBuilderWithoutMagnitu(
        PDO $pdo,
        ?string $since,
        int $limit,
        BriefingSourceSelection $selection,
    ): array {
        $repo    = new MagnituExportRepository($pdo);
        $entries = array_values(array_filter(
            $this->collectShapedEntries($repo, $since, $limit, $selection),
            function (array $e) use ($selection, $since): bool {
                if (!$this->entryMatchesModuleSelection($e, $selection)) {
                    return false;
                }

                return BriefingLookback::entryInWindow($e, $since);
            },
        ));

        $pairs = [];
        foreach ($entries as $e) {
            $pairs[] = [(string)($e['entry_type'] ?? ''), (int)($e['entry_id'] ?? 0)];
        }
        $scoresByKey = $repo->scoresByEntryKey($pairs);

        $this->sortByRelevanceDesc($entries, $scoresByKey);

        return [$entries, $scoresByKey];
    }

    /**
     * Briefing priority: highest relevance_score, then newest entry time,
     * then (entry_type, entry_id) for determinism. Used for Gemini context cap.
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
            $ta = BriefingLookback::entrySortTimestamp($a);
            $tb = BriefingLookback::entrySortTimestamp($b);
            if ($ta !== $tb) {
                return $tb <=> $ta;
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
     * @param array<int, array<string, mixed>> $entries
     */
    public function sortByPublishedDateDesc(array &$entries): void
    {
        usort($entries, static function (array $a, array $b): int {
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
     * @param array<string, list<int>> $missingIdsByType
     * @return array<int, array<string, mixed>>
     */
    private function hydrateMissingEntries(
        MagnituExportRepository $repo,
        ?string $since,
        array $missingIdsByType,
    ): array {
        $out = [];

        $feedIds = array_values($missingIdsByType['feed_item'] ?? []);
        if ($feedIds !== []) {
            foreach ($repo->listFeedItemsByIds($feedIds, $since) as $row) {
                $out[] = MagnituController::shapeFeedItem($row);
            }
        }

        $emailIds = array_values($missingIdsByType['email'] ?? []);
        if ($emailIds !== []) {
            foreach ($repo->listEmailsByIds($emailIds, $since) as $row) {
                $out[] = MagnituController::shapeEmail($row);
            }
        }

        $lexIds = array_values($missingIdsByType['lex_item'] ?? []);
        if ($lexIds !== []) {
            foreach ($repo->listLexItemsByIds($lexIds, $since) as $row) {
                $out[] = MagnituController::shapeLexItem($row);
            }
        }

        $legIds = array_values($missingIdsByType['calendar_event'] ?? []);
        if ($legIds !== []) {
            foreach ($repo->listCalendarEventsByIds($legIds, $since) as $row) {
                $out[] = MagnituController::shapeCalendarEvent($row);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $entry Shaped Magnitu export row.
     */
    private function entryMatchesModuleSelection(array $entry, BriefingSourceSelection $selection): bool
    {
        $type = (string)($entry['entry_type'] ?? '');

        return match ($type) {
            'feed_item' => $this->feedItemMatchesModuleSelection($entry, $selection),
            'email' => $selection->moduleEmail(),
            'lex_item' => $selection->moduleLex(),
            'calendar_event' => $selection->moduleLeg(),
            default => false,
        };
    }

    /**
     * Heuristic partition (feeds / media / scraper) from shaped feed metadata.
     *
     * @param array<string, mixed> $entry
     */
    private function feedItemMatchesModuleSelection(array $entry, BriefingSourceSelection $selection): bool
    {
        $sourceType = strtolower((string)($entry['source_type'] ?? ''));
        $category   = strtolower((string)($entry['source_category'] ?? ''));

        $isMedia = $category === 'media';
        $isScraper = $sourceType === 'scraper'
            || $category === 'scraper';
        $isFeeds = !$isMedia && !$isScraper
            && in_array($sourceType, ['rss', 'substack', 'parl_press'], true);

        return ($selection->moduleFeeds() && $isFeeds)
            || ($selection->moduleMedia() && $isMedia)
            || ($selection->moduleScraper() && $isScraper);
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
