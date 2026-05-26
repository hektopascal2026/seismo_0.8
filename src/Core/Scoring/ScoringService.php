<?php
/**
 * Orchestrates recipe-based rescoring across every entry family.
 *
 * The service is stateless beyond its injected dependencies and holds NO
 * SQL — unscored-row lookups live in {@see EntryScoreRepository}, score
 * writes live in the same repository. A single {@see rescoreAll()} call
 * corresponds to what 0.4's `magnituRescore()` did:
 *
 *   1. Ask {@see EntryScoreRepository} for rows missing Magnitu and missing a
 *      recipe score for the active recipe version (per-family methods, bounded
 *      by {@see self::BATCH_LIMIT}).
 *   2. Compute the recipe score via {@see RecipeScorer::score()} — a pure
 *      function with no I/O.
 *   3. Upsert via {@see EntryScoreRepository::upsertRecipeScore()} — which
 *      preserves any prior Magnitu score per the precedence rule.
 *
 * The per-family batch size is capped at {@see self::BATCH_LIMIT}. Larger
 * fleets are handled by subsequent refresh cycles; this keeps memory
 * bounded on shared hosts and avoids long-running request timeouts for
 * the `magnitu_recipe` POST.
 *
 * Leg (`calendar_event`) is rescored here for recipe backfill until Magnitu
 * overwrites with ML scores.
 */

declare(strict_types=1);

namespace Seismo\Core\Scoring;

use PDO;
use PDOException;
use Seismo\Core\Mail\EmailListingBoilerplateStripper;
use Seismo\Repository\EmailSubscriptionRepository;
use Seismo\Repository\EntryScoreRepository;
use Seismo\Repository\SystemConfigRepository;

final class ScoringService
{
    /**
     * Max rows rescored per family per `rescoreAll()` call. Must stay
     * <= {@see EntryScoreRepository::MAX_UNSCORED_LIMIT}; the repo clamps
     * silently if we ever ask for more, so the two stay in sync even if
     * someone edits only one.
     */
    public const BATCH_LIMIT = 500;

    public function __construct(
        private EntryScoreRepository $scores,
    ) {
    }

    /**
     * @param array<string, mixed> $recipe Decoded recipe JSON (keywords, …).
     * @return array{feed_items:int, lex_items:int, emails:int, calendar_events:int}
     */
    public function rescoreAll(array $recipe): array
    {
        if ($recipe === [] || empty($recipe['keywords'])) {
            return ['feed_items' => 0, 'lex_items' => 0, 'emails' => 0, 'calendar_events' => 0];
        }

        $version = (int)($recipe['version'] ?? 0);

        return [
            'feed_items'      => $this->rescoreFeedItems($recipe, $version),
            'lex_items'       => $this->rescoreLexItems($recipe, $version),
            'emails'          => $this->rescoreEmails($recipe, $version),
            'calendar_events' => $this->rescoreCalendarEvents($recipe, $version),
        ];
    }

    /**
     * @param array<string, mixed> $recipe
     */
    private function rescoreFeedItems(array $recipe, int $version): int
    {
        $rows = $this->scores->getUnscoredFeedItems(self::BATCH_LIMIT, $version);
        $done = 0;
        foreach ($rows as $row) {
            $st = (string)($row['source_type'] ?? 'rss');
            $sourceType = in_array($st, ['substack', 'scraper'], true) ? $st : 'rss';
            $body = (string)(($row['content'] !== null && $row['content'] !== '')
                ? $row['content']
                : ($row['description'] ?? ''));

            $result = RecipeScorer::score($recipe, (string)($row['title'] ?? ''), $body, $sourceType);
            if ($result === null) {
                continue;
            }
            if ($this->writeScore('feed_item', (int)$row['id'], $result, $version)) {
                $done++;
            }
        }
        return $done;
    }

    /**
     * @param array<string, mixed> $recipe
     *
     * Lex: recipe scoring uses `description` (synopsis) only — full document text
     * lives in `content` for Magnitu export once fetchers populate it.
     */
    private function rescoreLexItems(array $recipe, int $version): int
    {
        $rows = $this->scores->getUnscoredLexItems(self::BATCH_LIMIT, $version);
        $done = 0;
        foreach ($rows as $row) {
            $sourceType = 'lex_' . (string)($row['source'] ?? 'eu');
            $body = trim((string)($row['description'] ?? ''));
            if ($body === '') {
                $body = (string)($row['document_type'] ?? '');
            }
            $result = RecipeScorer::score(
                $recipe,
                (string)($row['title'] ?? ''),
                $body,
                $sourceType,
            );
            if ($result === null) {
                continue;
            }
            if ($this->writeScore('lex_item', (int)$row['id'], $result, $version)) {
                $done++;
            }
        }
        return $done;
    }

