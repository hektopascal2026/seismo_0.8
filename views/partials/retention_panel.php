<?php
/**
 * Retention policy grid (embedded in Settings → Retention tab or standalone legacy view).
 *
 * @var array<string, array{days: ?int, keep: list<string>, would_delete: ?int}> $rows
 * @var list<string> $families
 * @var array<string, array{days: ?int, keep: list<string>}> $defaults
 * @var string $csrfField
 * @var string $basePath
 * @var bool $satellite
 */

declare(strict_types=1);

use Seismo\Service\RetentionService;

$familyLabel = [
    'feed_items'      => 'Feed items (RSS, Substack, scraper)',
    'emails'          => 'Emails',
    'lex_items'       => 'Legal text (Lex)',
    'calendar_events' => 'Leg (parliamentary business)',
];

$keepLabel = [
    RetentionService::KEEP_FAVOURITED => 'Keep favourited',
    RetentionService::KEEP_HIGH_SCORE => 'Keep investigation_lead / important',
    RetentionService::KEEP_LABELLED   => 'Keep manually labelled',
];
?>
        <div class="latest-entries-section module-section-spaced">
            <h2 class="section-title">Policy</h2>
            <p class="admin-intro">
                "Days" is the age (by insertion timestamp) after which rows may be deleted. Leave empty or set to 0 for <em>unlimited retention</em> — the family is skipped entirely. Protected rows are never deleted regardless of age.
            </p>

            <form method="post" action="<?= e($basePath) ?>/index.php?action=retention_save">
                <?= $csrfField ?>
                <table class="retention-table">
                    <thead>
                    <tr>
                        <th>Family</th>
                        <th>Retention (days)</th>
                        <th>Protected rows</th>
                        <th>Would delete today</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($families as $family): ?>
                        <?php
                        $row        = $rows[$family] ?? ['days' => null, 'keep' => $defaults[$family]['keep'] ?? [], 'would_delete' => null];
                        $defaultDays = $defaults[$family]['days'] ?? null;
                        $currentKeep = $row['keep'];
                        ?>
                        <tr>
                            <td>
                                <strong><?= e($familyLabel[$family] ?? $family) ?></strong>
                                <div class="retention-family-note">
                                    default: <?= $defaultDays === null ? 'unlimited' : e((string)$defaultDays) . ' days' ?>
                                </div>
                            </td>
                            <td>
                                <input type="number" min="0" max="3650" step="1"
                                       name="<?= e($family) ?>_days"
                                       value="<?= $row['days'] === null ? '' : e((string)$row['days']) ?>"
                                       placeholder="unlimited"
                                       class="search-input"
                                       style="width:7rem;">
                            </td>
                            <td class="retention-keeps">
                                <?php foreach ($keepLabel as $token => $label): ?>
                                    <label>
                                        <input type="checkbox"
                                               name="<?= e($family) ?>_keep[]"
                                               value="<?= e($token) ?>"
                                               <?= in_array($token, $currentKeep, true) ? 'checked' : '' ?>>
                                        <?= e($label) ?>
                                    </label>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <?php if ($row['would_delete'] === null): ?>
                                    <span class="retention-unlimited">&mdash;</span>
                                <?php else: ?>
                                    <strong><?= e((string)$row['would_delete']) ?></strong>
                                    <?php if ($row['would_delete'] === 0): ?>
                                        <div class="retention-family-note">nothing to prune</div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="admin-form-actions">
                    <button type="submit" class="btn btn-success">Save policies</button>
                    <button type="submit" class="btn btn-secondary" formaction="<?= e($basePath) ?>/index.php?action=retention_preview">Refresh preview</button>
                </div>
            </form>
        </div>

        <div class="latest-entries-section module-section-spaced">
            <h2 class="section-title">Run prune now</h2>
            <p class="admin-intro">
                Runs retention across every family with a configured cutoff, right now. The CLI master cron does the same at the tail of <code>refresh_cron.php</code>. A dry-run is shown above — the real delete count usually matches, give or take rows inserted between preview and click.
            </p>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=retention_prune" class="admin-inline-form">
                <?= $csrfField ?>
                <button type="submit" class="btn btn-danger"<?= $satellite ? ' disabled' : '' ?>
                        onclick="return confirm('Run retention now? Rows matching the policy will be deleted.');">
                    Run retention now
                </button>
            </form>
        </div>
