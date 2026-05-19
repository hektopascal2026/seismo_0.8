<?php
/**
 * Settings → Magnitu tab.
 *
 * @var string $csrfField
 * @var string $basePath
 * @var array<string, string|null> $magnituConfig
 * @var array{total:int, magnitu:int, recipe:int} $magnituScoreStats
 * @var int $magnituTrainingLabelCount
 * @var string $seismoApiUrl
 */

declare(strict_types=1);
?>
        <div class="latest-entries-section magnitu-settings-stack">
            <h2 class="section-title">Magnitu</h2>
            <p class="admin-intro">
                ML-powered relevance scoring. Connect to your Magnitu instance, manage the API key, and clear the local score table when you want to start fresh.
            </p>

            <div class="magnitu-panel">
                <div class="magnitu-field-block">
                    <label for="magnituApiKey" class="magnitu-field-label">API key</label>
                    <div class="magnitu-copy-row">
                        <input type="text" id="magnituApiKey"
                               value="<?= e((string)($magnituConfig['api_key'] ?? '')) ?>"
                               readonly
                               class="search-input magnitu-readonly-key"
                               onclick="this.select(); document.execCommand('copy');"
                               title="Click to copy">
                        <form method="post" action="<?= e($basePath) ?>/index.php?action=settings_regenerate_magnitu_key" class="admin-inline-form">
                            <?= $csrfField ?>
                            <button type="submit" class="btn btn-warning" onclick="return confirm('Regenerate the API key? Magnitu will need the new key before it can sync again.');">Regenerate</button>
                        </form>
                    </div>
                    <div class="magnitu-field-hint">Click the key to copy. Paste it into Magnitu's <code>magnitu_config.json</code> as <code>api_key</code>.</div>
                </div>

                <div class="magnitu-field-block">
                    <label for="seismoApiUrl" class="magnitu-field-label">Seismo API URL (for Magnitu)</label>
                    <input type="text" id="seismoApiUrl"
                           value="<?= e($seismoApiUrl) ?>"
                           readonly
                           class="search-input magnitu-readonly-url"
                           onclick="this.select(); document.execCommand('copy');"
                           title="Click to copy">
                    <div class="magnitu-field-hint">Paste into Magnitu's <code>magnitu_config.json</code> as <code>seismo_url</code>.</div>
                </div>
            </div>

            <div class="magnitu-panel">
                <h3 class="section-title">Scoring state</h3>
                <div class="magnitu-stats-grid magnitu-stats-grid--four">
                    <div class="magnitu-stat-tile">
                        <div class="magnitu-stat-value"><?= (int)$magnituScoreStats['total'] ?></div>
                        <div class="magnitu-stat-label">Entries scored</div>
                    </div>
                    <div class="magnitu-stat-tile">
                        <div class="magnitu-stat-value"><?= (int)$magnituScoreStats['magnitu'] ?></div>
                        <div class="magnitu-stat-label">By Magnitu (full model)</div>
                    </div>
                    <div class="magnitu-stat-tile">
                        <div class="magnitu-stat-value"><?= (int)$magnituScoreStats['recipe'] ?></div>
                        <div class="magnitu-stat-label">By Recipe (keywords)</div>
                    </div>
                    <div class="magnitu-stat-tile">
                        <div class="magnitu-stat-value"><?= (int)($magnituTrainingLabelCount ?? 0) ?></div>
                        <div class="magnitu-stat-label">Training labels</div>
                    </div>
                </div>

                <?php if (!empty($magnituConfig['last_sync_at'])): ?>
                    <?php
                    $lastSyncRaw = trim((string)$magnituConfig['last_sync_at']);
                    $lastSyncShown = $lastSyncRaw;
                    try {
                        $dtUtc = new \DateTimeImmutable($lastSyncRaw, new \DateTimeZone('UTC'));
                        $lastSyncShown = seismo_format_utc($dtUtc, 'd.m.Y H:i T') ?? $lastSyncRaw;
                    } catch (\Throwable) {
                    }
                    ?>
                    <div class="magnitu-sync-line">
                        Last sync: <strong><?= e($lastSyncShown) ?></strong>
                        &middot; Recipe version: <strong><?= e((string)($magnituConfig['recipe_version'] ?? '0')) ?></strong>
                    </div>
                <?php else: ?>
                    <div class="magnitu-sync-line entry-muted">
                        No sync yet. Connect Magnitu using the API key and URL above.
                    </div>
                <?php endif; ?>

                <?php if (!empty($magnituConfig['model_name'])): ?>
                <div class="magnitu-model-block">
                    <div class="magnitu-model-kicker">Connected model</div>
                    <div class="magnitu-model-head">
                        <span class="magnitu-model-name"><?= e((string)$magnituConfig['model_name']) ?></span>
                        <?php if (!empty($magnituConfig['model_version'])): ?>
                            <span class="magnitu-model-version">v<?= e((string)$magnituConfig['model_version']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($magnituConfig['model_description'])): ?>
                        <div class="magnitu-model-desc"><?= e((string)$magnituConfig['model_description']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($magnituConfig['model_trained_at'])): ?>
                        <div class="magnitu-model-trained">
                            Last trained: <strong><?= e(substr((string)$magnituConfig['model_trained_at'], 0, 16)) ?></strong>
                        </div>
                    <?php endif; ?>
                    <div class="magnitu-model-foot">Model files are managed in the Magnitu app.</div>
                </div>
                <?php endif; ?>
            </div>

            <form method="post" action="<?= e($basePath) ?>/index.php?action=settings_save_magnitu">
                <?= $csrfField ?>
                <div class="magnitu-panel">
                    <h3 class="section-title">Scoring preferences</h3>
                    <p class="magnitu-field-hint magnitu-field-hint--spaced">
                        These preferences are stored now so Magnitu sync can read them, but the 0.5 timeline and calendar don't yet react to them — wiring lands in a later slice.
                    </p>

                    <div class="magnitu-prefs-grid">
                        <div>
                            <label for="alert_threshold" class="magnitu-field-label">Alert threshold (0.0 – 1.0)</label>
                            <input type="number" id="alert_threshold" name="alert_threshold"
                                   value="<?= e((string)($magnituConfig['alert_threshold'] ?? '0.60')) ?>"
                                   min="0" max="1" step="0.05"
                                   class="search-input" style="width:100%;">
                            <div class="magnitu-field-hint">Entries scoring above this will be flagged as alerts. Default lowered from 0.75 → 0.60 in May 2026 (see README "Scoring tuning"); raise it again once Magnitu's distiller emits stronger anchor-concept weights.</div>
                        </div>
                        <div>
                            <label class="magnitu-field-label">Default sort</label>
                            <label class="magnitu-sort-label">
                                <input type="checkbox" name="sort_by_relevance" value="1"
                                       <?= ((string)($magnituConfig['sort_by_relevance'] ?? '0')) === '1' ? 'checked' : '' ?>>
                                <span>Sort timeline by relevance instead of chronologically</span>
                            </label>
                        </div>
                    </div>

                    <div class="admin-form-actions">
                        <button type="submit" class="btn btn-success">Save preferences</button>
                    </div>
                </div>
            </form>

            <div class="magnitu-panel magnitu-panel--danger">
                <h3 class="section-title">Danger zone</h3>
                <p class="admin-intro">
                    Delete every row in <code>entry_scores</code> and reset the scoring recipe. The timeline goes back to chronological order. Magnitu's local labels (in the Magnitu app) are untouched and can be re-pushed.
                </p>
                <form method="post" action="<?= e($basePath) ?>/index.php?action=settings_clear_magnitu_scores" class="admin-inline-form">
                    <?= $csrfField ?>
                    <button type="submit" class="btn btn-danger" onclick="return confirm('Clear all Magnitu scores and recipe? This cannot be undone.');">
                        Clear all scores
                    </button>
                </form>
            </div>
        </div>
