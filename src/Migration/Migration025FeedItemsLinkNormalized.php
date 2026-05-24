<?php
/**
 * Migration 025 — normalized article link for cross-feed dedup (schema 41).
 *
 * Topic RSS feeds (e.g. Watson Schweiz / Wirtschaft / International) often publish
 * the same URL with different guids. {@see \Seismo\Core\Fetcher\ArticleLinkNormalizer}
 * backs ingest-time and timeline deduplication.
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;
use RuntimeException;
use Seismo\Core\Fetcher\ArticleLinkNormalizer;

final class Migration025FeedItemsLinkNormalized
{
    public const VERSION = 41;

    public static function migrationScope(): MigrationScope
    {
        return MigrationScope::MothershipOnly;
    }

    public function apply(PDO $pdo, MigrationTarget $target): void
    {
        try {
            $pdo->exec(
                'ALTER TABLE feed_items
                 ADD COLUMN link_normalized VARCHAR(500) DEFAULT NULL
                 AFTER link'
            );
        } catch (PDOException $e) {
            if (!self::columnAlreadyExists($e)) {
                throw new RuntimeException('Migration025 add column: ' . $e->getMessage(), 0, $e);
            }
        }

        try {
            $pdo->exec('CREATE INDEX idx_feed_items_link_normalized ON feed_items (link_normalized(255))');
        } catch (PDOException $e) {
            if (!self::indexAlreadyExists($e)) {
                throw new RuntimeException('Migration025 add index: ' . $e->getMessage(), 1, $e);
            }
        }

        $this->backfillLinkNormalized($pdo);
        $this->hideDuplicateFeedItems($pdo);
    }

    private function backfillLinkNormalized(PDO $pdo): void
    {
        $stmt = $pdo->query(
            'SELECT id, link FROM feed_items WHERE link IS NOT NULL AND link != \'\' ORDER BY id ASC'
        );
        if ($stmt === false) {
            return;
        }

        $upd = $pdo->prepare('UPDATE feed_items SET link_normalized = ? WHERE id = ?');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $link = trim((string)($row['link'] ?? ''));
            if ($link === '') {
                continue;
            }
            $norm = ArticleLinkNormalizer::normalize($link);
            if ($norm === '') {
                continue;
            }
            $upd->execute([mb_substr($norm, 0, 500), (int)$row['id']]);
        }
    }

    private function hideDuplicateFeedItems(PDO $pdo): void
    {
        $stmt = $pdo->query(
            'SELECT id, link_normalized, CHAR_LENGTH(content) AS content_len
             FROM feed_items
             WHERE link_normalized IS NOT NULL AND link_normalized != \'\' AND hidden = 0
             ORDER BY link_normalized ASC, content_len DESC, id ASC'
        );
        if ($stmt === false) {
            return;
        }

        /** @var array<string, int> $keepIdByNorm */
        $keepIdByNorm = [];
        /** @var list<int> $hideIds */
        $hideIds = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $norm = (string)($row['link_normalized'] ?? '');
            $id   = (int)($row['id'] ?? 0);
            if ($norm === '' || $id <= 0) {
                continue;
            }
            if (!isset($keepIdByNorm[$norm])) {
                $keepIdByNorm[$norm] = $id;
                continue;
            }
            $hideIds[] = $id;
        }

        if ($hideIds === []) {
            return;
        }

        $hide = $pdo->prepare('UPDATE feed_items SET hidden = 1 WHERE id = ?');
        foreach ($hideIds as $id) {
            $hide->execute([$id]);
        }
    }

    private static function columnAlreadyExists(PDOException $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'Duplicate column') || str_contains($msg, '1060');
    }

    private static function indexAlreadyExists(PDOException $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'Duplicate key name') || str_contains($msg, '1061');
    }
}
