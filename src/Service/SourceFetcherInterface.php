<?php

declare(strict_types=1);

namespace Seismo\Service;

/**
 * Third-party source adapter contract (plugins). No SQL — persistence is the runner's job.
 *
 * @see \Seismo\Service\RefreshAllService
 */
interface SourceFetcherInterface
{
    /** Stable machine id (logs, diagnostics). */
    public function getIdentifier(): string;

    /** Human-readable label for UI and logs. */
    public function getLabel(): string;

    /**
     * Family table / Magnitu `entry_type`.
     *
     * v0.5: `RefreshAllService` maps `lex_item` and `calendar_event` to repos;
     * add a branch there when introducing a new family.
     */
    public function getEntryType(): string;

    /**
     * Row-key fragment inside `system_config` (e.g. Fedlex → "ch", which
     * resolves to `plugin:ch`). Historically the slot lived in a JSON
     * file (`lex_config.json`, `calendar_config.json`); Slice 5a folded
     * those into `system_config` rows.
     */
    public function getConfigKey(): string;

    /**
     * Fetch items from the external source and return normalised row arrays.
     * MUST NOT write to the DB. MAY throw — the runner catches and logs.
     * Implementations MUST drop unusable rows (e.g. empty title, missing stable id)
     * so the repository never persists dead dashboard cards.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetch(array $config): array;

    /**
     * Minimum seconds between successful runs for this plugin.
     *
     * RefreshAllService::runAll() skips the plugin when the last successful
     * row (`ok` or `warn`) in plugin_run_log is newer than now -
     * getMinIntervalSeconds(). Throttle
     * skips are NOT persisted to plugin_run_log (see Master Cron pattern in
     * core-plugin-architecture.mdc) — they only appear on cron stdout.
     *
     * User-initiated single-plugin refreshes bypass this via
     * RefreshAllService::runPlugin($id, force: true).
     *
     * Return 0 to always run (no throttle).
     */
    public function getMinIntervalSeconds(): int;
}
