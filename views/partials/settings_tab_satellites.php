<?php
/**
 * Settings → Satellites (mothership only).
 *
 * @var string $csrfField
 * @var string $basePath
 * @var list<array<string, mixed>> $satellitesRegistry
 * @var string $satellitesMothershipUrl
 * @var string $satellitesMothershipDb
 * @var string $satellitesHighlightSlug
 */

declare(strict_types=1);

$bp = $basePath;
?>
        <div class="latest-entries-section">
            <h2 class="section-title">Satellites</h2>
            <p class="admin-intro">
                Path satellites share this codebase and read entries from the <code><?= e($satellitesMothershipDb) ?></code> database.
                Each desk gets its own scores database (<code>seismo_&lt;slug&gt;</code>) and URL under <code><?= e($satellitesMothershipUrl) ?>/&lt;slug&gt;/</code>.
            </p>
            <p class="admin-intro message message-info" style="margin-top: 0.75rem;">
                After adding a row here, SSH to the VPS and run:
                <code class="settings-code-inline">bin/seismo-satellite-provision.sh &lt;slug&gt;</code>
                (creates the database, migrations, and <code>/&lt;slug&gt;/</code> stub if missing).
            </p>

            <?php if ($satellitesRegistry === []): ?>
                <p class="admin-intro">No satellites registered yet. Add <code>security</code> or <code>digital</code> below, then run the provision script on the server.</p>
            <?php else: ?>
                <div class="settings-satellite-table-wrap">
                    <table class="settings-satellite-table">
                        <thead>
                            <tr>
                                <th>Slug</th>
                                <th>URL</th>
                                <th>Display name</th>
                                <th>Scores DB</th>
                                <th>Status</th>
                                <th class="settings-satellite-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($satellitesRegistry as $sat): ?>
                                <?php
                                $slug = (string)($sat['slug'] ?? '');
                                $isHighlight = $slug !== '' && $slug === $satellitesHighlightSlug;
                                $mount = (string)($sat['mount_path'] ?? '/' . $slug);
                                $dbName = (string)($sat['db_name'] ?? 'seismo_' . $slug);
                                $status = (string)($sat['status'] ?? 'pending');
                                ?>
                                <tr class="<?= $isHighlight ? 'settings-satellite-row-highlight' : '' ?>">
                                    <td><code><?= e($slug) ?></code></td>
                                    <td>
                                        <a href="<?= e(rtrim($satellitesMothershipUrl, '/') . $mount . '/') ?>" target="_blank" rel="noopener">
                                            <?= e($mount) ?>/
                                        </a>
                                    </td>
                                    <td>
                                        <?php
                                        $dn = (string)($sat['display_name'] ?? '');
                                        $accentHex = trim((string)($sat['brand_accent'] ?? ''));
                                        $brandParts = seismoBrandDisplaySplit($dn);
                                        ?>
                                        <?php if ($brandParts !== null): ?>
                                            <span class="settings-satellite-brand-prefix"><?= e($brandParts[0]) ?></span><span class="settings-satellite-brand-suffix"<?= $accentHex !== '' ? ' style="color: ' . e($accentHex) . '"' : '' ?>><?= e(' ' . $brandParts[1]) ?></span>
                                        <?php else: ?>
                                            <?= e($dn) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><code><?= e($dbName) ?></code></td>
                                    <td class="settings-satellite-meta"><?= e($status) ?></td>
                                    <td class="settings-satellite-actions">
                                        <form method="post" action="<?= e($bp) ?>/index.php?action=satellite_rotate_key" class="admin-inline-form"
                                              onsubmit="return confirm('Rotate API key for <?= e($slug) ?>? Update Magnitu with the new key.');">
                                            <?= $csrfField ?>
                                            <input type="hidden" name="slug" value="<?= e($slug) ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm">Rotate key</button>
                                        </form>
                                        <form method="post" action="<?= e($bp) ?>/index.php?action=satellite_remove" class="admin-inline-form"
                                              onsubmit="return confirm('Remove <?= e($slug) ?> from registry?');">
                                            <?= $csrfField ?>
                                            <input type="hidden" name="slug" value="<?= e($slug) ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <details class="settings-satellite-add" <?= $satellitesRegistry === [] ? 'open' : '' ?>>
                <summary class="settings-satellite-add-summary">Add a satellite</summary>
                <form method="post" action="<?= e($bp) ?>/index.php?action=satellite_add" class="admin-form-card settings-satellite-form">
                    <?= $csrfField ?>
                    <div class="admin-form-field">
                        <label for="sat_slug">Slug</label>
                        <input type="text" id="sat_slug" name="slug" required pattern="[a-z0-9-]+" maxlength="40" placeholder="security" class="search-input">
                    </div>
                    <div class="admin-form-field">
                        <label for="sat_name">Display name</label>
                        <input type="text" id="sat_name" name="display_name" maxlength="80" placeholder="Security" class="search-input" aria-describedby="sat_name_hint">
                        <p id="sat_name_hint" class="admin-intro" style="margin-top:0.35rem; font-size:0.85rem;">Suffix after &quot;Seismo&quot; (stored as Seismo …).</p>
                    </div>
                    <div class="admin-form-field">
                        <label for="sat_profile">Magnitu profile</label>
                        <input type="text" id="sat_profile" name="magnitu_profile" maxlength="40" pattern="[a-z0-9-]+" placeholder="(same as slug)" class="search-input">
                    </div>
                    <div class="admin-form-field">
                        <label for="sat_accent">Brand accent (optional)</label>
                        <input type="text" id="sat_accent" name="brand_accent" maxlength="9" placeholder="#4a90e2" pattern="#[0-9a-fA-F]{3,8}" class="search-input">
                    </div>
                    <div class="admin-form-field">
                        <label>
                            <input type="checkbox" name="remote_refresh" value="1" checked>
                            Remote refresh
                        </label>
                        <p class="admin-intro" style="margin-top:0.35rem; font-size:0.85rem;">When enabled, this desk’s Refresh button triggers mothership ingest (shared secret in <code>system_config</code>).</p>
                    </div>
                    <div class="admin-form-actions">
                        <button type="submit" class="btn btn-success">Add satellite</button>
                    </div>
                </form>
            </details>

            <div class="settings-satellite-context">
                <strong>Mothership</strong>
                <ul>
                    <li>Entries DB: <code><?= e($satellitesMothershipDb) ?></code></li>
                    <li>Base URL: <code><?= e($satellitesMothershipUrl) ?></code></li>
                </ul>
            </div>
        </div>
