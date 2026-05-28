<?php
/**
 * Settings → Diagnostics tab — plugin / core fetcher status (embedded panel).
 *
 * @var string $csrfField
 * @var string $basePath
 * @var array<string, array<string, mixed>> $diagStatus
 * @var array<string, array<string, mixed>> $diagCoreStatus
 * @var array<string, array<string, mixed>> $diagMediaStatus
 * @var ?string $diagLoadError
 * @var ?array{id: string, count: int, error: ?string, items: list<array<string, mixed>>} $diagTestResult
 * @var array<string, list<array{run_at: \DateTimeImmutable, status: string, item_count: int, error_message: ?string, duration_ms: int}>> $diagRunHistory
 * @var list<array<string, mixed>> $diagSourceHealthFeeds
 * @var list<array<string, mixed>> $diagSourceHealthMail
 * @var int $diagSourceHealthStaleDays
 * @var ?string $diagSourceHealthError
 * @var bool $satellite
 */

declare(strict_types=1);

if (!function_exists('seismo_format_utc')) {
    require_once __DIR__ . '/../helpers.php';
}

$diagCardClass = static function (?array $row): string {
    if ($row === null) {
        return 'entry-card--diag-never';
    }

    return match ($row['status']) {
        'ok'      => 'entry-card--diag-ok',
        'warn'    => 'entry-card--diag-warn',
        'error'   => 'entry-card--diag-error',
        'skipped' => 'entry-card--diag-skipped',
        default   => 'entry-card--diag-warn',
    };
};

/** Human label for plugin_run_log status on diagnostics cards / tables. */
$diagRunStatusLabel = static function (string $status): string {
    return match ($status) {
        'warn' => 'partial',
        default => $status,
    };
};

$diagSourceHealthStatus = static function (string $status): array {
    return match ($status) {
        'broken' => ['label' => 'Broken', 'class' => 'diag-source-pill diag-source-pill--broken'],
        'stale' => ['label' => 'Stale', 'class' => 'diag-source-pill diag-source-pill--stale'],
        'ok' => ['label' => 'OK', 'class' => 'diag-source-pill diag-source-pill--ok'],
        'disabled' => ['label' => 'Disabled', 'class' => 'diag-source-pill diag-source-pill--disabled'],
        default => ['label' => $status, 'class' => 'diag-source-pill'],
    };
};

$friendlyPluginStatus = static function (array $s) use ($diagRunStatusLabel): array {
    if ($s['last'] === null) {
        return [
            'label' => 'Never Run',
            'class' => 'status-badge status-badge--neutral',
            'desc' => 'This source has not been checked yet.',
            'is_error' => false,
            'is_throttled' => false,
            'is_disabled' => false,
        ];
    }

    $last = $s['last'];
    $err = trim((string)($last['error_message'] ?? ''));

    if (str_contains($err, 'Disabled in config')) {
        return [
            'label' => 'Turned Off',
            'class' => 'status-badge status-badge--neutral',
            'desc' => 'This source is currently inactive (disabled in configuration).',
            'is_error' => false,
            'is_throttled' => false,
            'is_disabled' => true,
        ];
    }

    if (str_contains($err, 'Throttled')) {
        $nextTime = $s['next_allowed'] !== null ? seismo_format_utc($s['next_allowed']) : 'soon';
        return [
            'label' => 'Up-to-date',
            'class' => 'status-badge status-badge--info',
            'desc' => "Checked recently. Next check allowed after {$nextTime}.",
            'is_error' => false,
            'is_throttled' => true,
            'is_disabled' => false,
        ];
    }

    if ($last['status'] === 'ok') {
        return [
            'label' => 'Healthy',
            'class' => 'status-badge status-badge--success',
            'desc' => "Successfully checked. Found {$last['item_count']} new items.",
            'is_error' => false,
            'is_throttled' => false,
            'is_disabled' => false,
        ];
    }

    if ($last['status'] === 'warn') {
        return [
            'label' => 'Partial Success',
            'class' => 'status-badge status-badge--info',
            'desc' => "Checked with warning: " . $err,
            'is_error' => false,
            'is_throttled' => false,
            'is_disabled' => false,
        ];
    }

    return [
        'label' => 'System Issue',
        'class' => 'status-badge status-badge--danger',
        'desc' => "Connection failed or error encountered: " . $err,
        'is_error' => true,
        'is_throttled' => false,
        'is_disabled' => false,
    ];
};

