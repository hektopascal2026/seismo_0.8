<?php
/**
 * SQL-only repository for the local `entry_scores` table.
 *
 * `entry_scores` holds both Magnitu (ML) and recipe (deterministic local) scores
 * for every scored entry. The table is ALWAYS local — never wrapped in
 * {@see entryTable()} — so satellites keep their own score state even though
 * they read entries cross-DB from the mothership.
 *
 * Precedence rule encoded in the UPSERT (unchanged from 0.4): once a row is
 * marked `score_source = 'magnitu'`, subsequent `'recipe'` writes keep the
 * Magnitu score and model_version. Magnitu scores overwrite recipe scores
 * freely. This lets the recipe engine backfill rows while Magnitu is offline
 * without ever clobbering newer ML output on reconnect.
 */

declare(strict_types=1);

namespace Seismo\Repository;

use PDO;
use PDOException;

final class EntryScoreRepository
{
    /** Entry types Magnitu may push scores for (includes Leg). */
    public const MAGNITU_ENTRY_TYPES = ['feed_item', 'email', 'lex_item', 'calendar_event'];

    /** All entry types that can carry a score in `entry_scores`. */
    public const ALL_ENTRY_TYPES = ['feed_item', 'email', 'lex_item', 'calendar_event'];

    /**
     * Upper cap on how many "unscored" rows one family query may return.
     * Higher than the standard 200-row cap used elsewhere because rescoring
     * is a service-side batch job (not a user-facing list) and pays the
     * memory in exchange for fewer refresh cycles to catch up.
     */
    public const MAX_UNSCORED_LIMIT = 500;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Upsert a Magnitu-sourced score. Always marks `score_source = 'magnitu'`.
     *
     * @param array<string, mixed>|null $explanation JSON-encoded before storage.
     * @return 'inserted'|'updated'
     */
    public function upsertMagnituScore(
        string $entryType,
        int $entryId,
        float $relevanceScore,
        ?string $predictedLabel,
        ?array $explanation,
        int $modelVersion,
    ): string {
        $stmt = $this->pdo->prepare(
            "INSERT INTO entry_scores
                 (entry_type, entry_id, relevance_score, predicted_label, explanation, score_source, model_version)
             VALUES (?, ?, ?, ?, ?, 'magnitu', ?)
             ON DUPLICATE KEY UPDATE
                 relevance_score = VALUES(relevance_score),
                 predicted_label = VALUES(predicted_label),
                 explanation     = VALUES(explanation),
                 score_source    = 'magnitu',
                 model_version   = VALUES(model_version)"
        );
        $stmt->execute([
            $entryType,
            $entryId,
            $relevanceScore,
            $predictedLabel,
            $explanation === null ? null : json_encode($explanation, JSON_UNESCAPED_UNICODE),
            $modelVersion,
        ]);

        // MariaDB returns 1 on INSERT, 2 on UPDATE for ON DUPLICATE KEY UPDATE.
        return $stmt->rowCount() === 1 ? 'inserted' : 'updated';
    }

    /**
     * Upsert a recipe-sourced score. Preserves a prior Magnitu score / model_version
     * (see class docblock for precedence rule).
     *
     * @param array<string, mixed>|null $explanation JSON-encoded before storage.
     */
    public function upsertRecipeScore(
        string $entryType,
        int $entryId,
        float $relevanceScore,
        ?string $predictedLabel,
        ?array $explanation,
        int $modelVersion,
    ): void {
        // Precedence rule: a Magnitu score (score_source = 'magnitu') wins
        // over any recipe rescore, full row. All columns are guarded — not
        // just `score_source` / `model_version` — otherwise a recipe run
        // would silently overwrite the Magnitu-assigned relevance_score,
        // predicted_label, and explanation while keeping the 'magnitu' tag,
        // which is worse than either outcome on its own.
        $stmt = $this->pdo->prepare(
            "INSERT INTO entry_scores
                 (entry_type, entry_id, relevance_score, predicted_label, explanation, score_source, model_version)
             VALUES (?, ?, ?, ?, ?, 'recipe', ?)
             ON DUPLICATE KEY UPDATE
                 relevance_score = IF(score_source = 'magnitu', relevance_score, VALUES(relevance_score)),
                 predicted_label = IF(score_source = 'magnitu', predicted_label, VALUES(predicted_label)),
                 explanation     = IF(score_source = 'magnitu', explanation,     VALUES(explanation)),
                 score_source    = IF(score_source = 'magnitu', score_source,    'recipe'),
                 model_version   = IF(score_source = 'magnitu', model_version,   VALUES(model_version))"
        );
        $stmt->execute([
            $entryType,
            $entryId,
            $relevanceScore,
            $predictedLabel,
            $explanation === null ? null : json_encode($explanation, JSON_UNESCAPED_UNICODE),
            $modelVersion,
        ]);
    }

