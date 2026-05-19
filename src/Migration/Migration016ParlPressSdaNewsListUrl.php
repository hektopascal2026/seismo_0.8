<?php
/**
 * Migration 016 — point SDA `parl_press` feed at the news site `Seiten` list (schema 32).
 *
 * SDA-Meldungen are not in `press-releases/Pages`; they live under `/de/services/news/Seiten/`.
 * {@see \Seismo\Core\Fetcher\ParlPressFetchService::DEFAULT_SDA_LIST_ITEMS_URL}
 *
 * Idempotent: only updates rows that still use the press-releases list URL.
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use Seismo\Core\Fetcher\ParlPressFetchService;

final class Migration016ParlPressSdaNewsListUrl
{
    public const VERSION = 32;

    private const OLD_PRESS_RELEASES_LIST = "https://www.parlament.ch/press-releases/_api/web/lists/getByTitle('Pages')/items";

    public function apply(PDO $pdo): void
    {
        $newUrl = ParlPressFetchService::DEFAULT_SDA_LIST_ITEMS_URL;
        $newLink = 'https://www.parlament.ch/de/services/news/';

        $stmt = $pdo->prepare(
            "UPDATE feeds
                SET url = ?, link = ?
              WHERE source_type = 'parl_press'
                AND (
                    LOWER(TRIM(IFNULL(category, ''))) = 'parl_sda'
                    OR description LIKE '%\"guid_prefix\":\"parl_sda\"%'
                    OR description LIKE '%\"guid_prefix\": \"parl_sda\"%'
                )
                AND (
                    TRIM(url) = ''
                    OR TRIM(url) = ?
                    OR url LIKE '%press-releases/_api/web/lists%'
                )"
        );
        $stmt->execute([$newUrl, $newLink, self::OLD_PRESS_RELEASES_LIST]);
    }
}
