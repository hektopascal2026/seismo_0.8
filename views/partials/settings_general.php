<?php
/**
 * Settings → General tab: timeline, session admin password.
 *
 * @var string $csrfField
 * @var string $basePath
 * @var int $dashboardLimitSaved
 * @var int $dashboardLimitMax
 * @var bool $configLocalWritable
 * @var ?string $adminPasswordPasteBlock
 * @var bool $sessionAuthEnabled
 * @var bool $adminBootstrapTokenConfigured
 * @var bool $navLeadingThrottleOn
 * @var bool $legacyRssScraperRefresh
 * @var bool $geminiApiKeyOnFile Gemini key stored in local system_config
 * @var ?array $dbStats
 */

declare(strict_types=1);
?>
        <?php if (!empty($dbStats)): ?>
        <div class="latest-entries-section">
            <h2 class="section-title">Database Overview</h2>
            <div class="admin-form-card" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: 1.5rem; border-color: #000000; margin-bottom: 1.5rem;">
                <div>
                    <strong style="display: block; font-size: 0.75rem; text-transform: uppercase; color: #666666; margin-bottom: 0.25rem;">Database Size</strong>
                    <span style="font-size: 1.5rem; font-weight: 700;"><?= number_format($dbStats['size_mb'], 2) ?> MB</span>
                </div>
                <div>
                    <strong style="display: block; font-size: 0.75rem; text-transform: uppercase; color: #666666; margin-bottom: 0.25rem;">Total Sources</strong>
                    <span style="font-size: 1.5rem; font-weight: 700;"><?= number_format($dbStats['total_sources']) ?></span>
                    <span style="display: block; font-size: 0.75rem; color: #666666; margin-top: 0.25rem; line-height: 1.3;">
                        <?= $dbStats['feeds_count'] ?> feeds<br>
                        <?= $dbStats['scrapers_count'] ?> scrapers<br>
                        <?= $dbStats['email_subs_count'] ?> mail subs
                    </span>
                </div>
                <div>
                    <strong style="display: block; font-size: 0.75rem; text-transform: uppercase; color: #666666; margin-bottom: 0.25rem;">Total Items</strong>
                    <span style="font-size: 1.5rem; font-weight: 700;"><?= number_format($dbStats['total_items']) ?></span>
                    <span style="display: block; font-size: 0.75rem; color: #666666; margin-top: 0.25rem; line-height: 1.3;">
                        <?= number_format($dbStats['feed_items_count']) ?> feed items<br>
                        <?= number_format($dbStats['emails_count']) ?> emails<br>
                        <?= number_format($dbStats['lex_count']) ?> legislative docs<br>
                        <?= number_format($dbStats['calendar_count']) ?> parliamentary events
                    </span>
                </div>
            </div>
        </div>
        <?php endif; ?>

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
                <div class="admin-form-field">
                    <label for="gemini_api_key">Gemini API key (AI Researcher)</label>
                    <input type="password" id="gemini_api_key" name="gemini_api_key" class="search-input" style="width:100%; max-width:28rem;"
                           value="" placeholder="Leave blank to keep current key" autocomplete="off">
                    <?php if (!empty($geminiApiKeyOnFile)): ?>
                        <div class="magnitu-field-hint">A Gemini API key is already stored. Used server-side only for <code>?action=researcher</code><?= !empty($satellite) ? ' on this desk' : '' ?>.</div>
                    <?php else: ?>
                        <div class="magnitu-field-hint">Required for the AI Researcher page. Create a key in <a href="https://aistudio.google.com/apikey" rel="noopener noreferrer">Google AI Studio</a>.</div>
                    <?php endif; ?>
                </div>
                <div class="admin-form-actions">
                    <button type="submit" class="btn btn-success">Save</button>
                </div>
            </form>
        </div>

        <div class="latest-entries-section module-section-spaced">
            <h2 class="section-title">AI Researcher prompts</h2>
            <p class="admin-intro">
                Restores the built-in <strong>Default</strong> and <strong>Swissmem</strong> prompt texts shipped with this Seismo version
                (library tabs and the desk default used when you click <strong>Save prompt (default)</strong> on
                <a href="<?= e($basePath) ?>/index.php?action=researcher">AI Researcher</a>).
            </p>
            <p class="message message-warning">
                Overwrites only those two built-in prompts. Custom tabs in your prompt library keep their names, IDs, and content unchanged.
            </p>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=settings_restore_researcher_builtin_prompts" class="admin-inline-form">
                <?= $csrfField ?>
                <button type="submit" class="btn btn-danger"
                        onclick="return confirm('Restore built-in Default and Swissmem prompts to the version shipped with this app?\n\nYour other saved prompts will not be deleted or changed.');">
                    Restore default prompts
                </button>
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

            <h3 class="section-title" style="font-size:1rem; margin-top:1rem;">Import feeds + scraper only</h3>
            <p class="admin-intro">
                Imports only <code>feeds_module_sources</code> and <code>scraper_configs</code> from a JSON payload.
                Existing rows are updated by URL; missing rows are inserted.
            </p>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=import_source_configs" class="admin-form-card" enctype="multipart/form-data">
                <?= $csrfField ?>
                <div class="admin-form-field">
                    <label for="source_config_file">Upload export JSON</label>
                    <input type="file" id="source_config_file" name="source_config_file" accept=".json,application/json" class="search-input" style="width:100%;">
                </div>
                <div class="admin-form-field">
                    <label for="source_config_json">Or paste JSON</label>
                    <textarea id="source_config_json" name="source_config_json" rows="8" class="search-input" style="width:100%;" placeholder='{"feeds_module_sources":[...],"scraper_configs":[...]}'></textarea>
                </div>
                <div class="admin-form-actions">
                    <button type="submit" class="btn btn-success">Import feeds + scraper configs</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

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
            <?php if (!$sessionAuthEnabled && !$adminBootstrapTokenConfigured): ?>
            <p class="admin-intro message message-info">
                While auth is dormant, set <code>SEISMO_ADMIN_PASSWORD_HASH</code> in <code>config.local.php</code> manually
                (run <code>php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"</code>),
                or define <code>SEISMO_ADMIN_SETUP_TOKEN</code> for a one-time web setup below.
            </p>
            <?php endif; ?>

            <form method="post" action="<?= e($basePath) ?>/index.php?action=settings_save_admin_password" class="admin-form-card">
                <?= $csrfField ?>
                <?php if (!$sessionAuthEnabled && $adminBootstrapTokenConfigured): ?>
                <div class="admin-form-field">
                    <label for="admin_setup_token">One-time setup token</label>
                    <input type="password" id="admin_setup_token" name="admin_setup_token" autocomplete="off" class="search-input" style="width:100%; max-width:28rem;" required>
                    <p class="meta-text">Must match <code>SEISMO_ADMIN_SETUP_TOKEN</code> in config.local.php.</p>
                </div>
                <?php endif; ?>
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
                    <?php if ($sessionAuthEnabled || $adminBootstrapTokenConfigured): ?>
                    <button type="submit" class="btn btn-success"><?= $sessionAuthEnabled ? 'Change password' : 'Save password (enables auth)' ?></button>
                    <?php else: ?>
                    <p class="meta-text">Web password setup is disabled until you add a setup token or hash in config.local.php.</p>
                    <?php endif; ?>
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
