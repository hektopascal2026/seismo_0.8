<?php

declare(strict_types=1);

namespace Seismo\Service\Seismogramm;

use PDO;
use DateTimeImmutable;
use DateTimeZone;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Service\ResearcherSourceSelection;
use Seismo\Service\ResearcherScoreFilter;
use Seismo\Service\ResearcherEntryGatherer;
use Seismo\Service\ResearcherGeminiContext;
use Seismo\Formatter\MarkdownResearcherFormatter;
use Seismo\Service\ResearcherModuleGuard;
use Seismo\Core\MagnituScoreBands;

final class SeismogrammRequestContext
{
    private const DEFAULT_LIMIT = 200;
    private const ALLOWED_ITEM_COUNTS = [5, 7, 10, 12, 15];
    private const DEFAULT_ITEM_COUNT = 5;

    /**
     * Parse the filters and metadata from a raw POST array.
     */
    public function parseFiltersFromPost(array $post, PDO $pdo): array
    {
        $selection = $this->parseModuleSelection($post['modules'] ?? null);
        $lookbackDays = $this->parseLookbackDays($post['lookback_days'] ?? null);
        $since        = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . $lookbackDays . ' days')
            ->format('Y-m-d\TH:i:s\Z');
        $limit = $this->clampLimit($post['limit'] ?? self::DEFAULT_LIMIT);

        $includeImportantBelow = (string)($post['include_important'] ?? '0') === '1';
        $disregardMagnitu      = (string)($post['disregard_magnitu'] ?? '0') === '1';
        $useRecipeSnippets     = (string)($post['use_recipe_snippets'] ?? '0') === '1';
        $alertThreshold        = (new SystemConfigRepository($pdo))->getAlertThreshold();
        $scoreFilter           = new ResearcherScoreFilter(
            $alertThreshold,
            $includeImportantBelow,
            $disregardMagnitu,
        );

