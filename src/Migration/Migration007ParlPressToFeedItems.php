<?php
/**
 * Migration 007 — Parlament.ch Medienmitteilungen as `feed_items` (schema 23).
 *
 * Press releases were stored in `lex_items` with `source = 'parl_mm'`. They
 * are now a first-class RSS-shaped feed (`feeds.source_type = parl_press`)
 * so Magnitu and the dashboard treat them as `feed_item`, not legislation.
 *
 * Idempotent: re-run skips when no `parl_mm` lex rows remain and the seed feed
 * already exists.
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;

final class Migration007ParlPressToFeedItems
{
    public const VERSION = 23;

    private const API_URL = "https://www.parlament.ch/press-releases/_api/web/lists/getByTitle('Pages')/items";

    public function apply(PDO $pdo): void
    {
        $feedId = $this->ensureParlPressFeed($pdo);
        if ($feedId <= 0) {
            return;
        }

        $this->migrateLexRows($pdo, $feedId);

        try {
            $pdo->exec("DELETE FROM lex_items WHERE source = 'parl_mm'");
        } catch (PDOException $e) {
            if (!str_contains($e->getMessage(), 'lex_items')) {
                throw $e;
            }
        }

        try {
            $pdo->exec("DELETE FROM system_config WHERE config_key = 'plugin:parl_mm'");
        } catch (PDOException) {
            // system_config may be absent on broken installs — ignore
        }
    }

    private function ensureParlPressFeed(PDO $pdo): int
    {
        try {
            $stmt = $pdo->query("SELECT id FROM feeds WHERE source_type = 'parl_press' ORDER BY id ASC LIMIT 1");
            $existing = $stmt ? $stmt->fetchColumn() : false;
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'feeds')) {
                return 0;
            }
            throw $e;
        }
        if ($existing !== false && $existing !== null && (int)$existing > 0) {
            return (int)$existing;
        }

        $desc = json_encode(
            ['lookback_days' => 90, 'limit' => 50, 'language' => 'de'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '{}';

        $ins = $pdo->prepare(
            'INSERT INTO feeds (
                url, source_type, title, description, link, category, disabled,
                consecutive_failures
            ) VALUES (?, \'parl_press\', ?, ?, ?, ?, 0, 0)'
        );
        $ins->execute([
            self::API_URL,
            'Parlament.ch — Medienmitteilungen',
            $desc,
            'https://www.parlament.ch/press-releases/',
            'Parlament Medien',
        ]);

        return (int)$pdo->lastInsertId();
    }

    private function migrateLexRows(PDO $pdo, int $feedId): void
    {
        try {
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM lex_items WHERE source = 'parl_mm'")->fetchColumn();
        } catch (PDOException) {
            return;
        }
        if ($cnt <= 0) {
            return;
        }

        $sql = 'INSERT INTO feed_items (
                feed_id, guid, title, link, description, content, author,
                published_date, content_hash, hidden, cached_at
            )
            SELECT
                ?,
                SUBSTRING(li.celex, 1, 500),
                SUBSTRING(COALESCE(NULLIF(TRIM(li.title), \'\'), \'(no title)\'), 1, 500),
                SUBSTRING(COALESCE(NULLIF(TRIM(li.eurlex_url), \'\'), \'https://www.parlament.ch/\'), 1, 500),
                li.description,
                COALESCE(NULLIF(li.description, \'\'), li.title),
                \'\',
                CASE
                    WHEN li.document_date IS NOT NULL AND TRIM(li.document_date) <> \'\'
                    THEN CONCAT(TRIM(li.document_date), \' 00:00:00\')
                    ELSE NULL
                END,
                SUBSTRING(MD5(CONCAT(li.celex, \'|\', COALESCE(li.title, \'\'))), 1, 32),
                0,
                UTC_TIMESTAMP()
            FROM lex_items li
            WHERE li.source = \'parl_mm\'
              AND li.eurlex_url IS NOT NULL
              AND TRIM(li.eurlex_url) <> \'\'
              AND li.eurlex_url LIKE \'http%\'
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                link = VALUES(link),
                description = VALUES(description),
                content = VALUES(content),
                published_date = IF(
                    VALUES(content_hash) = feed_items.content_hash,
                    feed_items.published_date,
                    VALUES(published_date)
                ),
                content_hash = VALUES(content_hash),
                cached_at = UTC_TIMESTAMP()';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$feedId]);
    }
}
