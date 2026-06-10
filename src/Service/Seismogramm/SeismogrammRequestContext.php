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
        $preset = SeismogrammPresetProfile::normalizePreset((string)($post['preset'] ?? ''));
        $customAdvanced = (string)($post['custom_advanced'] ?? '0') === '1';
        $gatherDefaults = SeismogrammPresetProfile::gatherDefaults($preset, $customAdvanced);

        $selection = $this->parseModuleSelection($post['modules'] ?? null);
        $lookbackDays = $this->parseLookbackDays($post['lookback_days'] ?? null);
        $since        = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . $lookbackDays . ' days')
            ->format('Y-m-d\TH:i:s\Z');
        $limit = $this->clampLimit($post['limit'] ?? self::DEFAULT_LIMIT);

        $includeImportantBelow = (string)($post['include_important'] ?? '0') === '1';
        $disregardMagnitu      = $customAdvanced
            ? (string)($post['disregard_magnitu'] ?? '0') === '1'
            : $gatherDefaults['disregardMagnitu'];
        $useRecipeSnippets     = $customAdvanced
            ? (string)($post['use_recipe_snippets'] ?? '0') === '1'
            : $gatherDefaults['useRecipeSnippets'];
        $alertThreshold        = (new SystemConfigRepository($pdo))->getAlertThreshold();
        $scoreFilter           = new ResearcherScoreFilter(
            $alertThreshold,
            $includeImportantBelow,
            $disregardMagnitu,
        );

        $useContextCache = $customAdvanced
            && (string)($post['use_context_cache'] ?? '0') === '1';
        $poolPriority = $customAdvanced
            ? SeismogrammPresetProfile::normalizePoolPriority((string)($post['pool_priority'] ?? ''))
            : ($gatherDefaults['poolPriority'] ?? SeismogrammPresetProfile::defaultPoolPriority($preset));

        return [
            'since'             => $since,
            'limit'             => $limit,
            'lookbackDays'      => $lookbackDays,
            'scoreFilter'       => $scoreFilter,
            'selection'         => $selection,
            'useRecipeSnippets' => $useRecipeSnippets,
            'useContextCache'   => $useContextCache,
            'poolPriority'      => $poolPriority,
            'preset'            => $preset,
            'customAdvanced'    => $customAdvanced,
            'gatherDefaults'    => $gatherDefaults,
        ];
    }

    public function formatterMetaForEntryCount(array $baseMeta, int $entryCount): array
    {
        $meta = $baseMeta;
        $meta['entry_body_max_chars'] = MarkdownResearcherFormatter::dynamicEntryBodyMaxChars($entryCount);

        return $meta;
    }

    public function resolveMaxContextEntriesForRequest(array $filters, mixed $postedMax, int $configuredMax): int
    {
        if ($filters['customAdvanced'] ?? false) {
            if ($postedMax !== null && $postedMax !== '') {
                $v = (int)$postedMax;
                if ($v >= ResearcherGeminiContext::MIN_MAX_CONTEXT_ENTRIES
                    && $v <= ResearcherGeminiContext::MAX_MAX_CONTEXT_ENTRIES) {
                    return $v;
                }
            }

            return $configuredMax;
        }

        $floor = $filters['gatherDefaults']['maxContextFloor'] ?? null;
        if (is_int($floor) && $floor > $configuredMax) {
            return min(ResearcherGeminiContext::MAX_MAX_CONTEXT_ENTRIES, $floor);
        }

        if ($postedMax !== null && $postedMax !== '') {
            $v = (int)$postedMax;
            if ($v >= ResearcherGeminiContext::MIN_MAX_CONTEXT_ENTRIES) {
                return min(ResearcherGeminiContext::MAX_MAX_CONTEXT_ENTRIES, max($configuredMax, $v));
            }
        }

        return $configuredMax;
    }

    public function parseItemCount(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT_ITEM_COUNT;
        }
        $v = (int)$raw;
        return in_array($v, self::ALLOWED_ITEM_COUNTS, true) ? $v : self::DEFAULT_ITEM_COUNT;
    }

    public function persistMaxContextEntries(PDO $pdo, mixed $raw, array $filters = []): void
    {
        if (!($filters['customAdvanced'] ?? false)) {
            return;
        }
        if ($raw === null || $raw === '') {
            return;
        }
        $configured = (new ResearcherGeminiContext(new SystemConfigRepository($pdo)))->maxContextEntries();
        $v = $this->resolveMaxContextEntriesForRequest($filters, $raw, $configured);
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
        $poolPriority = (string)($filters['poolPriority'] ?? SeismogrammPresetProfile::POOL_PRIORITY_HIGHEST);
        $this->sortEntriesForPoolPriority($gatherer, $entries, $scoresByKey, $poolPriority);
        $entries = $gatherer->filterByModuleSelection($entries, $filters['selection']);

        $geminiContext = new ResearcherGeminiContext(new SystemConfigRepository($pdo));
        $maxCap = (int)($filters['maxContextEntries'] ?? $geminiContext->maxContextEntries());
        $entriesEligibleBeforeCap = count($entries);
        $capped        = ResearcherGeminiContext::capEntryListStratified(
            $entries,
            $maxCap,
            $scoresByKey,
            $gatherer,
            $filters['selection'],
            $poolPriority,
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
                . $maxCap
                . ', '
                . $entriesEligibleBeforeCap . ' eligible before cap'
                . ($stratifiedCap
                    ? '; fair share per enabled source module, then ' . $this->describePoolPriority($poolPriority)
                    : '; ' . $this->describePoolPriority($poolPriority))
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
            'maxContextEntries'        => $maxCap,
            'formatterMeta'            => $gatherMeta,
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
        $picked = array_flip($modules);

        return ResearcherSourceSelection::forModules(
            isset($picked['feeds']),
            isset($picked['media']),
            isset($picked['scraper']),
            isset($picked['email']),
            isset($picked['newsletter']),
            isset($picked['lex']),
            isset($picked['leg']),
            false, // lexCh
            isset($picked['mem'])
        );
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

    /**
     * @param list<array<string, mixed>> $entries
     * @param array<string, array<string, mixed>> $scoresByKey
     */
    private function sortEntriesForPoolPriority(
        ResearcherEntryGatherer $gatherer,
        array &$entries,
        array $scoresByKey,
        string $poolPriority,
    ): void {
        if ($poolPriority === SeismogrammPresetProfile::POOL_PRIORITY_NEWEST) {
            $gatherer->sortByPublishedDateDesc($entries);

            return;
        }

        $gatherer->sortByRelevanceDesc($entries, $scoresByKey);
    }

    private function describePoolPriority(string $poolPriority): string
    {
        return $poolPriority === SeismogrammPresetProfile::POOL_PRIORITY_NEWEST
            ? 'newest first (then relevance)'
            : 'highest relevance, then newest';
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
