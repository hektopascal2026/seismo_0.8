<?php
/**
 * Migration 015 — seed Parlament.ch SDA-Meldungen `parl_press` feed (schema 31).
 *
 * Medienmitteilungen (parl_mm) may already exist from {@see Migration007ParlPressToFeedItems}.
 * SDA uses the same SharePoint list URL with `guid_prefix: parl_sda` in description JSON
 * ({@see Migration008FeedsUrlNonUnique}).
 *
 * Idempotent: skips when a `parl_sda` category row or equivalent config already exists.
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;

final class Migration015ParlPressSdaFeed
{
    public const VERSION = 31;

    private const DEFAULT_API_URL = "https://www.parlament.ch/press-releases/_api/web/lists/getByTitle('Pages')/items";

    public function apply(PDO $pdo): void
    {
        if ($this->sdaFeedExists($pdo)) {
            return;
        }

        $url = $this->resolveListItemsUrl($pdo);
        $desc = json_encode(
            [
                'lookback_days' => 365,
                'limit'         => 80,
                'language'      => 'de',
                'guid_prefix'   => 'parl_sda',
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '{}';

        $ins = $pdo->prepare(
            'INSERT INTO feeds (
                url, source_type, title, description, link, category, disabled,
                consecutive_failures
            ) VALUES (?, \'parl_press\', ?, ?, ?, ?, 0, 0)'
        );
        $ins->execute([
            $url,
            'Parlament.ch — SDA-Meldungen',
            $desc,
            'https://www.parlament.ch/press-releases/',
            'parl_sda',
        ]);
    }

    private function sdaFeedExists(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query(
                "SELECT id FROM feeds
                  WHERE source_type = 'parl_press'
                    AND (
                        LOWER(TRIM(IFNULL(category, ''))) = 'parl_sda'
                        OR description LIKE '%\"guid_prefix\":\"parl_sda\"%'
                        OR description LIKE '%\"guid_prefix\": \"parl_sda\"%'
                    )
                  LIMIT 1"
            );
            $id = $stmt ? $stmt->fetchColumn() : false;
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'feeds')) {
                return true;
            }
            throw $e;
        }

        return $id !== false && $id !== null && (int)$id > 0;
    }

    private function resolveListItemsUrl(PDO $pdo): string
    {
        try {
            $stmt = $pdo->query(
                "SELECT url FROM feeds
                  WHERE source_type = 'parl_press'
                    AND TRIM(url) <> ''
                  ORDER BY id ASC
                  LIMIT 1"
            );
            $url = $stmt ? $stmt->fetchColumn() : false;
        } catch (PDOException) {
            return self::DEFAULT_API_URL;
        }
        if ($url !== false && $url !== null && trim((string)$url) !== '') {
            return trim((string)$url);
        }

        return self::DEFAULT_API_URL;
    }
}
