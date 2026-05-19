<?php

declare(strict_types=1);

namespace Seismo\Service;

/**
 * Outcome of a single plugin or core fetcher run. Persisted to `plugin_run_log` by
 * RefreshAllService / CoreRunner unless {@see self::$persistToPluginRunLog} is
 * false (throttle skips — stdout / cron mail only).
 */
final class PluginRunResult
{
    public function __construct(
        public readonly string $status,
        public readonly int $count = 0,
        public readonly ?string $message = null,
        public readonly bool $persistToPluginRunLog = true,
    ) {
    }

    public static function ok(int $count): self
    {
        return new self('ok', $count);
    }

    /**
     * Core batch fetchers (RSS, Parl. press, scraper): aggregate outcome from
     * per-feed tries. All failed → {@see error()}; some failed → `warn`; none
     * failed → `ok`. Empty batch (`$sourcesAttempted === 0`) → `ok`.
     */
    public static function batchFeeds(int $itemCount, int $sourcesAttempted, int $sourcesFailed): self
    {
        if ($sourcesAttempted < 0 || $sourcesFailed < 0) {
            throw new \InvalidArgumentException('batchFeeds: counts must be non-negative.');
        }
        if ($sourcesFailed > $sourcesAttempted) {
            throw new \InvalidArgumentException('batchFeeds: failed cannot exceed attempted.');
        }
        if ($sourcesAttempted === 0 || $sourcesFailed === 0) {
            return new self('ok', $itemCount);
        }
        if ($sourcesFailed === $sourcesAttempted) {
            return new self(
                'error',
                $itemCount,
                sprintf('All %d source(s) failed.', $sourcesFailed)
            );
        }

        return new self(
            'warn',
            $itemCount,
            sprintf('%d of %d sources failed.', $sourcesFailed, $sourcesAttempted)
        );
    }

    /**
     * @param bool $persistToPluginRunLog When false, cron/diagnostics will not
     *        write a `plugin_run_log` row (e.g. IMAP not configured — avoid
     *        log spam every cron tick).
     */
    public static function skipped(string $message, bool $persistToPluginRunLog = true): self
    {
        return new self('skipped', 0, $message, $persistToPluginRunLog);
    }

    /**
     * Skipped because the per-source throttle window has not elapsed. Must not
     * write a `plugin_run_log` row (avoids cron noise).
     */
    public static function throttleSkipped(string $message): self
    {
        return new self('skipped', 0, $message, false);
    }

    public static function error(string $message): self
    {
        return new self('error', 0, $message);
    }

    public function isOk(): bool
    {
        return $this->status === 'ok';
    }

    /**
     * Outcome of {@see \Seismo\Service\CoreRunner} throttle check — must not
     * be treated like a partial chunked cycle when looping web refresh.
     */
    public function isThrottleSkipped(): bool
    {
        return $this->status === 'skipped'
            && $this->message !== null
            && str_contains($this->message, 'Throttled');
    }

    /**
     * Copy with a different `plugin_run_log` persistence flag (chunked partial
     * runs vs cycle-complete rows).
     */
    public function withPersist(bool $persistToPluginRunLog): self
    {
        return new self(
            $this->status,
            $this->count,
            $this->message,
            $persistToPluginRunLog
        );
    }
}
