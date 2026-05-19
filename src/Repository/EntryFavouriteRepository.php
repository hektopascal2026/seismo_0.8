<?php
/**
 * Star / bookmark state for timeline entries.
 *
 * Backed by the local `entry_favourites` table only — never pass through
 * entryTable(); satellites keep their own favourites on the local DB.
 */

declare(strict_types=1);

namespace Seismo\Repository;

use PDO;
use PDOException;

final class EntryFavouriteRepository
{
    /** @var list<string> Single source of truth — add new entry families here only. */
    public const ALLOWED_ENTRY_TYPES = ['feed_item', 'email', 'lex_item', 'calendar_event'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Flip favourite state atomically: DELETE first; if no row removed, INSERT.
     * Returns the new starred state (true = now in favourites).
     *
     * @throws PDOException On real DB failures (missing table, etc.) — not swallowed.
     */
    public function toggle(string $entryType, int $entryId): bool
    {
        if (!in_array($entryType, self::ALLOWED_ENTRY_TYPES, true) || $entryId <= 0) {
            return false;
        }

        try {
            $del = $this->pdo->prepare(
                'DELETE FROM entry_favourites WHERE entry_type = ? AND entry_id = ?'
            );
            $del->execute([$entryType, $entryId]);
            if ($del->rowCount() > 0) {
                return false;
            }

            $ins = $this->pdo->prepare(
                'INSERT INTO entry_favourites (entry_type, entry_id) VALUES (?, ?)'
            );
            $ins->execute([$entryType, $entryId]);
            return true;
        } catch (PDOException $e) {
            // Concurrent double-star: duplicate key — row already exists, treat as starred.
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                return true;
            }
            throw $e;
        }
    }
}
