<?php

declare(strict_types=1);

namespace Seismo\Repository;

use PDO;

/**
 * MySQL/MariaDB named locks for ingest mutual exclusion (CLI cron + web refresh).
 *
 * Chunked RSS/scraper refresh persists cursor state only after a batch finishes.
 * Overlapping PHP processes (two cron ticks, or cron plus a browser refresh) could
 * otherwise read the same cursor and duplicate upstream fetches.
 *
 * `refresh_cron.php` acquires this lock for the whole script; {@see \Seismo\Service\RefreshAllService}
 * acquires the same lock for web paths that run RSS/scraper chunk ingest (`runAll`, module Feeds/Scraper
 * refresh, Diagnostics refresh for `core:rss` / `core:scraper`). Pass `runAll(..., mutexHeldExternally: true)`
 * when the caller already holds the lock.
 *
 * Lock names are scoped per {@see DB_NAME} so two Seismo databases on one server
 * do not block each other.
 */
final class CronMutexRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Stable lock identifier for this install's master cron (max 64 chars on MariaDB).
     */
    public static function refreshCronLockName(): string
    {
        $db = defined('DB_NAME') ? (string)DB_NAME : '';
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', $db);
        $full = 'seismo_refresh_cron_' . $safe;

        return substr($full, 0, 64);
    }

    /**
     * Try to acquire the master-cron lock without waiting (`GET_LOCK(..., 0)`).
     *
     * @return bool True when this connection owns the lock.
     */
    public function tryAcquireRefreshCron(): bool
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute([self::refreshCronLockName()]);
        $v = $stmt->fetchColumn();
        if ($v === false || $v === null) {
            return false;
        }

        return (int)$v === 1;
    }

    public function releaseRefreshCron(): void
    {
        $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([self::refreshCronLockName()]);
    }
}
