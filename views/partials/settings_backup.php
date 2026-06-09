<?php
/**
 * Settings → Backup tab: Database SQL export, Source config JSON export and import.
 *
 * @var string $csrfField
 * @var string $basePath
 */

declare(strict_types=1);
?>

<div class="latest-entries-section">
    <h2 class="section-title">Database Backup</h2>
    <p class="admin-intro">
        Download a full SQL dump of the database (tables structure and data).
        This includes all source configurations, items, email configurations, legislative/parliamentary documents, and system configuration.
    </p>
    <p class="admin-form-actions">
        <a class="btn btn-success" href="<?= e($basePath) ?>/index.php?action=export_database_sql">Download database SQL dump</a>
    </p>
</div>

<div class="latest-entries-section module-section-spaced">
    <h2 class="section-title">Source config backup</h2>
    <p class="admin-intro">
        Download one JSON bundle with Feeds sources, Scraper sources, Mail subscriptions, and related
        <code>system_config</code> rows. Useful before host migrations or for a lighter-weight backup of just source rules.
    </p>
    <p class="message message-warning">
        This export includes sensitive values (for example OAuth tokens and API-related settings). Store it securely.
    </p>
    <p class="admin-form-actions">
        <a class="btn btn-secondary" href="<?= e($basePath) ?>/index.php?action=export_source_configs">Download source config bundle</a>
    </p>

    <h3 class="section-title" style="font-size:1rem; margin-top:1.5rem;">Import feeds + scraper only</h3>
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
