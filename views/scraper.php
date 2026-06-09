<?php
/**
 * Scraper feed items — Items | Sources (Slice 8).
 *
 * @var array<int, array<string, mixed>> $allItems
 * @var list<array<string, mixed>> $configsList
 * @var ?array<string, mixed> $editRow
 * @var ?string $pageError
 * @var string $csrfField
 * @var float $alertThreshold
 * @var string $view 'items'|'sources'
 * @var bool $satellite
 * @var ?string $dashboardError
 */

declare(strict_types=1);

$basePath = getBasePath();
$accent     = seismoBrandAccent();

$headerTitle    = 'Scraper';
$headerSubtitle = 'Scraped pages';
$activeNav      = 'scraper';

$itemsQs   = 'action=scraper';
$sourcesQs = 'action=scraper&view=sources';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($headerTitle) ?> — <?= e(seismoBrandTitle()) ?></title>
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css">
    <?php if ($accent): ?>
    <style>:root { --seismo-accent: <?= e($accent) ?>; }</style>
    <?php endif; ?>
    <style>
        .gemini-feedback-box {
            margin-top: 1.5rem;
            margin-bottom: 1.5rem;
            padding: 1.2rem;
            border-radius: var(--radius-md, 8px);
            background: rgba(168, 85, 247, 0.05);
            border: 1px solid rgba(168, 85, 247, 0.2);
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.02);
            transition: all 0.3s ease;
        }
        .gemini-feedback-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 700;
            color: #9333ea;
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }
        .gemini-feedback-text {
            font-size: 0.9rem;
            color: var(--text-color, #374151);
            line-height: 1.4;
            margin: 0;
        }
    </style>
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
            <a href="<?= e($basePath) ?>/index.php?<?= e($sourcesQs) ?>" class="btn <?= $view === 'sources' ? 'btn-primary' : 'btn-secondary' ?>">Sources</a>
        </div>

        <?php if ($satellite && $view === 'sources'): ?>
            <p class="message message-info">Satellite mode: scraper targets are read-only here. Manage sources on the mothership.</p>
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
                <button class="btn btn-secondary entry-expand-all-btn">expand all &#9662;</button>
            </div>
            <?php if ($dashboardError !== null): ?>
            <?php elseif ($allItems !== []): ?>
                <?php include __DIR__ . '/partials/dashboard_entry_loop.php'; ?>
            <?php else: ?>
                <div class="empty-state">
                    <p>No scraper items yet. Add a source under <a href="<?= e($basePath) ?>/index.php?<?= e($sourcesQs) ?>">Sources</a> or run refresh from Diagnostics.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="latest-entries-section">
            <h2 class="section-title">Scraper sources</h2>
            <p class="admin-intro">Saving a source here wires the matching <code>feeds</code> row automatically so the core scraper picks it up on the next refresh. Use category <code>media</code> for news monitoring shown on <a href="<?= e($basePath) ?>/index.php?action=media&amp;view=sources">Media</a>; general RSS belongs on <a href="<?= e($basePath) ?>/index.php?action=feeds&amp;view=sources">Feeds</a>.</p>

            <?php if (!$satellite): ?>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=scraper_save" class="admin-form-card" id="scraper-source-form">
                <?= $csrfField ?>
                <input type="hidden" name="id" value="<?= $editRow ? (int)$editRow['id'] : '' ?>">
                <h3><?= $editRow ? 'Edit source' : 'Add source' ?></h3>
                <div class="admin-form-field">
                    <label>Name <input type="text" name="name" required class="search-input" style="width:100%;" value="<?= e((string)($editRow['name'] ?? '')) ?>"></label>
                </div>
                <div class="admin-form-field">
                    <label>Page URL <input type="url" name="url" required class="search-input" style="width:100%;" value="<?= e((string)($editRow['url'] ?? '')) ?>" placeholder="https://…"></label>
                </div>
                <p class="admin-hint">Paste the listing URL from your browser. A trailing <code>/</code> at the end is optional — Seismo stores one canonical form so you do not get duplicate feeds.</p>
                <div class="admin-form-field">
                    <label>Link pattern (substring) <input type="text" name="link_pattern" class="search-input" style="width:100%;" value="<?= e((string)($editRow['link_pattern'] ?? '')) ?>" placeholder="must appear in the article URL" title="0.4-style: plain substring in resolved http(s) URL, not a regex."></label>
                </div>
                <p class="admin-hint">Link mode: the resolved same-host URL must <strong>contain</strong> this text (0.4 behaviour). Single-page mode: leave empty.</p>
                <div class="admin-form-field">
                    <label>Date selector <input type="text" name="date_selector" class="search-input" style="width:100%;" value="<?= e((string)($editRow['date_selector'] ?? '')) ?>" placeholder="e.g. .date or //time[@datetime]"></label>
                </div>
                <p class="admin-hint">Date: standard CSS (e.g. <code>article time[datetime]</code>, <code>.date</code>, <code>meta[property="article:published_time"]</code>) or raw XPath (<code>//…</code>). Preview uses the same extractor as production. For SPRIND articles use <code>p.w-max.lining-nums</code> (not bare <code>p.lining-nums</code> on a listing URL). Link mode requires a <strong>non-empty</strong> link pattern (e.g. <code>/worte/magazin/</code>) — empty pattern scrapes only the page URL and dates will be wrong.</p>
                <div class="admin-form-field">
                    <label>Exclude selectors <textarea name="exclude_selectors" class="search-input" style="width:100%; min-height:5rem; font-family: inherit;" rows="4" placeholder="One per line: .breadcrumb, #page-footer, nav.breadcrumbs"><?= e((string)($editRow['exclude_selectors'] ?? '')) ?></textarea></label>
                </div>
                <p class="admin-hint">Elements matching these selectors are removed from the page <strong>before</strong> Readability / text extraction (same CSS / XPath rules as the date field). Use for breadcrumbs, footers, and chrome that would otherwise be merged into the body. Lines starting with <code>#</code> are comments.</p>
                <div class="admin-form-field">
                    <label>Category <input type="text" name="category" class="search-input" style="width:100%; max-width:24rem;" value="<?= e((string)($editRow['category'] ?? 'scraper')) ?>"></label>
                </div>
                <div class="admin-form-field">
                    <input type="hidden" name="disabled" value="0">
                    <label><input type="checkbox" name="disabled" value="1" <?= !empty($editRow['disabled']) ? 'checked' : '' ?>> Disabled</label>
                </div>
                <div class="admin-form-actions">
                    <button type="submit" class="btn btn-success"><?= $editRow ? 'Save' : 'Add source' ?></button>
                    <button type="button" class="btn btn-secondary" id="scraper-preview-btn">Preview (dry run)</button>
                    <button type="button" class="btn btn-secondary" id="scraper-gemini-btn">Analyze with Gemini</button>
                    <?php if ($editRow): ?>
                        <a href="<?= e($basePath) ?>/index.php?<?= e($sourcesQs) ?>" class="btn btn-secondary">Cancel edit</a>
                    <?php endif; ?>
                </div>
            </form>
            <div id="gemini-feedback-panel" class="gemini-feedback-box" hidden>
                <div class="gemini-feedback-title">
                    Gemini AI Insights
                </div>
                <p id="gemini-feedback-text" class="gemini-feedback-text"></p>
                <p id="gemini-feedback-url" style="font-size: 0.8rem; opacity: 0.8; margin-top: 0.5rem;"></p>
            </div>
            <div id="scraper-preview-panel" class="scraper-preview-panel" hidden>
                <h3 class="section-title">Preview <span class="scraper-preview-badge">not saved</span></h3>
                <p id="scraper-preview-error" class="message message-error" hidden></p>
                <p id="scraper-preview-warnings" class="message message-info" hidden></p>
                <div id="scraper-preview-cards" class="latest-entries-section scraper-preview-cards"></div>
            </div>
            <?php endif; ?>

            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Active</th>
                        <th>URL</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($configsList as $row): ?>
                    <?php $sourceActive = empty($row['disabled']); ?>
                    <tr class="<?= $sourceActive ? '' : 'data-table-row-muted' ?>">
                        <td><?= (int)$row['id'] ?></td>
                        <td><?= e((string)$row['name']) ?></td>
                        <td><?= $sourceActive ? '<span class="pill pill-on">yes</span>' : '<span class="pill pill-off">no</span>' ?></td>
                        <td class="data-table-url"><a href="<?= e((string)$row['url']) ?>" target="_blank" rel="noopener"><?= e((string)$row['url']) ?></a></td>
                        <td>
                            <?php if (!$satellite): ?>
                            <div class="admin-table-actions">
                                <a href="<?= e($basePath) ?>/index.php?action=scraper&amp;view=sources&amp;edit=<?= (int)$row['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                                <?php if ($sourceActive): ?>
                                <form method="post" action="<?= e($basePath) ?>/index.php?action=scraper_toggle_disabled" class="admin-inline-form">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <button type="submit" class="btn btn-warning btn-sm" title="Stop scraping this URL until re-enabled">Disable</button>
                                </form>
                                <?php else: ?>
                                <form method="post" action="<?= e($basePath) ?>/index.php?action=scraper_toggle_disabled" class="admin-inline-form">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <button type="submit" class="btn btn-success btn-sm">Enable</button>
                                </form>
                                <?php endif; ?>
                                <form method="post" action="<?= e($basePath) ?>/index.php?action=scraper_delete" class="admin-inline-form" onsubmit="return confirm('Delete this scraper config?');">
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
                <?php if ($configsList === []): ?>
                    <tr class="data-table-empty"><td colspan="5">No scraper configs.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <script>
    (function() {
        var form = document.getElementById('scraper-source-form');
        var btnPreview = document.getElementById('scraper-preview-btn');
        var panel = document.getElementById('scraper-preview-panel');
        var outCards = document.getElementById('scraper-preview-cards');
        var outErr = document.getElementById('scraper-preview-error');
        var outWarn = document.getElementById('scraper-preview-warnings');
        var previewUrl = <?= json_encode($basePath . '/index.php?action=scraper_preview', JSON_UNESCAPED_SLASHES) ?>;

        var btnGemini = document.getElementById('scraper-gemini-btn');
        var geminiPanel = document.getElementById('gemini-feedback-panel');
        var geminiText = document.getElementById('gemini-feedback-text');
        var geminiLink = document.getElementById('gemini-feedback-url');
        var geminiAnalyzeUrl = <?= json_encode($basePath . '/index.php?action=scraper_analyze_gemini', JSON_UNESCAPED_SLASHES) ?>;

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

        if (form && btnGemini && geminiPanel) {
            btnGemini.addEventListener('click', function() {
                var urlInput = form.querySelector('input[name="url"]');
                if (!urlInput || !urlInput.value) {
                    alert('Please enter a Page URL first.');
                    return;
                }
                btnGemini.disabled = true;
                var originalText = btnGemini.textContent;
                btnGemini.textContent = 'Analyzing…';
                geminiPanel.hidden = true;

                var fd = new FormData(form);
                fetch(geminiAnalyzeUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                    .then(function(r) { return r.text().then(function(t) { return { status: r.status, body: t }; }); })
                    .then(function(res) {
                        btnGemini.disabled = false;
                        btnGemini.textContent = originalText;
                        var data;
                        try { data = JSON.parse(res.body); } catch (e) {
                            alert('Invalid response from analyzer.');
                            return;
                        }
                        if (!data.ok) {
                            alert(data.error || 'AI analysis failed.');
                        } else {
                            // Populate form inputs
                            var dateInput = form.querySelector('input[name="date_selector"]');
                            var excludeTextarea = form.querySelector('textarea[name="exclude_selectors"]');
                            if (dateInput) dateInput.value = data.date_selector || '';
                            if (excludeTextarea) excludeTextarea.value = data.exclude_selectors || '';
                            
                            // Display explanation
                            if (geminiText) geminiText.textContent = data.explanation || 'Analyzed successfully!';
                            if (geminiLink && data.resolved_url) {
                                geminiLink.innerHTML = 'Analyzed target page: <a href="' + encodeURI(data.resolved_url) + '" target="_blank" style="color: #9333ea; text-decoration: underline;">' + data.resolved_url + '</a>';
                            }
                            geminiPanel.hidden = false;

                            // Automatically trigger preview
                            if (btnPreview) {
                                btnPreview.click();
                            }
                        }
                    })
                    .catch(function(err) {
                        btnGemini.disabled = false;
                        btnGemini.textContent = originalText;
                        alert('Network error — could not run AI analysis.');
                    });
            });
        }
    })();
    </script>
    <?php require __DIR__ . '/partials/timeline_entry_expand_script.php'; ?>
</body>
</html>