    /**
     * @param array<string, mixed> $recipe
     */
    private function rescoreEmails(array $recipe, int $version): int
    {
        $rows = $this->scores->getUnscoredEmails(self::BATCH_LIMIT, $version);
        $done   = 0;
        $subs   = (new EmailSubscriptionRepository(\getDbConnection()))->listActive(EmailSubscriptionRepository::MAX_LIMIT, 0);
        foreach ($rows as $row) {
            $body = (string)($row['text_body'] ?? '');
            if ($body === '') {
                $body = strip_tags((string)($row['html_body'] ?? ''));
            }
            $subject = (string)($row['subject'] ?? '');
            $scoreTitle = trim((string)($row['derived_title'] ?? ''));
            if ($scoreTitle === '') {
                $scoreTitle = $subject;
            }
            $ui      = EmailSubscriptionRepository::resolveSubscriptionUiForFromEmail((string)($row['from_email'] ?? ''), $subs);
            if ($body !== '' && \Seismo\Core\Mail\EmailListingBoilerplatePolicy::shouldStrip($ui)) {
                $body = EmailListingBoilerplateStripper::strip($body, $subject !== '' ? $subject : null);
            }
            $result = RecipeScorer::score($recipe, $scoreTitle, $body, 'email');
            if ($result === null) {
                continue;
            }
            if ($this->writeScore('email', (int)$row['id'], $result, $version)) {
                $done++;
            }
        }
        return $done;
    }

    /**
     * @param array<string, mixed> $recipe
     */
    private function rescoreCalendarEvents(array $recipe, int $version): int
    {
        $rows = $this->scores->getUnscoredCalendarEvents(self::BATCH_LIMIT, $version);
        $done = 0;
        foreach ($rows as $row) {
            // Recipe scoring uses synopsis only — full `content` is for Magnitu export.
            $body = trim((string)($row['description'] ?? ''));
            if ($body === '') {
                $body = (string)($row['event_type'] ?? '');
            }
            $sourceType = 'calendar_' . (string)($row['source'] ?? 'unknown');
            $result = RecipeScorer::score($recipe, (string)($row['title'] ?? ''), $body, $sourceType);
            if ($result === null) {
                continue;
            }
            if ($this->writeScore('calendar_event', (int)$row['id'], $result, $version)) {
                $done++;
            }
        }
        return $done;
    }

    /**
     * @param array{relevance_score: float, predicted_label: string, explanation: array<string, mixed>|null} $result
     */
    private function writeScore(string $entryType, int $entryId, array $result, int $version): bool
    {
        try {
            $this->scores->upsertRecipeScore(
                $entryType,
                $entryId,
                (float)$result['relevance_score'],
                (string)$result['predicted_label'],
                $result['explanation'],
                $version,
            );
            return true;
        } catch (PDOException $e) {
            error_log('ScoringService ' . $entryType . ' rescore: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Load `recipe_json` from system_config and run {@see rescoreAll()} — best-effort;
     * logs errors. Safe for satellites (unlike {@see RefreshAllService}, pruned from bundles).
     */
    public static function rescoreStoredRecipeBestEffort(PDO $pdo): void
    {
        self::rescoreStoredRecipeBestEffortForRepos(
            new SystemConfigRepository($pdo),
            new EntryScoreRepository($pdo),
        );
    }

    /**
     * Same as {@see rescoreStoredRecipeBestEffort()}, reusing repo instances (mothership ingest).
     *
     * @internal
     */
    public static function rescoreStoredRecipeBestEffortForRepos(
        SystemConfigRepository $systemConfig,
        EntryScoreRepository $entryScores,
    ): void {
        try {
            self::rescoreStoredRecipeRoundsForRepos($systemConfig, $entryScores, 1);
        } catch (\Throwable $e) {
            error_log('Seismo recipe rescore after refresh: ' . $e->getMessage());
        }
    }

    /**
     * Run {@see rescoreAll()} in a loop until a pass scores nothing or {@see $maxRounds}.
     *
     * @return array{counts: array{feed_items: int, lex_items: int, emails: int, calendar_events: int}, rounds: int}|null
     *                                                                                                      null when no recipe_json
     * @throws \JsonException
     */
    public static function rescoreStoredRecipeRoundsForRepos(
        SystemConfigRepository $systemConfig,
        EntryScoreRepository $entryScores,
        int $maxRounds = 20,
    ): ?array {
        $raw = $systemConfig->get('recipe_json');
        if ($raw === null || $raw === '') {
            return null;
        }
        $recipe = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($recipe) || $recipe === [] || empty($recipe['keywords'])) {
            return null;
        }

        $maxRounds = max(1, min($maxRounds, 50));
        $scorer    = new self($entryScores);
        $totals    = ['feed_items' => 0, 'lex_items' => 0, 'emails' => 0, 'calendar_events' => 0];
        $rounds    = 0;

        for ($i = 0; $i < $maxRounds; $i++) {
            $counts = $scorer->rescoreAll($recipe);
            $rounds++;
            $batchTotal = 0;
            foreach ($counts as $family => $n) {
                $totals[$family] += $n;
                $batchTotal += $n;
            }
            if ($batchTotal === 0) {
                break;
            }
        }

        return ['counts' => $totals, 'rounds' => $rounds];
    }

    /**
     * @return array{counts: array{feed_items: int, lex_items: int, emails: int, calendar_events: int}, rounds: int}|null
     */
    public static function rescoreStoredRecipeRounds(PDO $pdo, int $maxRounds = 20): ?array
    {
        return self::rescoreStoredRecipeRoundsForRepos(
            new SystemConfigRepository($pdo),
            new EntryScoreRepository($pdo),
            $maxRounds,
        );
    }
}
