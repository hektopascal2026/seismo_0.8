<?php
/**
 * Translates retention keep-predicate tokens into SQL `EXISTS (…)`
 * fragments scoped to a given entry_type.
 *
 * This is the **only** place in the codebase that maps the policy
 * vocabulary used by {@see RetentionService} (KEEP_FAVOURITED,
 * KEEP_HIGH_SCORE, KEEP_LABELLED) to concrete SQL. Family repositories
 * call {@see self::forEntryType()} from inside `prune()` / `dryRunPrune()`
 * so the "preview matches actual" invariant survives any future keep
 * rule additions — both sides go through the same builder.
 *
 * The fragments are inert strings; no PDO, no execution. Repositories
 * own query execution, this class owns the vocabulary-to-SQL mapping.
 *
 * Why it lives under `Service\` and not `Repository\`:
 * `RetentionPredicates` is part of the retention *policy* layer — it
 * translates the policy the service decides on into SQL that repos
 * then run. Plumbing it next to `RetentionService` keeps the two
 * co-located; the repo is still the executor.
 */

declare(strict_types=1);

namespace Seismo\Service;

final class RetentionPredicates
{
    /**
     * Whitelist of entry-type values that may appear as SQL literals
     * inside a keep-predicate EXISTS fragment. Must stay in lockstep
     * with the ENUM in `entry_scores.entry_type` /
     * `entry_favourites.entry_type` / `magnitu_labels.entry_type`.
     * Interpolating any of these is safe because the values are
     * hardcoded strings, never user input.
     */
    private const ALLOWED_ENTRY_TYPES = [
        'feed_item'      => true,
        'email'          => true,
        'lex_item'       => true,
        'calendar_event' => true,
    ];

    /**
     * Resolve keep-tokens into a parenthesis-less OR'd fragment, e.g.
     * `EXISTS (… favourites …) OR EXISTS (… scores …)`. Returns the
     * empty string if the token list is empty or contains only unknown
     * tokens — the caller skips the `AND NOT (…)` wrap in that case
     * so `prune()` doesn't silently delete what the policy wanted
     * protected.
     *
     * IMPORTANT: the alias `t` is assumed to be the family table the
     * caller is pruning; every fragment references `t.id`. Callers
     * must use `FROM <table> t` (or `DELETE FROM <table> t`) so the
     * correlation works.
     *
     * Entry-type names match the ENUM in `entry_scores` /
     * `entry_favourites` / `magnitu_labels`. Pass the singular form:
     * 'feed_item', 'email', 'lex_item', 'calendar_event'. Any other
     * value raises an `InvalidArgumentException` — this closes the
     * `addslashes`-on-a-SQL-literal footgun evicted from the rest of
     * the codebase in Slice 5. The EXISTS subqueries can't accept a
     * bound parameter here because the fragment is concatenated into
     * the caller's own prepared statement without rewiring its
     * parameter list, so a compile-time whitelist is the next-best
     * thing.
     *
     * @param list<string> $tokens {@see RetentionService::KEEP_*}
     */
    public static function forEntryType(string $entryType, array $tokens): string
    {
        if ($tokens === []) {
            return '';
        }
        if (!isset(self::ALLOWED_ENTRY_TYPES[$entryType])) {
            throw new \InvalidArgumentException(
                'RetentionPredicates::forEntryType: unknown entry type '
                . var_export($entryType, true)
                . '. Must be one of: '
                . implode(', ', array_keys(self::ALLOWED_ENTRY_TYPES))
            );
        }

        $fragments = [];
        foreach (array_unique($tokens) as $token) {
            switch ($token) {
                case RetentionService::KEEP_FAVOURITED:
                    $fragments[] = "EXISTS (SELECT 1 FROM entry_favourites ef "
                                 . "WHERE ef.entry_type = '{$entryType}' AND ef.entry_id = t.id)";
                    break;

                case RetentionService::KEEP_HIGH_SCORE:
                    $fragments[] = "EXISTS (SELECT 1 FROM entry_scores es "
                                 . "WHERE es.entry_type = '{$entryType}' AND es.entry_id = t.id "
                                 . "AND es.predicted_label IN ('investigation_lead','important'))";
                    break;

                case RetentionService::KEEP_LABELLED:
                    $fragments[] = "EXISTS (SELECT 1 FROM magnitu_labels ml "
                                 . "WHERE ml.entry_type = '{$entryType}' AND ml.entry_id = t.id)";
                    break;

                // Unknown tokens are ignored (forward-compat).
            }
        }

        return implode(' OR ', $fragments);
    }
}
