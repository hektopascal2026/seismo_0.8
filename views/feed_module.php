<?php
/**
 * Shared Feeds / Media module view ({@see \Seismo\Feed\FeedModule}).
 *
 * @var \Seismo\Feed\FeedModule $feedModule
 * @var array<int, array<string, mixed>> $allItems
 * @var list<array<string, mixed>> $feedsList
 * @var ?array<string, mixed> $editRow
 * @var ?string $pageError
 * @var string $csrfField
 * @var string $view 'items'|'sources'
 * @var bool $satellite
 * @var ?string $dashboardError
 * @var list<string> $categorySuggestions
 */

declare(strict_types=1);

$basePath = getBasePath();
$accent   = seismoBrandAccent();

$headerTitle    = $feedModule->pageTitle;
$headerSubtitle = $feedModule->subtitle;
$activeNav      = $feedModule->navKey;

$itemsQs   = 'action=' . $feedModule->action;
$sourcesQs = 'action=' . $feedModule->action . '&view=sources';
$sourcesHref = $basePath . '/index.php?' . $sourcesQs;
$emptyItemsMessage = str_replace('{sources_href}', e($sourcesHref), $feedModule->emptyItemsHtml);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($headerTitle) ?> — <?= e(seismoBrandTitle()) ?></title>
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css?v=<?= e(SEISMO_VERSION) ?>">
    <?php if ($accent): ?>
    <style>:root { --seismo-accent: <?= e($accent) ?>; }</style>
    <?php endif; ?>