        return [
            'since'             => $since,
            'limit'             => $limit,
            'lookbackDays'      => $lookbackDays,
            'scoreFilter'       => $scoreFilter,
            'selection'         => $selection,
            'useRecipeSnippets' => $useRecipeSnippets,
        ];
    }

    public function parseItemCount(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT_ITEM_COUNT;
        }
        $v = (int)$raw;
        return in_array($v, self::ALLOWED_ITEM_COUNTS, true) ? $v : self::DEFAULT_ITEM_COUNT;
    }

    public function persistMaxContextEntries(PDO $pdo, mixed $raw): void
    {
        if ($raw === null || $raw === '') {
            return;
        }
        $v = (int)$raw;
        if ($v >= ResearcherGeminiContext::MIN_MAX_CONTEXT_ENTRIES && $v <= ResearcherGeminiContext::MAX_MAX_CONTEXT_ENTRIES) {
            (new SystemConfigRepository($pdo))->set('researcher:max_context_entries', (string)$v);
        }
    }

    public function gatherContext(PDO $pdo, array $filters, bool $enrichBodies = true): array
    {
        $gatherer = new ResearcherEntryGatherer();
        [$entries, $scoresByKey] = $gatherer->gather(
            $pdo,
            $filters['since'],
            $filters['limit'],
            $filters['selection'],
            null,
            $filters['scoreFilter'],
        );
        $gatherer->sortByRelevanceDesc($entries, $scoresByKey);
        $entries = $gatherer->filterByModuleSelection($entries, $filters['selection']);

        $geminiContext = new ResearcherGeminiContext(new SystemConfigRepository($pdo));
        $entriesEligibleBeforeCap = count($entries);
        $capped        = $geminiContext->capEntriesForModules(
            $entries,
            $scoresByKey,
            $gatherer,
            $filters['selection'],
        );
        $entries          = $capped['entries'];
        $contextTruncated = $capped['truncated'];
        $stratifiedCap    = $capped['stratified'];

        if ($enrichBodies) {
            $gatherer->enrichEntriesWithFullBodies($pdo, $filters['since'], $entries);
        }

        $entryBodyMaxChars = MarkdownResearcherFormatter::dynamicEntryBodyMaxChars(count($entries));
        $recipeKeywords = [];
        if ($filters['useRecipeSnippets'] ?? false) {
            $configRepo = new SystemConfigRepository($pdo);
            $recipeJson = $configRepo->get('recipe_json');
            if ($recipeJson !== null && $recipeJson !== '') {
                $recipe = json_decode($recipeJson, true) ?: [];
                $recipeKeywords = array_keys($recipe['keywords'] ?? []);
            }
        }

        $gatherMeta = [
            'since'                => $filters['since'],
            'limit'                => $filters['limit'],
            'score_selection'      => MagnituScoreBands::describeResearcherGather($filters['scoreFilter']),
            'entry_body_max_chars' => $entryBodyMaxChars,
            'use_recipe_snippets'  => $filters['useRecipeSnippets'] ?? false,
            'recipe_keywords'      => $recipeKeywords,
        ];

        $guard  = new ResearcherModuleGuard($gatherer);
        $sealed = $guard->sealGeminiContext($entries, $scoresByKey, $gatherMeta, $filters['selection']);
        $entries       = $sealed['entries'];
        $markdown      = $sealed['markdown'];
        $markdownChars = $sealed['markdownChars'];

        $contextWarning = $this->contextSizeWarning($markdownChars);
        if ($contextTruncated > 0) {
            $capNote = count($entries) . ' sent to Gemini; '
                . $contextTruncated . ' additional '
                . ($contextTruncated === 1 ? 'entry was' : 'entries were')
                . ' omitted (cap '
                . $geminiContext->maxContextEntries()
                . ', '
                . $entriesEligibleBeforeCap . ' eligible before cap'
                . ($stratifiedCap
                    ? '; fair share per enabled source module, then relevance'
                    : '; highest relevance, then newest')
                . ').';
            $contextWarning = $contextWarning !== null
                ? $contextWarning . ' ' . $capNote
                : $capNote;
        }

        return [
            'entries'           => $entries,
            'scoresByKey'       => $scoresByKey,
            'markdown'          => $markdown,
            'markdownChars'     => $markdownChars,
            'contextWarning'    => $contextWarning,
            'gatherStats'       => $gatherer->lastGatherStats(),
            'contextTruncated'         => $contextTruncated,
            'entriesEligibleBeforeCap' => $entriesEligibleBeforeCap,
            'maxContextEntries'        => $geminiContext->maxContextEntries(),
            'entry_body_max_chars'     => $entryBodyMaxChars,
        ];
    }

    public function contextCapMetaFromGathered(array $gathered): array
    {
        $sent    = count($gathered['entries']);
        $omitted = (int)($gathered['contextTruncated'] ?? 0);
        $eligible = (int)($gathered['entriesEligibleBeforeCap'] ?? ($sent + $omitted));
        $max     = (int)($gathered['maxContextEntries'] ?? 0);

        $meta = [
            'entries_sent_to_gemini'      => $sent,
            'entries_omitted_by_cap'      => $omitted,
            'entries_eligible_before_cap' => $eligible,
            'max_context_entries'         => $max,
            'entry_count'                 => $sent,
        ];
        if ($omitted > 0) {
            $meta['context_truncated'] = $omitted;
        }

        return $meta;
    }

    private function parseModuleSelection(mixed $raw): ResearcherSourceSelection
    {
        $modules = is_array($raw) ? $raw : [];
        return ResearcherSourceSelection::fromArray($modules);
    }

    private function parseLookbackDays(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return 7;
        }
        $v = (int)$raw;
        if ($v < 1 || $v > 7) {
            return 2; // Fallback matches AiResearcherController
        }
        return $v;
    }

    private function clampLimit(mixed $raw): int
    {
        $v = (int)$raw;
        if ($v < 1) {
            return self::DEFAULT_LIMIT;
        }
        $max = 2000; // MagnituExportRepository::BRIEFING_MAX_LIMIT
        return min($v, $max);
    }

    private function contextSizeWarning(int $chars): ?string
    {
        if ($chars > 400_000) {
            return 'Large context size (' . number_format($chars) . ' characters). Selection processing may hit limits or experience higher latency.';
        }
        if ($chars > 200_000) {
            return 'Moderate context size (' . number_format($chars) . ' characters). Consider using Snippets if this runs slowly.';
        }
        return null;
    }
}
