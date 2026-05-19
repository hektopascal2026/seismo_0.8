<?php
/**
 * Settings → Satellites (mothership only).
 *
 * @var string $csrfField
 * @var string $basePath
 * @var list<array<string, mixed>> $satellitesRegistry
 * @var string $satellitesMothershipUrl
 * @var string $satellitesMothershipDb
 * @var bool $satellitesRemoteRefreshKeyConfigured
 * @var string $satellitesSuggestedRefreshKey
 * @var string $satellitesHighlightSlug
 */

declare(strict_types=1);

$bp = $basePath;
?>
        <div class="latest-entries-section">
            <h2 class="section-title">Satellites</h2>
            <p class="admin-intro">
                Register lightweight satellite Seismo installs that read entries from this mothership and show them scored by a dedicated Magnitu profile.
                After adding a satellite, download its JSON and run <strong>seismo-generator</strong> (CLI or local GUI) to produce a deployable folder.
            </p>
            <p class="admin-intro message message-info" style="margin-top: 0.75rem;">
                <strong>Two databases on the host.</strong>
                The JSON embeds this mothership’s entry database name (<code><?= e($satellitesMothershipDb) ?></code>) for read-only access.
                Each satellite still needs its <em>own</em> MySQL database for scores and config — create that separately and grant <code>SELECT</code> on the mothership DB plus full access on the satellite DB (see the generated <code>DEPLOY.md</code>).
            </p>

            <?php if (!$satellitesRemoteRefreshKeyConfigured): ?>
            <div class="message message-warning">
                <strong>SEISMO_REMOTE_REFRESH_KEY is not set on this mothership.</strong>
                Satellites can still pull entries, but their &quot;Refresh&quot; button (which calls back into this mothership) will fail.
                <?php if ($satellitesSuggestedRefreshKey !== ''): ?>
                    <div style="margin-top: 0.5rem;">
                        Suggested value:
                        <code class="settings-code-inline"><?= e($satellitesSuggestedRefreshKey) ?></code>
                    </div>
                    <div style="margin-top: 0.5rem; font-size: 0.85rem;">
                        Add to mothership <code>config.local.php</code>:
                    </div>
                    <pre class="settings-code-block">define('SEISMO_REMOTE_REFRESH_KEY', '<?= e($satellitesSuggestedRefreshKey) ?>');</pre>
                <?php else: ?>
                    <form method="post" action="<?= e($bp) ?>/index.php?action=satellite_rotate_refresh_key" class="admin-inline-form" style="margin-top: 0.5rem;">
                        <?= $csrfField ?>
                        <button type="submit" class="btn btn-secondary btn-sm">Generate suggested key</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($satellitesRegistry === []): ?>
                <p class="admin-intro">No satellites registered yet. Use the form below to add your first one.</p>
            <?php else: ?>
                <div class="settings-satellite-table-wrap">
                    <table class="settings-satellite-table">
                        <thead>
                            <tr>
                                <th>Slug</th>
                                <th>Display name</th>
                                <th>Magnitu profile</th>
                                <th>Accent</th>
                                <th>Created</th>
                                <th class="settings-satellite-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($satellitesRegistry as $sat): ?>
                                <?php
                                $slug = (string)($sat['slug'] ?? '');
                                $isHighlight = $slug !== '' && $slug === $satellitesHighlightSlug;
                                ?>
                                <tr class="<?= $isHighlight ? 'settings-satellite-row-highlight' : '' ?>">
                                    <td><code><?= e($slug) ?></code></td>
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
                                    <td><code><?= e((string)($sat['magnitu_profile'] ?? '')) ?></code></td>
                                    <td>
                                        <?php if (!empty($sat['brand_accent'])): ?>
                                            <span class="settings-accent-swatch" style="background: <?= e((string)$sat['brand_accent']) ?>;"></span>
                                            <code class="settings-code-tiny"><?= e((string)$sat['brand_accent']) ?></code>
                                        <?php else: ?>
                                            <span class="settings-satellite-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="settings-satellite-meta">
                                        <?= e(substr((string)($sat['created_at'] ?? ''), 0, 10)) ?>
                                        <?php if (!empty($sat['rotated_at'])): ?>
                                            <br><span class="settings-satellite-rotated">rotated <?= e(substr((string)$sat['rotated_at'], 0, 10)) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="settings-satellite-actions">
                                        <a href="<?= e($bp) ?>/index.php?action=satellite_download_json&amp;slug=<?= urlencode($slug) ?>" class="btn btn-primary btn-sm">Download JSON</a>
                                        <form method="post" action="<?= e($bp) ?>/index.php?action=satellite_rotate_key" class="admin-inline-form"
                                              onsubmit="return confirm('Rotate API key for <?= e($slug) ?>? The old key stops working immediately and you must redeploy the satellite.');">
                                            <?= $csrfField ?>
                                            <input type="hidden" name="slug" value="<?= e($slug) ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm">Rotate key</button>
                                        </form>
                                        <form method="post" action="<?= e($bp) ?>/index.php?action=satellite_remove" class="admin-inline-form"
                                              onsubmit="return confirm('Remove <?= e($slug) ?> from registry? The satellite itself is untouched, but you lose the ability to generate its JSON here.');">
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
                        <input type="text" id="sat_slug" name="slug" required pattern="[a-z0-9-]+" maxlength="40" placeholder="digital" class="search-input">
                    </div>
                    <div class="admin-form-field">
                        <label for="sat_name">Display name</label>
                        <input type="text" id="sat_name" name="display_name" maxlength="80" placeholder="Digital" class="search-input" aria-describedby="sat_name_hint">
                        <p id="sat_name_hint" class="admin-intro" style="margin-top:0.35rem; font-size:0.85rem;">Enter only the satellite label after &quot;Seismo&quot; (stored as Seismo … in JSON).</p>
                    </div>
                    <div class="admin-form-field">
                        <label for="sat_profile">Magnitu profile</label>
                        <input type="text" id="sat_profile" name="magnitu_profile" maxlength="40" pattern="[a-z0-9-]+" placeholder="(same as slug)" class="search-input">
                    </div>
                    <div class="admin-form-field">
                        <label for="sat_accent">Brand accent (optional)</label>
                        <input type="text" id="sat_accent" name="brand_accent" maxlength="9" placeholder="#4a90e2" pattern="#[0-9a-fA-F]{3,8}" class="search-input">
                    </div>
                    <div class="admin-form-actions">
                        <button type="submit" class="btn btn-success">Add satellite</button>
                    </div>
                </form>
            </details>

            <div class="settings-satellite-context">
                <strong>Detected mothership values</strong> (embedded in each downloaded JSON):
                <ul>
                    <li>URL: <code><?= e($satellitesMothershipUrl) ?></code></li>
                    <li>DB: <code><?= e($satellitesMothershipDb) ?></code></li>
                    <li>Remote refresh key:
                        <?php if ($satellitesRemoteRefreshKeyConfigured): ?>
                            <code class="settings-ok">configured</code>
                        <?php else: ?>
                            <span class="settings-bad">NOT SET — satellites will not be able to trigger refresh</span>
                        <?php endif; ?>
                    </li>
                </ul>
            </div>
        </div>
