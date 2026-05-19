<?php
/**
 * Migration 008 — allow duplicate `feeds.url` (schema 24).
 *
 * Two `parl_press` sources (e.g. Medienmitteilungen + SDA) intentionally share
 * the same SharePoint `…/items` endpoint; options differ in `description` JSON.
 * The legacy UNIQUE on `url` blocked the second row with SQLSTATE 1062.
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;

final class Migration008FeedsUrlNonUnique
{
    public const VERSION = 24;

    public function apply(PDO $pdo): void
    {
        $uniqueKey = $this->findUniqueIndexNameOnFeedsUrl($pdo);
        if ($uniqueKey !== null) {
            $pdo->exec('ALTER TABLE feeds DROP INDEX `' . str_replace('`', '``', $uniqueKey) . '`');
        }
        $this->ensureNonUniqueUrlIndex($pdo);
    }

    private function findUniqueIndexNameOnFeedsUrl(PDO $pdo): ?string
    {
        try {
            $stmt = $pdo->query(
                "SHOW INDEX FROM feeds WHERE Column_name = 'url' AND Non_unique = 0 AND Key_name <> 'PRIMARY'"
            );
            if ($stmt === false) {
                return null;
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return null;
        }
        if ($row === false || $row === null) {
            return null;
        }
        $name = (string)($row['Key_name'] ?? '');

        return $name !== '' ? $name : null;
    }

    private function ensureNonUniqueUrlIndex(PDO $pdo): void
    {
        try {
            $stmt = $pdo->query("SHOW INDEX FROM feeds WHERE Key_name = 'idx_url'");
            if ($stmt !== false && $stmt->fetch(PDO::FETCH_ASSOC) !== false) {
                return;
            }
        } catch (PDOException) {
            // fall through to ADD INDEX attempt
        }
        try {
            $pdo->exec('ALTER TABLE feeds ADD INDEX idx_url (url)');
        } catch (PDOException) {
            // If idx_url already exists under another name or table differs, ignore.
        }
    }
}
