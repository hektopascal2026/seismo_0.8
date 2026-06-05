<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

/**
 * Visibility rules for split digest emails outside the Mail admin timeline.
 *
 * Mail module keeps the parent digest card with nested {@see attachEmailChildStories()}.
 * Highlights, Magnitu export, Researcher, and recipe rescoring must surface individual
 * story rows only — hide a parent when it has visible child emails.
 */
final class EmailDigestExportPolicy
{
    /**
     * SQL predicate: include email row `$alias` when it is a child story or has no
     * visible children (unsplit / split produced zero rows).
     */
    public static function sqlExportableEmail(string $alias = 'e'): string
    {
        $table = entryTable('emails');

        return "(
            {$alias}.parent_email_id IS NOT NULL
            OR NOT EXISTS (
                SELECT 1 FROM {$table} c
                 WHERE c.parent_email_id = {$alias}.id
                   AND c.hidden = 0
            )
        )";
    }

    /**
     * SQL fragment for {@see entry_scores} queries: drop parent digest scores when
     * visible child story rows exist for that email id.
     */
    public static function sqlScoreRowExcludesDigestParents(string $esAlias = 'es'): string
    {
        $table = entryTable('emails');

        return " AND (
            {$esAlias}.entry_type != 'email'
            OR EXISTS (
                SELECT 1 FROM {$table} e
                 WHERE e.id = {$esAlias}.entry_id
                   AND e.hidden = 0
                   AND " . self::sqlExportableEmail('e') . '
            )
        )';
    }
}
