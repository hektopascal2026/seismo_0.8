<?php
/**
 * Feed / mail subscription health for Settings → Diagnostics (staleness + fetch errors).
 */

declare(strict_types=1);

namespace Seismo\Repository;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;

final class SourceHealthRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * One row per `feeds` record. Status: broken | stale | ok | disabled.
     *
     * @return list<array<string, mixed>>
     */
    public function listFeedHealth(int $staleDays = 14): array
    {
        $staleDays = max(1, min(365, $staleDays));
        $t         = entryTable('feeds');
        $tItems    = entryTable('feed_items');
        $stmt      = $this->pdo->query(
            "SELECT f.id, f.title, f.source_type, f.url, f.disabled, f.last_fetched, f.last_error, f.last_error_at, f.consecutive_failures,
                    (
                        SELECT MAX(fi.cached_at)
                          FROM {$tItems} fi
                         WHERE fi.feed_id = f.id AND fi.hidden = 0
                    ) AS last_entry_added_at
               FROM {$t} f
              ORDER BY f.id ASC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . $staleDays . ' days');

        $out = [];
        foreach ($rows as $row) {
            $disabled = !empty($row['disabled']);
            $failures = (int)($row['consecutive_failures'] ?? 0);
            $lastErr  = trim((string)($row['last_error'] ?? ''));
            $broken   = !$disabled && ($failures > 0 || $lastErr !== '');

            $lastFetched    = $this->parseDbDateTime((string)($row['last_fetched'] ?? ''));
            $lastEntryAdded = $this->parseDbDateTime((string)($row['last_entry_added_at'] ?? ''));
            $stale          = !$disabled && !$broken
                && ($lastFetched === null || $lastFetched < $cutoff);

            if ($disabled) {
                $status = 'disabled';
            } elseif ($broken) {
                $status = 'broken';
            } elseif ($stale) {
                $status = 'stale';
            } else {
                $status = 'ok';
            }

            $sourceType = strtolower(trim((string)($row['source_type'] ?? '')));
            $out[]      = [
                'id'                    => (int)$row['id'],
                'title'                 => (string)($row['title'] ?? ''),
                'source_type'           => $sourceType,
                'source_kind_label'     => self::feedKindLabel($sourceType),
                'url'                   => (string)($row['url'] ?? ''),
                'disabled'              => $disabled,
                'status'                => $status,
                'last_fetched'          => $lastFetched,
                'last_fetched_raw'      => $row['last_fetched'],
                'last_entry_added'      => $lastEntryAdded,
                'last_entry_added_raw'  => $row['last_entry_added_at'],
                'last_error'            => $lastErr,
                'last_error_at'         => $row['last_error_at'],
                'consecutive_failures'  => $failures,
                'sort_rank'             => self::feedSortRank($status),
            ];
        }

        usort($out, static function (array $a, array $b): int {
            $ra = (int)($a['sort_rank'] ?? 99);
            $rb = (int)($b['sort_rank'] ?? 99);
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }

            return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
        });

        return $out;
    }

    /**
     * One row per active `email_subscriptions` row. Stale = no matching `emails` row in stale window.
     *
     * @return list<array<string, mixed>>
     */
    public function listMailHealth(int $staleDays = 14): array
    {
        $staleDays = max(1, min(365, $staleDays));
        $tSub      = entryTable('email_subscriptions');
        $tEmail    = entryTable('emails');

        $sql = "SELECT es.id, es.display_name, es.disabled, es.match_type, es.match_value,
            (
                SELECT MAX(COALESCE(e.date_received, e.date_sent, e.created_at))
                  FROM {$tEmail} e
                 WHERE e.from_email IS NOT NULL AND TRIM(e.from_email) <> ''
                   AND (
                        (es.match_type = 'email' AND LOWER(TRIM(e.from_email)) = LOWER(TRIM(es.match_value)))
                     OR (
                          es.match_type = 'domain'
                          AND (
                               LOWER(TRIM(SUBSTRING_INDEX(e.from_email, '@', -1))) = LOWER(TRIM(es.match_value))
                            OR LOWER(TRIM(SUBSTRING_INDEX(e.from_email, '@', -1)))
                                 LIKE CONCAT('%.', LOWER(TRIM(es.match_value)))
                          )
                     )
                   )
            ) AS last_ingested_at
              FROM {$tSub} es
             WHERE es.removed_at IS NULL
             ORDER BY es.id ASC";

        try {
            $stmt = $this->pdo->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log('Seismo SourceHealthRepository mail: ' . $e->getMessage());

            return [];
        }

        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . $staleDays . ' days');

        $out = [];
        foreach ($rows as $row) {
            $disabled      = !empty($row['disabled']);
            $lastIngested  = $this->parseDbDateTime((string)($row['last_ingested_at'] ?? ''));
            $stale         = !$disabled && ($lastIngested === null || $lastIngested < $cutoff);
            $status        = $disabled ? 'disabled' : ($stale ? 'stale' : 'ok');

            $out[] = [
                'id'                 => (int)$row['id'],
                'display_name'       => (string)($row['display_name'] ?? ''),
                'match_type'         => (string)($row['match_type'] ?? ''),
                'match_value'        => (string)($row['match_value'] ?? ''),
                'disabled'           => $disabled,
                'status'             => $status,
                'last_ingested'      => $lastIngested,
                'last_ingested_raw'  => $row['last_ingested_at'],
                'sort_rank'          => self::mailSortRank($status),
            ];
        }

        usort($out, static function (array $a, array $b): int {
            $ra = (int)($a['sort_rank'] ?? 99);
            $rb = (int)($b['sort_rank'] ?? 99);
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }

            return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
        });

        return $out;
    }

    private static function feedKindLabel(string $sourceType): string
    {
        return match ($sourceType) {
            'rss'         => 'RSS',
            'substack'    => 'Substack',
            'parl_press'  => 'Parl. press',
            'scraper'     => 'Scraper',
            default       => $sourceType !== '' ? $sourceType : 'Feed',
        };
    }

    private static function feedSortRank(string $status): int
    {
        return match ($status) {
            'broken'   => 0,
            'stale'    => 1,
            'ok'       => 2,
            'disabled' => 3,
            default    => 9,
        };
    }

    private static function mailSortRank(string $status): int
    {
        return match ($status) {
            'stale'    => 0,
            'ok'       => 1,
            'disabled' => 2,
            default    => 9,
        };
    }

    private function parseDbDateTime(string $raw): ?DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($raw, new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }
}