$friendlyCategory = static function (string $family): string {
    return match ($family) {
        'lex_item' => 'Law & Jurisdiction',
        'leg_item' => 'Parliamentary Calendar',
        'feed_item' => 'News & Feeds',
        'email_item' => 'Email Updates',
        default => ucfirst(str_replace('_', ' ', $family)),
    };
};

$friendlyInterval = static function (int $seconds): string {
    if ($seconds <= 0) {
        return 'Every check cycle';
    }
    $minutes = round($seconds / 60);
    if ($minutes < 60) {
        return "Every {$minutes} minutes";
    }
    $hours = round($minutes / 60);
    if ($hours === 1.0) {
        return 'Every hour';
    }
    return "Every {$hours} hours";
};

$friendlyDuration = static function (int $ms): string {
    if ($ms <= 0) {
        return 'Less than a second';
    }
    if ($ms < 1000) {
        return "{$ms} ms";
    }
    $seconds = round($ms / 1000, 1);
    return "{$seconds} seconds";
};

$diagMediaStatus = $diagMediaStatus ?? [];
$diagSourceHealthFeeds = $diagSourceHealthFeeds ?? [];
$diagSourceHealthMail = $diagSourceHealthMail ?? [];
$diagSourceHealthStaleDays = (int)($diagSourceHealthStaleDays ?? 14);
$diagSourceHealthError = $diagSourceHealthError ?? null;
?>
        <?php if (isset($cronStalledMinutes) && $cronStalledMinutes !== null): ?>
            <div class="message message-error" style="margin-bottom: 20px;">
                <strong>⚠️ Warning: Background cron job appears stalled!</strong><br>
                The master cron execution started <?= (int)$cronStalledMinutes ?> minutes ago and is still holding the advisory lock. 
                This usually indicates a stuck loop or a process time-budget deadlock.
            </div>
        <?php endif; ?>
        <?php if ($diagLoadError !== null): ?>
            <div class="message message-error"><?= e($diagLoadError) ?></div>
        <?php endif; ?>
        <?php if ($satellite): ?>
            <p class="message message-info">Satellite mode: plugins do not run on this instance. The mothership refreshes the entry tables.</p>
        <?php endif; ?>

        <div class="latest-entries-section module-section-spaced">
            <h2 class="section-title">Master refresh</h2>
            <p class="admin-intro">
                Runs every registered plugin now, ignoring throttle (except mail/Gmail — same 15&nbsp;min window as cron). The CLI cron
                <code>refresh_cron.php</code> calls the same code with throttle on.
            </p>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=refresh_all" class="admin-inline-form seismo-ajax-refresh-form">
                <?= $csrfField ?>
                <button type="submit" class="btn btn-primary" data-refresh-label="Refresh all now"<?= $satellite ? ' disabled' : '' ?>>Refresh all now</button>
            </form>
        </div>

        <?php if (!$satellite): ?>
        <div class="latest-entries-section module-section-spaced">
            <h2 class="section-title">Source health</h2>
            <p class="admin-intro">
                One row per <strong>feed</strong> (RSS, Substack, Parl. press, scraper) and per <strong>mail subscription</strong>.
                <strong>Stale</strong> means no successful fetch (feeds, by <code>last_fetched</code>) or no matching message
                ingested into the mail store in the last <strong><?= (int)$diagSourceHealthStaleDays ?></strong> days.
                <strong>Broken</strong> applies to feeds only: enabled source with consecutive failures or a stored fetch error.
                For feeds, <strong>Last entry</strong> is when the newest non-hidden item was stored (<code>cached_at</code>).
            </p>
            <?php if ($diagSourceHealthError !== null): ?>
                <div class="message message-error"><?= e($diagSourceHealthError) ?></div>
            <?php else: ?>
                <?php
                $feedAttention = 0;
                foreach ($diagSourceHealthFeeds as $_fh) {
                    if (in_array($_fh['status'] ?? '', ['broken', 'stale'], true)) {
                        $feedAttention++;
                    }
                }
                $mailAttention = 0;
                foreach ($diagSourceHealthMail as $_mh) {
                    if (($_mh['status'] ?? '') === 'stale') {
                        $mailAttention++;
                    }
                }
                ?>
                <p class="admin-intro diag-source-health-summary">
                    <?= (int)$feedAttention ?> feed source(s) and <?= (int)$mailAttention ?> mail subscription(s) need attention
                    (broken or stale).
                </p>

                <h3 class="section-title section-title--nested">Feeds</h3>
                <div class="settings-satellite-table-wrap">
                    <table class="settings-satellite-table diag-source-health-table">
                        <thead>
                            <tr>
                                <th scope="col">Status</th>
                                <th scope="col">Kind</th>
                                <th scope="col">Title</th>
                                <th scope="col">Last entry</th>
                                <th scope="col">Last fetch</th>
                                <th scope="col">Error / note</th>
                                <th scope="col"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($diagSourceHealthFeeds as $fr): ?>
                                <?php
                                $st = $diagSourceHealthStatus((string)($fr['status'] ?? ''));
                                $leRaw = $fr['last_entry_added_raw'] ?? null;
                                $leShown = ($leRaw !== null && (string)$leRaw !== '')
                                    ? date('d.m.Y H:i', strtotime((string)$leRaw))
                                    : 'never';
                                $lfRaw = $fr['last_fetched_raw'] ?? null;
                                $lfShown = ($lfRaw !== null && (string)$lfRaw !== '')
                                    ? date('d.m.Y H:i', strtotime((string)$lfRaw))
                                    : 'never';
                                $err = trim((string)($fr['last_error'] ?? ''));
                                if (mb_strlen($err) > 160) {
                                    $err = mb_substr($err, 0, 157) . '…';
                                }
                                $failN = (int)($fr['consecutive_failures'] ?? 0);
                                $note = $err !== '' ? $err : ($failN > 0 ? 'consecutive_failures: ' . $failN : '—');
                                ?>
                                <tr>
                                    <td><span class="<?= e($st['class']) ?>" role="status"><?= e($st['label']) ?></span></td>
                                    <td><?= e((string)($fr['source_kind_label'] ?? '')) ?></td>
                                    <td><?= e((string)($fr['title'] ?? '')) ?></td>
                                    <td class="diag-source-health-mono"><?= e($leShown) ?></td>
                                    <td class="diag-source-health-mono"><?= e($lfShown) ?></td>
                                    <td class="diag-source-health-note"><?= e($note) ?></td>
                                    <td><a href="<?= e($basePath) ?>/index.php?action=feeds&amp;view=sources">Feeds</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <h3 class="section-title section-title--nested">Mail subscriptions</h3>
                <div class="settings-satellite-table-wrap">
                    <table class="settings-satellite-table diag-source-health-table">
                        <thead>
                            <tr>
                                <th scope="col">Status</th>
                                <th scope="col">Display name</th>
                                <th scope="col">Match</th>
                                <th scope="col">Last ingested</th>
                                <th scope="col"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($diagSourceHealthMail as $mr): ?>
                                <?php
                                $st = $diagSourceHealthStatus((string)($mr['status'] ?? ''));
                                $liRaw = $mr['last_ingested_raw'] ?? null;
                                $liShown = ($liRaw !== null && (string)$liRaw !== '')
                                    ? date('d.m.Y H:i', strtotime((string)$liRaw))
                                    : 'never';
                                $matchLabel = (string)($mr['match_type'] ?? '') . ': ' . (string)($mr['match_value'] ?? '');
                                ?>
                                <tr>
                                    <td><span class="<?= e($st['class']) ?>" role="status"><?= e($st['label']) ?></span></td>
                                    <td><?= e((string)($mr['display_name'] ?? '')) ?></td>
                                    <td class="diag-source-health-mono"><?= e($matchLabel) ?></td>
                                    <td class="diag-source-health-mono"><?= e($liShown) ?></td>
                                    <td><a href="<?= e($basePath) ?>/index.php?action=mail&amp;view=sources">Mail</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php
        $diagFetcherSectionVars = static fn (
            string $title,
            string $introHtml,
            array $cards,
        ): array => [
            'sectionTitle'        => $title,
            'sectionIntroHtml'    => $introHtml,
            'diagCards'           => $cards,
            'diagRunHistory'      => $diagRunHistory,
            'csrfField'           => $csrfField,
            'basePath'            => $basePath,
            'satellite'           => $satellite,
            'diagCardClass'       => $diagCardClass,
            'diagRunStatusLabel'  => $diagRunStatusLabel,
            'friendlyPluginStatus' => $friendlyPluginStatus,
            'friendlyCategory'    => $friendlyCategory,
            'friendlyInterval'    => $friendlyInterval,
            'friendlyDuration'    => $friendlyDuration,
        ];
        extract($diagFetcherSectionVars(
            'Media module',
            'Targeted refresh for <code>feeds.category = media</code> only (RSS with optional hydration + media scrapers). '
            . 'Same as <a href="' . e($basePath) . '/index.php?action=media&amp;view=sources">Media → Refresh</a>; '
            . 'does not run general Feeds or full <code>core:rss</code> / <code>core:scraper</code> cycles. '
            . 'Logged under <code>core:rss:media</code> and <code>core:scraper:media</code>.',
            $diagMediaStatus,
        ));
        require __DIR__ . '/diagnostics_fetcher_section.php';

        extract($diagFetcherSectionVars(
            'Core fetchers',
            'RSS (incl. Substack, excluding <code>category = media</code>), Parliament press (<code>core:parl_press</code>), '
            . 'scraper (non-media listings), and mail share <code>plugin_run_log</code> under <code>core:*</code>. '
            . 'They run with “Refresh all now” and CLI cron. Cards are <strong>green</strong> when every source in the batch succeeded; '
            . '<strong>yellow</strong> (<em>partial</em>) when some failed; <strong>red</strong> when all failed or the runner threw.',
            $diagCoreStatus,
        ));
        require __DIR__ . '/diagnostics_fetcher_section.php';
        ?>

        <div class="latest-entries-section">
            <h2 class="section-title">Plugins (<?= count($diagStatus) ?>)</h2>

            <?php if ($diagStatus === []): ?>
                <div class="empty-state"><p>No plugins registered.</p></div>
            <?php else: ?>
                <?php foreach ($diagStatus as $id => $s): ?>
                    <?php
                        $cardClass = $diagCardClass($s['last']);
                        $lastWhen   = $s['last'] !== null ? seismo_format_utc($s['last']['run_at']) : null;
                        $nextWhen   = $s['next_allowed'] !== null ? seismo_format_utc($s['next_allowed']) : null;

                        $friendly = $friendlyPluginStatus($s);
                        $category = $friendlyCategory($s['entry_type']);
                        $interval = $friendlyInterval((int)$s['min_interval']);
                    ?>
                    <div class="entry-card <?= e($cardClass) ?>">
                        <div class="entry-header">
                            <span class="entry-tag entry-tag--surface">
                                <strong><?= e($s['label']) ?></strong>
                                <span class="entry-muted">(<?= e($s['id']) ?>)</span>
                            </span>
                            <span class="entry-tag entry-tag--meta" style="margin-inline-start: auto;">
                                Category: <?= e($category) ?>
                            </span>
                        </div>

                        <div style="margin: 0.5rem 0 0.75rem 0; display: flex; align-items: baseline; flex-wrap: wrap; gap: 0.5rem;">
                            <span class="<?= e($friendly['class']) ?>"><?= e($friendly['label']) ?></span>
                            <span class="entry-muted" style="font-size: 0.8125rem; font-weight: 500;">
                                <?= e($friendly['desc']) ?> (Checks: <?= e(strtolower($interval)) ?>)
                            </span>
                        </div>

                        <div class="entry-content entry-content--mono-sm" style="opacity: 0.85; border-top: 1px dashed rgba(0,0,0,0.15); padding-top: 0.5rem;">
                            <?php if ($s['last'] === null): ?>
                                Never checked yet.
                            <?php else: ?>
                                <strong>Last check:</strong> <?= e((string)$lastWhen) ?>
                                · <strong>Items added:</strong> <?= (int)$s['last']['item_count'] ?>
                                · <strong>Duration:</strong> <?= e($friendlyDuration((int)$s['last']['duration_ms'])) ?>
                            <?php endif; ?>

                            <?php if ($friendly['is_error'] && !empty($s['last']['error_message'])): ?>
                                <div class="diag-inline-error" style="margin-top: 0.375rem; font-weight: 600;">
                                    ⚠️ System details: <?= e((string)$s['last']['error_message']) ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($s['last'] !== null && $s['last']['status'] === 'skipped' && !empty($s['last_attempt'])): ?>
                                <?php
                                $att = $s['last_attempt'];
                                $attWhen = date('d.m.Y H:i', $att['run_at']->getTimestamp());
                                $attStatusLabel = $diagRunStatusLabel($att['status']);
                                $attMsgClass = ($att['status'] === 'warn') ? 'diag-inline-warn' : 'diag-inline-error';
                                ?>
                                <div class="diag-inline-skipped" style="margin-top: 0.375rem; font-size: 0.9em; opacity: 0.85;">
                                    ℹ️ Last attempt before skip (<?= $attWhen ?>): 
                                    <span class="<?= e($attMsgClass) ?>" style="display: inline-block; padding: 0.125rem 0.375rem; font-weight: bold;">
                                        <?= e($attStatusLabel) ?>
                                    </span>
                                    <?php if (!empty($att['error_message']) && $att['status'] !== 'ok' && !str_contains($att['error_message'], 'Throttled') && !str_contains($att['error_message'], 'Disabled')): ?>
                                        <br><span class="diag-inline-error" style="display: inline-block; margin-top: 0.1875rem;"><?= e((string)$att['error_message']) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="entry-actions diag-actions">
                            <div class="admin-table-actions">
                                <form method="post" action="<?= e($basePath) ?>/index.php?action=refresh_plugin" class="admin-inline-form seismo-ajax-refresh-form">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="plugin_id" value="<?= e($s['id']) ?>">
                                    <button type="submit" class="btn btn-secondary" data-refresh-label="Checking..."<?= $satellite ? ' disabled' : '' ?>>Check now</button>
                                </form>
                                <form method="post" action="<?= e($basePath) ?>/index.php?action=plugin_test" class="admin-inline-form">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="plugin_id" value="<?= e($s['id']) ?>">
                                    <button type="submit" class="btn btn-secondary"<?= $satellite ? ' disabled' : '' ?>>Preview incoming items</button>
                                </form>
                            </div>
                        </div>
                        <?php
                        $hist = $diagRunHistory[$id] ?? [];
                        require __DIR__ . '/plugin_recent_runs.php';
                        ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($diagTestResult !== null): ?>
            <div class="latest-entries-section diag-test-section">
                <h2 class="section-title">Test fetch result: <?= e($diagTestResult['id']) ?></h2>
                <?php if ($diagTestResult['error'] !== null): ?>
                    <div class="message message-error"><?= e((string)$diagTestResult['error']) ?></div>
                <?php else: ?>
                    <p class="admin-intro">
                        Plugin returned <strong><?= (int)$diagTestResult['count'] ?></strong> row(s).
                        Showing first <?= count($diagTestResult['items']) ?> (no DB writes occurred).
                    </p>
                    <pre class="pre-json-block"><?= e(json_encode($diagTestResult['items'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                <?php endif; ?>
            </div>
        <?php endif; ?>
