<?php
/**
 * Settings → Diagnostics tab — plugin / core fetcher status (embedded panel).
 *
 * @var string $csrfField
 * @var string $basePath
 * @var array<string, array<string, mixed>> $diagStatus
 * @var array<string, array<string, mixed>> $diagCoreStatus
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

$diagSourceHealthFeeds = $diagSourceHealthFeeds ?? [];
$diagSourceHealthMail = $diagSourceHealthMail ?? [];
$diagSourceHealthStaleDays = (int)($diagSourceHealthStaleDays ?? 14);
$diagSourceHealthError = $diagSourceHealthError ?? null;
?>
        <?php if ($diagLoadError !== null): ?>
            <div class="message message-error"><?= e($diagLoadError) ?></div>
        <?php endif; ?>
        <?php if ($satellite): ?>
            <p class="message message-info">Satellite mode: plugins do not run on this instance. The mothership refreshes the entry tables.</p>
        <?php endif; ?>

        <div class="latest-entries-section module-section-spaced">
            <h2 class="section-title">Master refresh</h2>
            <p class="admin-intro">
                Runs every registered plugin now, ignoring throttle. The CLI cron
                <code>refresh_cron.php</code> calls the same code with throttle on.
            </p>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=refresh_all" class="admin-inline-form">
                <?= $csrfField ?>
                <button type="submit" class="btn btn-primary"<?= $satellite ? ' disabled' : '' ?>>Refresh all now</button>
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
                                    <td><a href="<?= e($basePath) ?>/index.php?action=mail&amp;view=subscriptions">Mail</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($diagCoreStatus !== []): ?>
        <div class="latest-entries-section module-section-spaced">
            <h2 class="section-title">Core fetchers (<?= count($diagCoreStatus) ?>)</h2>
            <p class="admin-intro">RSS (incl. Substack), Parliament press (<code>core:parl_press</code>), scraper, and mail runs share <code>plugin_run_log</code> under synthetic ids (<code>core:*</code>). They run automatically with “Refresh all now” and CLI cron. For RSS / Parl. press / scraper, the card is <strong>green</strong> only when every enabled source in that batch succeeded; <strong>yellow</strong> (<em>partial</em>) when some failed; <strong>red</strong> when all tried sources failed (or the runner threw).</p>
                <?php foreach ($diagCoreStatus as $id => $s): ?>
                <?php
                    $cardClass = $diagCardClass($s['last']);
                    $lastStatus = $s['last'] === null ? 'never run' : (string)($s['last']['status'] ?? '');
                    $lastStatusLabel = $s['last'] === null ? 'never run' : $diagRunStatusLabel($lastStatus);
                    $lastWhen   = $s['last'] !== null ? seismo_format_utc($s['last']['run_at']) : null;
                    $nextWhen   = $s['next_allowed'] !== null ? seismo_format_utc($s['next_allowed']) : null;
                ?>
                <div class="entry-card <?= e($cardClass) ?>">
                    <div class="entry-header">
                        <span class="entry-tag entry-tag--surface">
                            <strong><?= e($s['label']) ?></strong>
                            <span class="entry-muted">(<?= e($s['id']) ?>)</span>
                        </span>
                        <span class="entry-tag entry-tag--meta">family: <?= e((string)$s['entry_type']) ?></span>
                        <span class="entry-tag entry-tag--meta">
                            throttle: <?= $s['min_interval'] > 0 ? e((string)round($s['min_interval'] / 60)) . ' min' : 'none' ?>
                        </span>
                        <span class="entry-tag entry-tag--surface entry-tag--emphasis">last: <?= e($lastStatusLabel) ?></span>
                        <?php if ($s['is_throttled']): ?>
                            <span class="entry-tag entry-tag--warn-pill">throttled</span>
                        <?php endif; ?>
                    </div>
                    <div class="entry-content entry-content--mono-sm">
                        <?php if ($s['last'] === null): ?>
                            Never run.
                        <?php else: ?>
                            last_run: <?= e((string)$lastWhen) ?>
                            · items: <?= (int)$s['last']['item_count'] ?>
                            · duration: <?= (int)$s['last']['duration_ms'] ?> ms
                            <?php if ($nextWhen !== null): ?>
                                · next allowed: <?= e($nextWhen) ?>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (!empty($s['last']['error_message'])): ?>
                            <?php
                            $isPartialRun = (($s['last']['status'] ?? '') === 'warn');
                            $runMsgClass = $isPartialRun ? 'diag-inline-warn' : 'diag-inline-error';
                            $runMsgLabel = $isPartialRun ? 'partial' : 'error';
                            ?>
                            <div class="<?= e($runMsgClass) ?>"><?= e($runMsgLabel) ?>: <?= e((string)$s['last']['error_message']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="entry-actions diag-actions">
                        <form method="post" action="<?= e($basePath) ?>/index.php?action=refresh_plugin" class="admin-inline-form">
                            <?= $csrfField ?>
                            <input type="hidden" name="plugin_id" value="<?= e($s['id']) ?>">
                            <button type="submit" class="btn btn-secondary"<?= $satellite ? ' disabled' : '' ?>>Refresh now</button>
                        </form>
                    </div>
                    <?php
                    $hist = $diagRunHistory[$id] ?? [];
                    require __DIR__ . '/plugin_recent_runs.php';
                    ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="latest-entries-section">
            <h2 class="section-title">Plugins (<?= count($diagStatus) ?>)</h2>

            <?php if ($diagStatus === []): ?>
                <div class="empty-state"><p>No plugins registered.</p></div>
            <?php else: ?>
                <?php foreach ($diagStatus as $id => $s): ?>
                    <?php
                        $cardClass = $diagCardClass($s['last']);
                        $lastStatus = $s['last'] === null ? 'never run' : (string)($s['last']['status'] ?? '');
                        $lastStatusLabel = $s['last'] === null ? 'never run' : $diagRunStatusLabel($lastStatus);
                        $lastWhen   = $s['last'] !== null ? seismo_format_utc($s['last']['run_at']) : null;
                        $nextWhen   = $s['next_allowed'] !== null ? seismo_format_utc($s['next_allowed']) : null;
                    ?>
                    <div class="entry-card <?= e($cardClass) ?>">
                        <div class="entry-header">
                            <span class="entry-tag entry-tag--surface">
                                <strong><?= e($s['label']) ?></strong>
                                <span class="entry-muted">(<?= e($s['id']) ?>)</span>
                            </span>
                            <span class="entry-tag entry-tag--meta">
                                family: <?= e($s['entry_type']) ?>
                            </span>
                            <span class="entry-tag entry-tag--meta">
                                throttle: <?= $s['min_interval'] > 0 ? e((string)round($s['min_interval'] / 60)) . ' min' : 'none' ?>
                            </span>
                            <span class="entry-tag entry-tag--surface entry-tag--emphasis">
                                last: <?= e($lastStatusLabel) ?>
                            </span>
                            <?php if ($s['is_throttled']): ?>
                                <span class="entry-tag entry-tag--warn-pill">throttled</span>
                            <?php endif; ?>
                        </div>

                        <div class="entry-content entry-content--mono-sm">
                            <?php if ($s['last'] === null): ?>
                                Never run.
                            <?php else: ?>
                                last_run: <?= e((string)$lastWhen) ?>
                                · items: <?= (int)$s['last']['item_count'] ?>
                                · duration: <?= (int)$s['last']['duration_ms'] ?> ms
                                <?php if ($nextWhen !== null): ?>
                                    · next allowed: <?= e($nextWhen) ?>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if (!empty($s['last']['error_message'])): ?>
                                <?php
                                $isPartialRun = (($s['last']['status'] ?? '') === 'warn');
                                $runMsgClass = $isPartialRun ? 'diag-inline-warn' : 'diag-inline-error';
                                $runMsgLabel = $isPartialRun ? 'partial' : 'error';
                                ?>
                                <div class="<?= e($runMsgClass) ?>">
                                    <?= e($runMsgLabel) ?>: <?= e((string)$s['last']['error_message']) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="entry-actions diag-actions">
                            <div class="admin-table-actions">
                                <form method="post" action="<?= e($basePath) ?>/index.php?action=refresh_plugin" class="admin-inline-form">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="plugin_id" value="<?= e($s['id']) ?>">
                                    <button type="submit" class="btn btn-secondary"<?= $satellite ? ' disabled' : '' ?>>Refresh now</button>
                                </form>
                                <form method="post" action="<?= e($basePath) ?>/index.php?action=plugin_test" class="admin-inline-form">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="plugin_id" value="<?= e($s['id']) ?>">
                                    <button type="submit" class="btn btn-secondary"<?= $satellite ? ' disabled' : '' ?>>Test fetch (no save)</button>
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
