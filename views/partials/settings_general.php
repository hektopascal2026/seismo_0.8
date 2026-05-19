<?php
/**
 * Settings → General tab: timeline, web migrations, session admin password.
 *
 * @var string $csrfField
 * @var string $basePath
 * @var int $dashboardLimitSaved
 * @var int $dashboardLimitMax
 * @var bool $migrateKeyConfigured
 * @var bool $configLocalWritable
 * @var ?string $pendingMigrateKey
 * @var ?string $migrateKeyPasteBlock
 * @var ?string $adminPasswordPasteBlock
 * @var bool $sessionAuthEnabled
 * @var bool $navLeadingThrottleOn
 * @var bool $legacyRssScraperRefresh
 */

declare(strict_types=1);
?>
        <div class="latest-entries-section">
            <h2 class="section-title">Timeline</h2>
            <p class="admin-intro">
                Default number of entries on the main timeline when you open the dashboard without a <code>?limit=</code> query parameter. You can still override per visit (1–<?= (int)$dashboardLimitMax ?>).
            </p>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=settings_save" class="admin-form-card">
                <?= $csrfField ?>
                <div class="admin-form-field">
                    <label for="dashboard_limit">Entries per page</label>
                    <input type="number" id="dashboard_limit" name="dashboard_limit" min="1" max="<?= (int)$dashboardLimitMax ?>"
                           value="<?= (int)$dashboardLimitSaved ?>"
                           class="search-input" style="width:7rem;">
                </div>
                <div class="admin-form-field">
                    <input type="hidden" name="nav_leading_throttle" value="0">
                    <label>
                        <input type="checkbox" name="nav_leading_throttle" value="1" <?= !empty($navLeadingThrottleOn) ? 'checked' : '' ?>>
                        Throttle rapid navigation (500ms lock after each main-menu or Settings tab click; reduces duplicate full-page requests on slow or strict hosts; first click is never delayed)
                    </label>
                </div>
                <?php if (empty($satellite)): ?>
                <div class="admin-form-field">
                    <label>
                        <input type="checkbox" name="legacy_rss_scraper_refresh" value="1" <?= !empty($legacyRssScraperRefresh) ? 'checked' : '' ?>>
                        Legacy RSS &amp; scraper refresh (fetch every source in one run — can hit PHP time limits with hundreds of feeds)
                    </label>
                    <p class="admin-intro" style="margin-top:0.5rem;">
                        Default is <strong>chunked</strong> refresh: each cron tick (and each manual refresh up to a short time budget) pulls a limited batch; the cycle continues in the background. Turn this on only if you need the old single-pass behaviour.
                    </p>
                </div>
                <?php endif; ?>
                <div class="admin-form-actions">
                    <button type="submit" class="btn btn-success">Save</button>
                </div>
            </form>
        </div>

        <?php if (empty($satellite)): ?>
        <div class="latest-entries-section module-section-spaced">
            <h2 class="section-title">Source config export</h2>
            <p class="admin-intro">
                Download one JSON bundle with Feeds sources, Scraper sources, Mail subscriptions, and related
                <code>system_config</code> rows. Useful before host migrations.
            </p>
            <p class="message message-warning">
                This export includes sensitive values (for example OAuth tokens and API-related settings). Store it securely.
            </p>
            <p class="admin-form-actions">
                <a class="btn btn-secondary" href="<?= e($basePath) ?>/index.php?action=export_source_configs">Download source config bundle</a>
            </p>
        </div>
        <?php endif; ?>

        <div class="latest-entries-section module-section-spaced">
            <h2 class="section-title">Web migrations</h2>
            <p class="admin-intro">
                When <code>SEISMO_MIGRATE_KEY</code> is set in <code>config.local.php</code>, you can run schema upgrades from the browser:
                <a href="<?= e($basePath) ?>/index.php?action=migrate">?action=migrate</a>
                (pass the key via <code>Authorization: Bearer</code>, POST body <code>key=</code>, or query — see <code>MigrateController</code>).
                The key is a secret; it is never shown here after being saved.
            </p>
            <p class="admin-intro">
                Status:
                <?php if ($migrateKeyConfigured): ?>
                    <strong>configured</strong> (value hidden).
                <?php else: ?>
                    <strong>not set</strong> — CLI <code>php migrate.php</code> still works if you have shell access.
                <?php endif; ?>
                <?php if (!$configLocalWritable): ?>
                    <span class="settings-bad">config.local.php is not writable by PHP — you must edit the file manually.</span>
                <?php endif; ?>
            </p>

            <form method="post" action="<?= e($basePath) ?>/index.php?action=settings_generate_migrate_key" class="admin-inline-form admin-form-field">
                <?= $csrfField ?>
                <button type="submit" class="btn btn-secondary">Generate random migrate key</button>
            </form>

            <?php if ($pendingMigrateKey !== null && $pendingMigrateKey !== ''): ?>
                <div class="admin-form-card" style="margin-top:1rem;">
                    <p class="admin-intro">Save this key to <code>config.local.php</code> (or use the button if the file is writable).</p>
                    <form method="post" action="<?= e($basePath) ?>/index.php?action=settings_save_migrate_key" class="admin-form-card">
                        <?= $csrfField ?>
                        <input type="hidden" name="migrate_key" value="<?= e($pendingMigrateKey) ?>">
                        <div class="admin-form-actions">
                            <button type="submit" class="btn btn-success"<?= $configLocalWritable ? '' : ' disabled' ?>>Write SEISMO_MIGRATE_KEY to config.local.php</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($migrateKeyPasteBlock !== null && $migrateKeyPasteBlock !== ''): ?>
                <h3 class="section-title" style="font-size:1rem; margin-top:1rem;">Paste into config.local.php</h3>
                <textarea id="seismo-migrate-paste" readonly rows="3" class="search-input setup-code-preview" style="width:100%; max-width:40rem;"><?= e($migrateKeyPasteBlock) ?></textarea>
                <p class="admin-form-actions">
                    <button type="button" class="btn btn-secondary" id="seismo-migrate-copy">Copy line</button>
                </p>
                <script>
                (function() {
                    var ta = document.getElementById('seismo-migrate-paste');
                    var btn = document.getElementById('seismo-migrate-copy');
                    if (!btn || !ta) return;
                    btn.addEventListener('click', function() {
                        var text = ta.value || '';
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(text).then(function() {
                                btn.textContent = 'Copied';
                                setTimeout(function() { btn.textContent = 'Copy line'; }, 2000);
                            });
                            return;
                        }
                        ta.select();
                        try { document.execCommand('copy'); btn.textContent = 'Copied'; } catch (e) {}
                        setTimeout(function() { btn.textContent = 'Copy line'; }, 2000);
                    });
                })();
                </script>
            <?php endif; ?>
        </div>

        <div class="latest-entries-section module-section-spaced">
            <h2 class="section-title">Session admin password</h2>
            <p class="admin-intro">
                When <code>SEISMO_ADMIN_PASSWORD_HASH</code> is set in <code>config.local.php</code>, the web UI requires login (Magnitu / export APIs still use Bearer tokens).
                Only a <code>password_hash()</code> output is stored — never plaintext.
            </p>
            <p class="admin-intro">
                Status: <strong><?= $sessionAuthEnabled ? 'enforced' : 'dormant (no hash configured)' ?></strong>
                <?php if (!$configLocalWritable): ?>
                    <span class="settings-bad">config.local.php is not writable by PHP.</span>
                <?php endif; ?>
            </p>

            <form method="post" action="<?= e($basePath) ?>/index.php?action=settings_save_admin_password" class="admin-form-card">
                <?= $csrfField ?>
                <?php if ($sessionAuthEnabled): ?>
                <div class="admin-form-field">
                    <label for="current_admin_password">Current password</label>
                    <input type="password" id="current_admin_password" name="current_admin_password" autocomplete="current-password" class="search-input" style="width:100%; max-width:28rem;">
                </div>
                <?php endif; ?>
                <div class="admin-form-field">
                    <label for="new_admin_password">New password (min 8 characters)</label>
                    <input type="password" id="new_admin_password" name="new_admin_password" autocomplete="new-password" class="search-input" style="width:100%; max-width:28rem;" required minlength="8">
                </div>
                <div class="admin-form-field">
                    <label for="new_admin_password_confirm">Confirm new password</label>
                    <input type="password" id="new_admin_password_confirm" name="new_admin_password_confirm" autocomplete="new-password" class="search-input" style="width:100%; max-width:28rem;" required minlength="8">
                </div>
                <div class="admin-form-actions">
                    <button type="submit" class="btn btn-success"><?= $sessionAuthEnabled ? 'Change password' : 'Save password (enables auth)' ?></button>
                </div>
            </form>

            <?php if ($adminPasswordPasteBlock !== null && $adminPasswordPasteBlock !== ''): ?>
                <h3 class="section-title" style="font-size:1rem; margin-top:1rem;">Paste into config.local.php</h3>
                <textarea id="seismo-admin-paste" readonly rows="3" class="search-input setup-code-preview" style="width:100%; max-width:40rem;"><?= e($adminPasswordPasteBlock) ?></textarea>
                <p class="admin-form-actions">
                    <button type="button" class="btn btn-secondary" id="seismo-admin-copy">Copy line</button>
                </p>
                <script>
                (function() {
                    var ta = document.getElementById('seismo-admin-paste');
                    var btn = document.getElementById('seismo-admin-copy');
                    if (!btn || !ta) return;
                    btn.addEventListener('click', function() {
                        var text = ta.value || '';
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(text).then(function() {
                                btn.textContent = 'Copied';
                                setTimeout(function() { btn.textContent = 'Copy line'; }, 2000);
                            });
                            return;
                        }
                        ta.select();
                        try { document.execCommand('copy'); btn.textContent = 'Copied'; } catch (e) {}
                        setTimeout(function() { btn.textContent = 'Copy line'; }, 2000);
                    });
                })();
                </script>
            <?php endif; ?>
        </div>

        <div class="latest-entries-section module-section-spaced">
            <h2 class="section-title">Display timezone</h2>
            <p class="admin-intro">
                Timestamps are stored in UTC. Day groupings (“Heute”, “Gestern”) and clock times in the UI use the timezone from
                <code>SEISMO_VIEW_TIMEZONE</code> in <code>config.local.php</code> (default <code>Europe/Zurich</code>).
            </p>
        </div>
