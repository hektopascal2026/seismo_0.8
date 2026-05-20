<?php
/**
 * Orchestrates recipe-based rescoring across every entry family.
 *
 * The service is stateless beyond its injected dependencies and holds NO
 * SQL — unscored-row lookups live in {@see EntryScoreRepository}, score
 * writes live in the same repository. A single {@see rescoreAll()} call
 * corresponds to what 0.4's `magnituRescore()` did:
 *
 *   1. Ask {@see EntryScoreRepository} for feed_items, lex_items, emails,
 *      calendar_events that do NOT yet carry a `score_source = 'magnitu'`
 *      row (per-family methods, bounded by {@see self::BATCH_LIMIT}).
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
        $rows = $this->scores->getUnscoredFeedItems(self::BATCH_LIMIT);
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
     * Lex items rarely carry a real body in `lex_items` (no full document text
     * stored), so we prefer `description` when the fetcher populated one:
     * Légifrance `resumePrincipal`, RechtBund RSS body, Fedlex draft consultation
     * lines, and EU EuroVoc subject synopsis. Falls back to the categorical
     * `document_type` string for sources that still don't fill description
     * (Fedlex consolidated acts, Jus). Pre-existing recipe scores are not
     * touched — only rows surfaced by {@see EntryScoreRepository::getUnscoredLexItems()}.
     */
    private function rescoreLexItems(array $recipe, int $version): int
    {
        $rows = $this->scores->getUnscoredLexItems(self::BATCH_LIMIT);
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
        $rows = $this->scores->getUnscoredEmails(self::BATCH_LIMIT);
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
            if ($body !== '' && !empty($ui['strip_listing_boilerplate'])) {
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
        $rows = $this->scores->getUnscoredCalendarEvents(self::BATCH_LIMIT);
        $done = 0;
        foreach ($rows as $row) {
            $body = (string)(($row['content'] !== null && $row['content'] !== '')
                ? $row['content']
                : ($row['description'] ?? ''));
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
            $raw = $systemConfig->get('recipe_json');
            if ($raw === null || $raw === '') {
                return;
            }
            $recipe = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($recipe)) {
                return;
            }
            $scorer = new self($entryScores);
            $scorer->rescoreAll($recipe);
        } catch (\Throwable $e) {
            error_log('Seismo recipe rescore after refresh: ' . $e->getMessage());
        }
    }
}