</head>
<body>
    <div class="container">
        <?php require __DIR__ . '/partials/site_header.php'; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="message message-success"><?= e((string)$_SESSION['success']) ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="message message-error"><?= e((string)$_SESSION['error']) ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <div class="view-toggle view-toggle-bar">
            <span class="view-toggle-label">View:</span>
            <a href="<?= e($basePath) ?>/index.php?<?= e($itemsQs) ?>" class="btn <?= $view === 'items' ? 'btn-primary' : 'btn-secondary' ?>">Items</a>
            <a href="<?= e($basePath) ?>/index.php?<?= e($sourcesQs) ?>" class="btn <?= $view === 'sources' ? 'btn-primary' : 'btn-secondary' ?>"><?= e($feedModule->sourcesTabLabel) ?></a>
        </div>

        <?php if ($satellite && $view === 'sources'): ?>
            <p class="message message-info">Satellite mode: source definitions are read-only here. Manage them on the mothership.</p>
        <?php endif; ?>

        <?php if ($pageError !== null): ?>
            <div class="message message-error"><?= e($pageError) ?></div>
        <?php endif; ?>

        <?php if ($view === 'items'): ?>
        <div class="latest-entries-section">
            <div class="section-title-row">
                <h2 class="section-title">
                    <?= count($allItems) ?> <?= count($allItems) === 1 ? 'entry' : 'entries' ?>
                </h2>
                <button class="btn btn-secondary entry-expand-all-btn">expand all &#9660;</button>
            </div>
            <?php if ($dashboardError !== null): ?>
            <?php elseif ($allItems !== []): ?>
                <?php include __DIR__ . '/partials/dashboard_entry_loop.php'; ?>
            <?php else: ?>
                <div class="empty-state">
                    <p><?= $emptyItemsMessage ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="latest-entries-section">
            <h2 class="section-title"><?= e($feedModule->isMedia() ? 'Media sources' : 'Feed sources') ?></h2>

            <?php if ($feedModule->sourcesIntroHtml !== ''): ?>
                <?= $feedModule->sourcesIntroHtml ?>
            <?php endif; ?>

            <?php if (!$satellite): ?>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=<?= e($feedModule->saveAction) ?>" class="admin-form-card" id="feed-source-form">
                <?= $csrfField ?>
                <input type="hidden" name="id" value="<?= $editRow ? (int)$editRow['id'] : '' ?>">
                <?php if ($feedModule->fixedCategory !== null): ?>
                <input type="hidden" name="category" value="<?= e($feedModule->fixedCategory) ?>">
                <?php endif; ?>
                <h3><?= $editRow ? 'Edit source' : 'Add source' ?></h3>
                <div class="admin-form-field">
                    <label>URL <?= $feedModule->isMedia() ? '(RSS / Atom)' : '/ API endpoint' ?>
                        <input type="text" name="url" required class="search-input" style="width:100%;" value="<?= e((string)($editRow['url'] ?? '')) ?>" placeholder="<?= $feedModule->isMedia() ? 'https://news.google.com/rss/…' : 'https://… (RSS) or SharePoint list URL for parl_press' ?>">
                    </label>
                </div>
                <div class="admin-form-field">
                    <label>Title <input type="text" name="title" required class="search-input" style="width:100%;" value="<?= e((string)($editRow['title'] ?? '')) ?>"></label>
                </div>
                <div class="admin-form-field">
                    <label>Source type
                        <select name="source_type" class="search-input" style="width:100%;">
                            <?php $st = (string)($editRow['source_type'] ?? 'rss'); ?>
                            <option value="rss" <?= $st === 'rss' ? 'selected' : '' ?>>rss</option>
                            <option value="substack" <?= $st === 'substack' ? 'selected' : '' ?>>substack</option>
                            <?php if ($feedModule->allowParlPress): ?>
                            <option value="parl_press" <?= $st === 'parl_press' ? 'selected' : '' ?>>parl_press (Bundeshaus Medien)</option>
                            <?php endif; ?>
                        </select>
                    </label>
                </div>
                <?php if ($feedModule->allowParlPress && ($editRow['source_type'] ?? '') === 'parl_press'): ?>
                <div class="admin-help">
                    For <strong>parl_press</strong>, set the SharePoint list <code>…/items</code> OData URL per stream and a clear <strong>Title</strong> (that title becomes the dashboard filter pill). Ingest uses list OData GET only (no search API). <strong>Medienmitteilungen</strong> (<code>guid_prefix</code> <code>parl_mm</code>): <code>https://www.parlament.ch/press-releases/_api/web/lists/getByTitle('Pages')/items</code>. <strong>SDA-Meldungen</strong> (<code>guid_prefix</code> <code>parl_sda</code>): <code>https://www.parlament.ch/de/services/news/_api/web/lists/getByTitle('Seiten')/items</code>. Example Medien JSON: <code>{"lookback_days":90,"limit":50,"language":"de"}</code>. Example SDA JSON: <code>{"lookback_days":365,"limit":80,"language":"de","guid_prefix":"parl_sda"}</code>.
                </div>
                <?php endif; ?>
                <div class="admin-form-field">
                    <label>Description<br><textarea name="description" rows="2" class="search-input" style="width:100%;"><?= e((string)($editRow['description'] ?? '')) ?></textarea></label>
                </div>
                <div class="admin-form-field">
                    <label>Site link <input type="text" name="link" class="search-input" style="width:100%;" value="<?= e((string)($editRow['link'] ?? '')) ?>"></label>
                </div>
                <?php if ($feedModule->showCategoryField): ?>
                <?php
                $categoryValue = (string)($editRow['category'] ?? '');
                $datalistId = 'feed-category-suggestions';
                require __DIR__ . '/partials/category_field.php';
                ?>
                <?php endif; ?>
                <div class="admin-form-field">
                    <input type="hidden" name="extract_full_text" value="0">
                    <?php
                    $extractDefault = !empty($editRow['extract_full_text']);
                    ?>
                    <label><input type="checkbox" name="extract_full_text" value="1" <?= $extractDefault ? 'checked' : '' ?>> Hydrate: Use a scraper to fetch full text from the RSS-Link</label>
                </div>
                <div class="admin-help" style="margin-top:-0.5rem;margin-bottom:0.75rem;">
                    When enabled, ingest fetches up to 10 publisher pages per refresh for items whose body is shorter than ~400 characters.
                    Extraction compares JSON-LD <code>articleBody</code>, Readability, and meta descriptions, then stores the longest usable result.
                    <strong>Preview</strong> uses the same pipeline for up to <?= (int)\Seismo\Controller\FeedModuleHandler::PREVIEW_MAX_ITEMS ?> thin items — nothing is saved until Refresh.
                    Site listings use <a href="<?= e($basePath) ?>/index.php?action=scraper">Scraper</a> with category <code>media</code>. See <code>docs/rss-hydration.md</code>.
                </div>
                <div class="admin-form-field">
                    <input type="hidden" name="disabled" value="0">
                    <label><input type="checkbox" name="disabled" value="1" <?= !empty($editRow['disabled']) ? 'checked' : '' ?>> Disabled</label>
                </div>
                <div class="admin-form-actions">
                    <button type="submit" class="btn btn-success"><?= $editRow ? 'Save' : 'Add' ?></button>
                    <button type="button" class="btn btn-secondary" id="feed-preview-btn">Preview (dry run)</button>
                    <?php if ($editRow): ?>
                        <a href="<?= e($basePath) ?>/index.php?<?= e($sourcesQs) ?>" class="btn btn-secondary">Cancel edit</a>
                    <?php endif; ?>
                </div>
            </form>
            <div id="feed-preview-panel" class="scraper-preview-panel" hidden>
                <h3 class="section-title">Preview <span class="scraper-preview-badge">not saved</span></h3>
                <p id="feed-preview-error" class="message message-error" hidden></p>
                <p id="feed-preview-warnings" class="message message-info" hidden></p>
                <div id="feed-preview-cards" class="latest-entries-section scraper-preview-cards"></div>
            </div>
            <?php endif; ?>
 
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Type</th>
                        <th>Hydrate</th>
                        <th>Active</th>
                        <th>URL</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($feedsList as $row): ?>
                    <?php $feedActive = empty($row['disabled']); ?>
                    <tr class="<?= $feedActive ? '' : 'data-table-row-muted' ?>">
                        <td><?= (int)$row['id'] ?></td>
                        <td><?= e((string)$row['title']) ?></td>
                        <td><?= e((string)($row['source_type'] ?? '')) ?></td>
                        <td><?= !empty($row['extract_full_text']) ? '<span class="pill pill-on">hydrate</span>' : '<span class="pill pill-off">rss only</span>' ?></td>
                        <td><?= $feedActive ? '<span class="pill pill-on">yes</span>' : '<span class="pill pill-off">no</span>' ?></td>
                        <td class="data-table-url"><a href="<?= e((string)$row['url']) ?>" target="_blank" rel="noopener"><?= e((string)$row['url']) ?></a></td>
                        <td>
                            <?php if (!$satellite): ?>
                            <div class="admin-table-actions">
                                <a href="<?= e($basePath) ?>/index.php?action=<?= e($feedModule->action) ?>&amp;view=sources&amp;edit=<?= (int)$row['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                                <?php if ($feedActive): ?>
                                <form method="post" action="<?= e($basePath) ?>/index.php?action=<?= e($feedModule->toggleAction) ?>" class="admin-inline-form">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <button type="submit" class="btn btn-warning btn-sm" title="Stop fetching until re-enabled">Disable</button>
                                </form>
                                <?php else: ?>
                                <form method="post" action="<?= e($basePath) ?>/index.php?action=<?= e($feedModule->toggleAction) ?>" class="admin-inline-form">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <button type="submit" class="btn btn-success btn-sm">Enable</button>
                                </form>
                                <?php endif; ?>
                                <form method="post" action="<?= e($basePath) ?>/index.php?action=<?= e($feedModule->deleteAction) ?>" class="admin-inline-form" onsubmit="return confirm('Delete this source and its items?');">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            </div>
                            <?php else: ?>
                            <span class="table-cell-placeholder">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($feedsList === []): ?>
                    <tr class="data-table-empty"><td colspan="7">No sources defined.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <script>
    (function() {
        var form = document.getElementById('feed-source-form');
        var btnPreview = document.getElementById('feed-preview-btn');
        var panel = document.getElementById('feed-preview-panel');
        var outCards = document.getElementById('feed-preview-cards');
        var outErr = document.getElementById('feed-preview-error');
        var outWarn = document.getElementById('feed-preview-warnings');
        var previewUrl = <?= json_encode($basePath . '/index.php?action=' . $feedModule->previewAction, JSON_UNESCAPED_SLASHES) ?>;

        if (form && btnPreview && panel) {
            btnPreview.addEventListener('click', function() {
                if (outErr) { outErr.hidden = true; outErr.textContent = ''; }
                if (outWarn) { outWarn.hidden = true; outWarn.textContent = ''; }
                outCards.innerHTML = '<p class="admin-intro">Loading…</p>';
                panel.hidden = false;
                var fd = new FormData(form);
                fetch(previewUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                    .then(function(r) { return r.text().then(function(t) { return { status: r.status, body: t }; }); })
                    .then(function(res) {
                        var data;
                        try { data = JSON.parse(res.body); } catch (e) {
                            if (outErr) {
                                outErr.textContent = 'Invalid response (HTTP ' + res.status + ').';
                                outErr.hidden = false;
                            }
                            outCards.innerHTML = '';
                            return;
                        }
                        if (!data.ok) {
                            if (outErr) {
                                outErr.textContent = data.error || 'Preview failed.';
                                outErr.hidden = false;
                            }
                            outCards.innerHTML = '';
                        } else {
                            if (outErr) { outErr.hidden = true; }
                            outCards.innerHTML = data.html || '';
                        }
                        if (data.warnings && data.warnings.length && outWarn) {
                            outWarn.textContent = data.warnings.join(' ');
                            outWarn.hidden = false;
                        } else if (outWarn) {
                            outWarn.hidden = true;
                        }
                    })
                    .catch(function() {
                        if (outErr) {
                            outErr.textContent = 'Network error — could not run preview.';
                            outErr.hidden = false;
                        }
                        outCards.innerHTML = '';
                    });
            });
        }
        function collapse(card, btn) {
            var preview = card.querySelector('.entry-preview');
            var full    = card.querySelector('.entry-full-content');
            if (!preview || !full) return;
            full.style.display = 'none';
            preview.style.display = '';
            if (btn) btn.textContent = 'expand \u25BC';
        }
        function expand(card, btn) {
            var preview = card.querySelector('.entry-preview');
            var full    = card.querySelector('.entry-full-content');
            if (!preview || !full) return;
            preview.style.display = 'none';
            full.style.display    = 'block';
            if (btn) btn.textContent = 'collapse \u25B2';
        }
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.entry-expand-btn');
            if (!btn) return;
            var card = btn.closest('.entry-card');
            var full = card.querySelector('.entry-full-content');
            if (!full) return;
            full.style.display === 'block' ? collapse(card, btn) : expand(card, btn);
        });
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.entry-expand-all-btn');
            if (!btn) return;
            var isExpanded = btn.dataset.expanded === 'true';
            document.querySelectorAll('.entry-card').forEach(function(card) {
                var cardBtn = card.querySelector('.entry-expand-btn');
                isExpanded ? collapse(card, cardBtn) : expand(card, cardBtn);
            });
            btn.dataset.expanded = !isExpanded;
            btn.textContent = !isExpanded ? 'collapse all \u25B2' : 'expand all \u25BC';
        });
    })();
    </script>
</body>
</html>
