<?php
/**
 * Diagnostics cards for core / media fetchers (status + refresh + recent runs).
 *
 * @var string $sectionTitle
 * @var string $sectionIntroHtml trusted HTML for admin-intro
 * @var array<string, array<string, mixed>> $diagCards
 * @var array<string, list<array{run_at: \DateTimeImmutable, status: string, item_count: int, error_message: ?string, duration_ms: int}>> $diagRunHistory
 * @var string $csrfField
 * @var string $basePath
 * @var bool $satellite
 * @var callable $diagCardClass
 * @var callable $diagRunStatusLabel
 */

declare(strict_types=1);

if ($diagCards === []) {
    return;
}
?>
        <div class="latest-entries-section module-section-spaced">
            <h2 class="section-title"><?= e($sectionTitle) ?> (<?= count($diagCards) ?>)</h2>
            <p class="admin-intro"><?= $sectionIntroHtml ?></p>
                <?php foreach ($diagCards as $id => $s): ?>
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
                        <?php if (($s['last']['status'] ?? '') === 'skipped' && !empty($s['last_attempt'])): ?>
                            <?php
                            $att = $s['last_attempt'];
                            $attWhen = date('d.m.Y H:i', $att['run_at']->getTimestamp());
                            $attStatusLabel = $diagRunStatusLabel($att['status']);
                            $attMsgClass = ($att['status'] === 'warn') ? 'diag-inline-warn' : 'diag-inline-error';
                            ?>
                            <div class="diag-inline-skipped" style="margin-top: 5px; font-size: 0.9em; opacity: 0.85;">
                                ℹ️ Last attempt (<?= $attWhen ?>): 
                                <span class="<?= e($attMsgClass) ?>" style="display: inline-block; padding: 2px 6px; border-radius: 4px; font-weight: bold; font-size: 0.9em;">
                                    <?= e($attStatusLabel) ?>
                                </span>
                                <?php if (!empty($att['error_message'])): ?>
                                    <br><span class="diag-inline-error" style="display: inline-block; margin-top: 3px;"><?= e((string)$att['error_message']) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="entry-actions diag-actions">
                        <form method="post" action="<?= e($basePath) ?>/index.php?action=refresh_plugin" class="admin-inline-form seismo-ajax-refresh-form">
                            <?= $csrfField ?>
                            <input type="hidden" name="plugin_id" value="<?= e($s['id']) ?>">
                            <button type="submit" class="btn btn-secondary" data-refresh-label="Refresh now"<?= $satellite ? ' disabled' : '' ?>>Refresh now</button>
                        </form>
                    </div>
                    <?php
                    $hist = $diagRunHistory[$id] ?? [];
                    require __DIR__ . '/plugin_recent_runs.php';
                    ?>
                </div>
            <?php endforeach; ?>
        </div>
