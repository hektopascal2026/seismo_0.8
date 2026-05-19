<?php
/**
 * Migration 015 — seed Parlament.ch SDA-Meldungen `parl_press` feed (schema 31).
 *
 * Medienmitteilungen (parl_mm) may already exist from {@see Migration007ParlPressToFeedItems}.
 * SDA uses the news site `Seiten` list URL with `guid_prefix: parl_sda` in description JSON
 * ({@see Migration008FeedsUrlNonUnique}).
 *
 * Idempotent: skips when a `parl_sda` category row or equivalent config already exists.
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;
use Seismo\Core\Fetcher\ParlPressFetchService;

final class Migration015ParlPressSdaFeed
{
    public const VERSION = 31;

    public function apply(PDO $pdo): void
    {
        if ($this->sdaFeedExists($pdo)) {
            return;
        }

        $url = ParlPressFetchService::DEFAULT_SDA_LIST_ITEMS_URL;
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
            'https://www.parlament.ch/de/services/news/',
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

}