    /**
     * Counts used by `?action=magnitu_status` and the future diagnostics polish.
     *
     * @return array{total:int, magnitu:int, recipe:int}
     */
    public function getScoreCounts(): array
    {
        try {
            $total = (int)$this->pdo->query(
                'SELECT COUNT(*) FROM entry_scores'
            )->fetchColumn();
            $magnitu = (int)$this->pdo->query(
                "SELECT COUNT(*) FROM entry_scores WHERE score_source = 'magnitu'"
            )->fetchColumn();
            $recipe = (int)$this->pdo->query(
                "SELECT COUNT(*) FROM entry_scores WHERE score_source = 'recipe'"
            )->fetchColumn();
        } catch (PDOException $e) {
            return ['total' => 0, 'magnitu' => 0, 'recipe' => 0];
        }

        return ['total' => $total, 'magnitu' => $magnitu, 'recipe' => $recipe];
    }

    /**
     * Wipe every row. Used by the "Clear scores" admin action (port of 0.4's
     * `handleClearMagnituScores`). Caller is responsible for also clearing
     * recipe config in `magnitu_config` where applicable.
     */
    public function clearAll(): int
    {
        try {
            return (int)$this->pdo->exec('DELETE FROM entry_scores');
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * Batch-read score rows for Leg / diagnostics-style callers.
     *
     * @param array<int, array{0: string, 1: int}> $pairs (entry_type, entry_id)
     *
     * @return array<string, array<string, mixed>> keyed by `entry_type:entry_id`
     */
    public function fetchScoresIndexedByPairs(array $pairs): array
    {
        $pairs = array_values(array_filter(
            $pairs,
            static fn (array $p): bool => isset($p[0], $p[1]) && $p[1] > 0 && $p[0] !== ''
        ));
        if ($pairs === []) {
            return [];
        }
        $seen = [];
        $dedup = [];
        foreach ($pairs as $p) {
            $k = $p[0] . ':' . $p[1];
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $dedup[] = $p;
        }
        $placeholders = implode(', ', array_fill(0, count($dedup), '(?, ?)'));
        $flat         = [];
        foreach ($dedup as [$t, $id]) {
            $flat[] = $t;
            $flat[] = $id;
        }
        $sql = 'SELECT entry_type, entry_id, relevance_score, predicted_label,
                       explanation, score_source, model_version
                FROM entry_scores
                WHERE (entry_type, entry_id) IN (' . $placeholders . ')';
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($flat);
            $rows = $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
        $map = [];
        foreach ($rows as $row) {
            $map[(string)$row['entry_type'] . ':' . (int)$row['entry_id']] = $row;
        }

        return $map;
    }

    // -----------------------------------------------------------------
    // Unscored-row lookups used by ScoringService.
    //
    // These queries cross-join local `entry_scores` with the entry-source
    // tables (which `entryTable()` satellite-wraps). Both live on the same
    // MariaDB server in any supported deployment (mothership, or satellite
    // whose default catalog is `seismo_<slug>` while entries live in `seismo`),
    // so a single PDO connection suffices.
    //
    // Each method returns a flat array of rows with the exact columns
    // ScoringService needs to call RecipeScorer::score(). No HTML
    // escaping, no formatting — raw DB values (per core-plugin-architecture).
    // -----------------------------------------------------------------

    /**
     * Feed items (RSS / Substack / Scraper) that do not yet carry a
     * Magnitu-sourced row in `entry_scores`. Joined against `feeds` to pull
     * `source_type` and to skip disabled feeds in the same pass.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUnscoredFeedItems(int $limit): array
    {
        $limit = $this->clampLimit($limit);
        $sql = 'SELECT fi.id, fi.title, fi.description, fi.content, f.source_type
                  FROM ' . entryTable('feed_items') . ' fi
                  JOIN ' . entryTable('feeds') . ' f ON fi.feed_id = f.id
                 WHERE f.disabled = 0
                   AND fi.hidden = 0
                   AND NOT EXISTS (
                       SELECT 1 FROM entry_scores es
                        WHERE es.entry_type = \'feed_item\'
                          AND es.entry_id = fi.id
                          AND es.score_source = \'magnitu\'
                   )
                 ORDER BY fi.id DESC
                 LIMIT ' . $limit;

        return $this->runOrEmpty($sql, 'getUnscoredFeedItems');
    }

    /**
     * Lex items (any source — Fedlex, EU, Légifrance, …) without a Magnitu
     * score. `description` is returned so {@see ScoringService::rescoreLexItems()}
     * can use it as the content body where the plugin populated one
     * (Légifrance `resumePrincipal`, RechtBund RSS description, Fedlex draft
     * consultations, EU EuroVoc subjects). `document_type` is the legacy
     * fallback when description is null — Fedlex consolidated acts and Jus
     * decisions still have no stored body.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUnscoredLexItems(int $limit): array
    {
        $limit = $this->clampLimit($limit);
        $sql = 'SELECT li.id, li.title, li.description, li.document_type, li.source
                  FROM ' . entryTable('lex_items') . ' li
                 WHERE NOT EXISTS (
                       SELECT 1 FROM entry_scores es
                        WHERE es.entry_type = \'lex_item\'
                          AND es.entry_id = li.id
                          AND es.score_source = \'magnitu\'
                   )
                 ORDER BY li.id DESC
                 LIMIT ' . $limit;

        return $this->runOrEmpty($sql, 'getUnscoredLexItems');
    }

    /**
     * Emails without a Magnitu score. The 0.4 schema left the text/HTML
     * column names inconsistent (`text_body` vs `body_text`, `html_body`
     * vs `body_html`), so we probe INFORMATION_SCHEMA on the entry DB and
     * quietly return `[]` if neither pair is present — scoring then no-ops
     * on this family rather than blowing up the refresh cycle.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUnscoredEmails(int $limit): array
    {
        $limit = $this->clampLimit($limit);
        $table = entryTable(getEmailTableName());

        try {
            $stmt = $this->pdo->prepare(
                'SELECT COLUMN_NAME
                   FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = ' . entryDbSchemaExpr() . '
                    AND TABLE_NAME   = ?'
            );
            $stmt->execute([getEmailTableName()]);
            $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            error_log('EntryScoreRepository getUnscoredEmails column probe: ' . $e->getMessage());
            return [];
        }

        $textBody = $this->pickColumn($cols, ['text_body', 'body_text']);
        $htmlBody = $this->pickColumn($cols, ['html_body', 'body_html']);
        if ($textBody === null || $htmlBody === null) {
            return [];
        }

        $derivedCol = $this->pickColumn($cols, ['derived_title']);
        $derivedSel = $derivedCol !== null ? ', e.`' . $derivedCol . '` AS derived_title' : '';

        $hiddenClause = in_array('hidden', $cols, true) ? 'e.hidden = 0 AND ' : '';

        $sql = "SELECT e.id, e.subject,
                       e.`{$textBody}` AS text_body,
                       e.`{$htmlBody}` AS html_body{$derivedSel}
                  FROM {$table} e
                 WHERE {$hiddenClause}NOT EXISTS (
                       SELECT 1 FROM entry_scores es
                        WHERE es.entry_type = 'email'
                          AND es.entry_id  = e.id
                          AND es.score_source = 'magnitu'
                   )
                 ORDER BY e.id DESC
                 LIMIT {$limit}";

        return $this->runOrEmpty($sql, 'getUnscoredEmails');
    }

    /**
     * Calendar events (Leg) without a Magnitu score (candidates for recipe
     * rescoring until Magnitu overwrites).
     *
     * Newest-first order matches the sibling unscored queries
     * ({@see getUnscoredFeedItems()}, {@see getUnscoredLexItems()},
     * {@see getUnscoredEmails()}) — without it MariaDB returns InnoDB rows in
     * PK order (ascending), so a backlog larger than {@see self::MAX_UNSCORED_LIMIT}
     * unscored Leg rows would keep the newest events out of the recipe
     * rescore batch until older rows acquire a Magnitu score.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUnscoredCalendarEvents(int $limit): array
    {
        $limit = $this->clampLimit($limit);
        $sql = 'SELECT ce.id, ce.title, ce.description, ce.content, ce.source, ce.event_type
                  FROM ' . entryTable('calendar_events') . ' ce
                 WHERE NOT EXISTS (
                       SELECT 1 FROM entry_scores es
                        WHERE es.entry_type = \'calendar_event\'
                          AND es.entry_id  = ce.id
                          AND es.score_source = \'magnitu\'
                   )
                 ORDER BY ce.id DESC
                 LIMIT ' . $limit;

        return $this->runOrEmpty($sql, 'getUnscoredCalendarEvents');
    }

    private function clampLimit(int $limit): int
    {
        return max(1, min($limit, self::MAX_UNSCORED_LIMIT));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function runOrEmpty(string $sql, string $context): array
    {
        try {
            $stmt = $this->pdo->query($sql);
            return $stmt === false ? [] : $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('EntryScoreRepository ' . $context . ' failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * @param array<int, string> $cols
     * @param array<int, string> $preference
     */
    private function pickColumn(array $cols, array $preference): ?string
    {
        foreach ($preference as $p) {
            if (in_array($p, $cols, true)) {
                return $p;
            }
        }
        return null;
    }
}
