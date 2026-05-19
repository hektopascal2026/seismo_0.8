<?php

declare(strict_types=1);

namespace Seismo\Service;

/**
 * Thrown when {@see CronMutexRepository::tryAcquireRefreshCron()} fails because
 * another CLI or web ingest run already holds the advisory lock.
 */
final class RefreshMutexBusyException extends \RuntimeException
{
    public static function defaultMessage(): string
    {
        return 'Another ingest run is in progress (background cron or another refresh). Wait until it finishes, then try again.';
    }
}
