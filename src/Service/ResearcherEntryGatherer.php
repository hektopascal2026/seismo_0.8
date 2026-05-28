<?php

declare(strict_types=1);

namespace Seismo\Service;

use PDO;
use Seismo\Controller\MagnituController;
use Seismo\Core\Fetcher\ArticleLinkNormalizer;
use Seismo\Core\MagnituScoreBands;
use Seismo\Repository\EntryRepository;
use Seismo\Repository\MagnituExportRepository;

/**
 * Shared pipeline for export researcher and AI Researcher.
 *
 * Pulls per-family rows, shapes them to the Magnitu contract, attaches local
 * scores. Export uses optional {@see $labelFilter}; the Researcher uses
 * {@see ResearcherScoreFilter} (Highlights tier + optional Important band).
 */
final class ResearcherEntryGatherer
{
    /** @var array{entries_before_score_filter: int, entries_after_score_filter: int}|null */
    private ?array $lastGatherStats = null;

    /**
     * Stats from the latest {@see gatherForResearcherBuilder()} run (builder mode only).
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
        ResearcherSourceSelection $selection,
        ?array $labelFilter,
        ?ResearcherScoreFilter $scoreFilter = null,
    ): array {
        if (!$selection->isExportMode() && !$selection->hasAnyModule()) {
            return [[], []];
        }

        if ($scoreFilter !== null && !$selection->isExportMode()) {
            return $this->gatherForResearcherBuilder($pdo, $since, $limit, $selection, $scoreFilter);
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

        return $this->finalizeGatheredEntries($entries, $scoresByKey);
    }

    /**
     * Score-first gather: same relevance rules as Highlights, scoped by lookback + modules.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function gatherForResearcherBuilder(
        PDO $pdo,
        ?string $since,
        int $limit,
        ResearcherSourceSelection $selection,
        ResearcherScoreFilter $scoreFilter,
    ): array {
        $this->lastGatherStats = null;

        if ($scoreFilter->disregardMagnitu) {
            return $this->gatherForResearcherBuilderWithoutMagnitu($pdo, $since, $limit, $selection);
        }

        $repo      = new MagnituExportRepository($pdo);
        $entryRepo = new EntryRepository($pdo);

        $scoreRows = $entryRepo->listResearcherScoreCandidates(
            $scoreFilter->alertThreshold,
            $scoreFilter->includeImportantBelowThreshold,
            MagnituExportRepository::BRIEFING_MAX_LIMIT,
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
            if (!$this->entryTypeGatherable($type, $selection)) {
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

        foreach ($this->hydrateMissingEntries($repo, $since, $missingIdsByType, false) as $e) {
            if (!ResearcherLookback::entryInWindow($e, $since)) {
                continue;
            }
            if (!$this->entryMatchesModuleSelection($e, $selection)) {
                continue;
            }
            $k = ($e['entry_type'] ?? '') . ':' . ($e['entry_id'] ?? '');
            if ($k !== ':') {
                $byKey[$k] = $e;
            }
        }

        $entries = $this->filterByModuleSelection(array_values($byKey), $selection);

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
                if (!ResearcherLookback::entryInWindow($e, $since)) {
                    return false;
                }
                $key = ($e['entry_type'] ?? '') . ':' . ($e['entry_id'] ?? '');
                $rel = (float)($scoresByKey[$key]['relevance_score'] ?? 0);

                return MagnituScoreBands::passesResearcherPool(
                    $rel,
                    $scoreFilter->alertThreshold,
                    $scoreFilter->includeImportantBelowThreshold,
                );
            }
        ));

        $entries = $this->filterByModuleSelection($entries, $selection);

        $this->lastGatherStats = [
            'entries_before_score_filter' => $entriesBeforeScoreFilter,
            'entries_after_score_filter'  => count($entries),
        ];

        $this->sortByRelevanceDesc($entries, $scoresByKey);

        return $this->finalizeGatheredEntries($entries, $scoresByKey);
    }

    /**
     * Module + lookback only — no Magnitu score pool or relevance threshold.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function gatherForResearcherBuilderWithoutMagnitu(
        PDO $pdo,
        ?string $since,
        int $limit,
        ResearcherSourceSelection $selection,
    ): array {
        $repo    = new MagnituExportRepository($pdo);
        $entries = array_values(array_filter(
            $this->collectShapedEntries($repo, $since, $limit, $selection),
            function (array $e) use ($selection, $since): bool {
                if (!$this->entryMatchesModuleSelection($e, $selection)) {
                    return false;
                }

                return ResearcherLookback::entryInWindow($e, $since);
            },
        ));

        $entries = $this->filterByModuleSelection($entries, $selection);

        $pairs = [];
        foreach ($entries as $e) {
            $pairs[] = [(string)($e['entry_type'] ?? ''), (int)($e['entry_id'] ?? 0)];
        }
        $scoresByKey = $repo->scoresByEntryKey($pairs);

        $this->sortByRelevanceDesc($entries, $scoresByKey);

        return $this->finalizeGatheredEntries($entries, $scoresByKey);
    }

    /**
     * Collapse duplicate feed rows that share the same article URL (topic feeds).
     * Keeps the row with the highest Magnitu relevance score.
     *
     * @param list<array<string, mixed>> $entries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @return list<array<string, mixed>>
     */
    public function deduplicateFeedItemsByLink(array $entries, array $scoresByKey): array
    {
        /** @var array<string, int> $indexByLink */
        $indexByLink = [];
        $out         = [];

        foreach ($entries as $entry) {
            if (($entry['entry_type'] ?? '') !== 'feed_item') {
                $out[] = $entry;
                continue;
            }

            $link = trim((string)($entry['link'] ?? ''));
            if ($link === '') {
                $out[] = $entry;
                continue;
            }

            $normKey = ArticleLinkNormalizer::normalize($link);
            if ($normKey === '') {
                $out[] = $entry;
                continue;
            }

            if (!isset($indexByLink[$normKey])) {
                $indexByLink[$normKey] = count($out);
                $out[]                 = $entry;
                continue;
            }

            $existingIdx = $indexByLink[$normKey];
            $existing    = $out[$existingIdx];
            $existingKey = ($existing['entry_type'] ?? '') . ':' . ($existing['entry_id'] ?? '');
            $newKey      = ($entry['entry_type'] ?? '') . ':' . ($entry['entry_id'] ?? '');
            $existingScore = (float)($scoresByKey[$existingKey]['relevance_score'] ?? -1.0);
            $newScore      = (float)($scoresByKey[$newKey]['relevance_score'] ?? -1.0);

            if ($newScore > $existingScore) {
                $out[$existingIdx] = $entry;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @return array{0: list<array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function finalizeGatheredEntries(array $entries, array $scoresByKey): array
    {
        return [$this->deduplicateFeedItemsByLink($entries, $scoresByKey), $scoresByKey];
    }

    /**
     * Researcher priority: highest relevance_score, then newest entry time,
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
            $ta = ResearcherLookback::entrySortTimestamp($a);
            $tb = ResearcherLookback::entrySortTimestamp($b);
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
        bool $includeFullBody,
    ): array {
        $out = [];

        $feedIds = array_values($missingIdsByType['feed_item'] ?? []);
        if ($feedIds !== []) {
            foreach ($repo->listFeedItemsByIds($feedIds, $since, $includeFullBody) as $row) {
                $out[] = MagnituController::shapeFeedItem($row);
            }
        }

        $emailIds = array_values($missingIdsByType['email'] ?? []);
        if ($emailIds !== []) {
            foreach ($repo->listEmailsByIds($emailIds, $since, $includeFullBody) as $row) {
                $out[] = MagnituController::shapeEmail($row);
            }
        }

        $lexIds = array_values($missingIdsByType['lex_item'] ?? []);
        if ($lexIds !== []) {
            foreach ($repo->listLexItemsByIds($lexIds, $since, $includeFullBody) as $row) {
                $out[] = MagnituController::shapeLexItem($row);
            }
        }

        $legIds = array_values($missingIdsByType['calendar_event'] ?? []);
        if ($legIds !== []) {
            foreach ($repo->listCalendarEventsByIds($legIds, $since, $includeFullBody) as $row) {
                $out[] = MagnituController::shapeCalendarEvent($row);
            }
        }

        return $out;
    }

    /**
     * Load LONGTEXT bodies for the final capped researcher pool only (avoids OOM during score hydration).
     *
     * @param list<array<string, mixed>> $entries Shaped Magnitu rows (mutated in place).
     */
    public function enrichEntriesWithFullBodies(PDO $pdo, ?string $since, array &$entries): void
    {
        if ($entries === []) {
            return;
        }

        /** @var array<string, list<int>> $idsByType */
        $idsByType = [
            'feed_item'      => [],
            'email'          => [],
            'lex_item'       => [],
            'calendar_event' => [],
        ];
        foreach ($entries as $e) {
            $type = (string)($e['entry_type'] ?? '');
            $id   = (int)($e['entry_id'] ?? 0);
            if ($id <= 0 || !isset($idsByType[$type])) {
                continue;
            }
            $idsByType[$type][$id] = $id;
        }

        $repo = new MagnituExportRepository($pdo);
        /** @var array<string, array<string, mixed>> $shapedByKey */
        $shapedByKey = [];

        $feedIds = array_values($idsByType['feed_item']);
        if ($feedIds !== []) {
            foreach ($repo->listFeedItemsByIds($feedIds, $since, true) as $row) {
                $shapedByKey['feed_item:' . (int)$row['id']] = MagnituController::shapeFeedItem($row);
            }
        }

        $emailIds = array_values($idsByType['email']);
        if ($emailIds !== []) {
            foreach ($repo->listEmailsByIds($emailIds, $since, true) as $row) {
                $shapedByKey['email:' . (int)$row['id']] = MagnituController::shapeEmail($row);
            }
        }

        $lexIds = array_values($idsByType['lex_item']);
        if ($lexIds !== []) {
            foreach ($repo->listLexItemsByIds($lexIds, $since, true) as $row) {
                $shapedByKey['lex_item:' . (int)$row['id']] = MagnituController::shapeLexItem($row);
            }
        }

        $legIds = array_values($idsByType['calendar_event']);
        if ($legIds !== []) {
            foreach ($repo->listCalendarEventsByIds($legIds, $since, true) as $row) {
                $shapedByKey['calendar_event:' . (int)$row['id']] = MagnituController::shapeCalendarEvent($row);
            }
        }

        foreach ($entries as $i => $e) {
            $key = ($e['entry_type'] ?? '') . ':' . ($e['entry_id'] ?? '');
            if (!isset($shapedByKey[$key])) {
                continue;
            }
            $full = $shapedByKey[$key];
            $entries[$i]['description'] = $full['description'];
            $entries[$i]['content']     = $full['content'];
            if (($e['entry_type'] ?? '') === 'email') {
                $entries[$i]['title'] = $full['title'];
                $entries[$i]['link']  = $full['link'];
            }
        }
    }

    /**
     * Keep only rows from enabled nav modules (Feeds / Media / …).
     *
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    public function filterByModuleSelection(array $entries, ResearcherSourceSelection $selection): array
    {
        return array_values(array_filter(
            $entries,
            fn(array $e): bool => $this->entryMatchesModuleSelection($e, $selection),
        ));
    }

    /**
     * @param array<string, mixed> $entry Shaped Magnitu export row.
     */
    public function entryMatchesModuleSelection(array $entry, ResearcherSourceSelection $selection): bool
    {
        return $this->moduleBucketForEntry($entry, $selection) !== null;
    }

    /**
     * Nav module bucket for stratified Gemini context caps (feeds / media / …).
     *
     * @param array<string, mixed> $entry Shaped Magnitu export row.
     */
    public function moduleBucketForEntry(array $entry, ResearcherSourceSelection $selection): ?string
    {
        $type = (string)($entry['entry_type'] ?? '');

        if ($type === 'feed_item') {
            $sourceType = strtolower((string)($entry['source_type'] ?? ''));
            $category   = strtolower((string)($entry['source_category'] ?? ''));

            $isMedia   = $category === 'media';
            $isScraper = $sourceType === 'scraper' || $category === 'scraper'
                || (isset($entry['scraper_config_id']) && (int)$entry['scraper_config_id'] > 0);
            $isFeeds   = !$isMedia && !$isScraper
                && in_array($sourceType, ['rss', 'substack', 'parl_press'], true);

            if ($selection->moduleFeeds() && $isFeeds) {
                return 'feeds';
            }
            if ($selection->moduleMedia() && $isMedia) {
                return 'media';
            }
            if ($selection->moduleScraper() && $isScraper) {
                return 'scraper';
            }

            return null;
        }

        return match ($type) {
            'email' => $selection->moduleEmail() ? 'email' : null,
            'lex_item' => (($selection->moduleLex() || $selection->moduleLexCh()) && (!$selection->moduleLexCh() || ($entry['source_type'] ?? '') === 'lex_ch')) ? 'lex' : null,
            'calendar_event' => $selection->moduleLeg() ? 'leg' : null,
            default => null,
        };
    }

    /**
     * Whether Magnitu score hydration may fetch this entry_type at all.
     */
    private function entryTypeGatherable(string $entryType, ResearcherSourceSelection $selection): bool
    {
        return match ($entryType) {
            'email' => $selection->moduleEmail(),
            'lex_item' => $selection->moduleLex() || $selection->moduleLexCh(),
            'calendar_event' => $selection->moduleLeg(),
            'feed_item' => $selection->moduleFeeds()
                || $selection->moduleMedia()
                || $selection->moduleScraper(),
            default => false,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectShapedEntries(
        MagnituExportRepository $repo,
        ?string $since,
        int $limit,
        ResearcherSourceSelection $selection,
    ): array {
        $limitCap = $selection->isExportMode()
            ? MagnituExportRepository::MAX_LIMIT
            : MagnituExportRepository::BRIEFING_MAX_LIMIT;
        $entries  = [];

        if ($selection->isExportMode()) {
            $type = $selection->exportType();
            if ($type === 'all' || $type === 'feed_item') {
                foreach ($repo->listFeedItemsSince($since, $limit, $limitCap) as $row) {
                    $entries[] = MagnituController::shapeFeedItem($row);
                }
            }
            if ($type === 'all' || $type === 'email') {
                foreach ($repo->listEmailsSince($since, $limit, $limitCap) as $row) {
                    $entries[] = MagnituController::shapeEmail($row);
                }
            }
            if ($type === 'all' || $type === 'lex_item') {
                foreach ($repo->listLexItemsSince($since, $limit, $limitCap) as $row) {
                    $entries[] = MagnituController::shapeLexItem($row);
                }
            }
            if ($type === 'all' || $type === 'calendar_event') {
                foreach ($repo->listCalendarEventsSince($since, $limit, $limitCap) as $row) {
                    $entries[] = MagnituController::shapeCalendarEvent($row);
                }
            }

            return $entries;
        }

        if ($selection->moduleFeeds()) {
            foreach ($repo->listFeedItemsForModule($since, $limit, 'feeds', $limitCap) as $row) {
                $entries[] = MagnituController::shapeFeedItem($row);
            }
        }
        if ($selection->moduleMedia()) {
            foreach ($repo->listFeedItemsForModule($since, $limit, 'media', $limitCap) as $row) {
                $entries[] = MagnituController::shapeFeedItem($row);
            }
        }
        if ($selection->moduleScraper()) {
            foreach ($repo->listFeedItemsForModule($since, $limit, 'scraper', $limitCap) as $row) {
                $entries[] = MagnituController::shapeFeedItem($row);
            }
        }
        if ($selection->moduleEmail()) {
            foreach ($repo->listEmailsSince($since, $limit, $limitCap) as $row) {
                $entries[] = MagnituController::shapeEmail($row);
            }
        }
        if ($selection->moduleLex() || $selection->moduleLexCh()) {
            foreach ($repo->listLexItemsSince($since, $limit, $limitCap) as $row) {
                if ($selection->moduleLexCh() && ($row['source'] ?? '') !== 'ch') {
                    continue;
                }
                $entries[] = MagnituController::shapeLexItem($row);
            }
        }
        if ($selection->moduleLeg()) {
            foreach ($repo->listCalendarEventsSince($since, $limit, $limitCap) as $row) {
                $entries[] = MagnituController::shapeCalendarEvent($row);
            }
        }

        return $entries;
    }
}
