<?php
/**
 * Soft-dismiss (hide) for timeline entries — mail and feed_items (RSS/scraper).
 *
 * Sets `hidden = 1` on the entry row. Ingest upserts must not clear the flag.
 */

declare(strict_types=1);

namespace Seismo\Repository;

use PDO;
use PDOException;

final class EntryHiddenRepository
{
    /** @var list<string> */
    public const ALLOWED_ENTRY_TYPES = ['feed_item', 'email'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Hide one entry. Idempotent when already hidden.
     *
     * @throws PDOException
     */
    public function hide(string $entryType, int $entryId): bool
    {
        if (!in_array($entryType, self::ALLOWED_ENTRY_TYPES, true) || $entryId <= 0) {
            return false;
        }

        $table = match ($entryType) {
            'feed_item' => entryTable('feed_items'),
            'email'     => entryTable('emails'),
            default     => throw new \InvalidArgumentException('Unsupported entry_type'),
        };

        $stmt = $this->pdo->prepare(
            'UPDATE ' . $table . ' SET hidden = 1 WHERE id = ? AND hidden = 0'
        );
        $stmt->execute([$entryId]);
        if ($stmt->rowCount() > 0) {
            $this->removeFavourite($entryType, $entryId);

            return true;
        }

        return false;
    }

    private function removeFavourite(string $entryType, int $entryId): void
    {
        try {
            $del = $this->pdo->prepare(
                'DELETE FROM entry_favourites WHERE entry_type = ? AND entry_id = ?'
            );
            $del->execute([$entryType, $entryId]);
        } catch (PDOException $e) {
            if (!PdoMysqlDiagnostics::isMissingTable($e)) {
                throw $e;
            }
        }
    }
}
