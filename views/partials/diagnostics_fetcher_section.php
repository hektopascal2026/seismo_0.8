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
                        <form method="post" action="<?= e($basePath) ?>/index.php?action=refresh_plugin" class="admin-inline-form seismo-ajax-refresh-form">
                            <?= $csrfField ?>
                            <input type="hidden" name="plugin_id" value="<?= e($s['id']) ?>">
                            <button type="submit" class="btn btn-secondary" data-refresh-label="Checking..."<?= $satellite ? ' disabled' : '' ?>>Check now</button>
                        </form>
                    </div>
                    <?php
                    $hist = $diagRunHistory[$id] ?? [];
                    require __DIR__ . '/plugin_recent_runs.php';
                    ?>
                </div>
            <?php endforeach; ?>
        </div>
