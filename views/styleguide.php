<?php
/**
 * @var string $csrfField
 * @var string $basePath
 */

declare(strict_types=1);

$accent = seismoBrandAccent();
$headerTitle = 'Styleguide';
$headerSubtitle = 'Typography, buttons, filters, tag inputs, cards';
$activeNav = 'styleguide';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Styleguide — <?= e(seismoBrandTitle()) ?></title>
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css">
    <?php if ($accent): ?>
    <style>:root { --seismo-accent: <?= e($accent) ?>; }</style>
    <?php endif; ?>
</head>
<body>
    <div class="container">
        <?php require __DIR__ . '/partials/site_header.php'; ?>

        <h2 class="section-title">Typography</h2>
        <p class="admin-intro">Body uses the system stack at 14px. <span class="type-sample-big">Big (18px)</span> for titles. <span class="type-sample-small">Small (12px)</span> for meta.</p>

        <h2 class="section-title module-section-spaced">Buttons</h2>
        <p class="admin-form-actions">
            <a href="#" class="btn btn-primary" onclick="return false;">Primary</a>
            <a href="#" class="btn btn-secondary" onclick="return false;">Secondary</a>
            <a href="#" class="btn btn-success" onclick="return false;">Success</a>
            <a href="#" class="btn btn-warning" onclick="return false;">Warning</a>
            <a href="#" class="btn btn-danger" onclick="return false;">Danger</a>
        </p>

        <h2 class="section-title module-section-spaced">Messages</h2>
        <div class="message message-success">Success — 12px, 2px border</div>
        <div class="message message-error">Error</div>
        <div class="message message-info">Info</div>

        <h2 class="section-title module-section-spaced">Dashboard filter pills</h2>
        <p class="admin-intro">
            On <code>?action=index</code>, <strong>Selection: All | None</strong> (next to View) is a shortcut: <strong>All</strong> clears exclusions (<code>efc</code> / <code>elx</code> / <code>eet</code>) and shows Leg+Jus again; <strong>None</strong> excludes every pill token plus Leg and Jus so the timeline is empty until you turn sources back on.
            With Selection <strong>All</strong>, pills default to on; clicking turns one <strong>off</strong> via exclusions <code>efc</code>, <code>elx</code>, <code>eet</code>.
            After <strong>None</strong>, the first pill you click uses <strong>inclusion</strong> (<code>fc</code> / <code>lx</code> / <code>etag</code>): only that pill is on until you add more or reset.
            <strong>Leg</strong> toggles <code>ecal=1</code> to hide calendar rows. Swiss case-law sources (BGer / BGE / BVGE) are separate Lex-row pills (<code>filters[lex][]</code>); legacy <code>ejus=1</code> still hides all three. Colours: feeds blue, scraper violet, Lex yellow, mail peach, Leg green.
        </p>
        <div class="tag-pills-section filter-toolbar">
            <div class="filter-toolbar__head">
                <span class="filter-toolbar__label">Filters</span>
                <a href="#" class="filter-toolbar__clear-all" onclick="return false;">Reset all</a>
            </div>
            <div class="filter-toolbar__row">
                <span class="filter-toolbar__hint">Feed</span>
                <span class="filter-pill filter-pill--feed filter-pill--active">Bund</span>
                <span class="filter-pill filter-pill--feed">SRF</span>
                <span class="filter-pill filter-pill--scraper filter-pill--active">scraper</span>
            </div>
            <div class="filter-toolbar__row">
                <span class="filter-toolbar__hint">Lex</span>
                <span class="filter-pill filter-pill--lex">ch</span>
                <span class="filter-pill filter-pill--lex filter-pill--active">eu</span>
            </div>
            <div class="filter-toolbar__row">
                <span class="filter-toolbar__hint">Email tag</span>
                <span class="filter-pill filter-pill--mail filter-pill--active">Bund</span>
                <span class="filter-pill filter-pill--mail">Blick Wirtschaft</span>
            </div>
            <div class="filter-toolbar__row">
                <span class="filter-toolbar__hint">Leg / Jus</span>
                <span class="filter-pill filter-pill--leg filter-pill--active">Leg</span>
                <span class="filter-pill filter-pill--lex filter-pill--active">Jus</span>
            </div>
        </div>
        <p class="admin-intro">Classes: <code>filter-pill</code> + <code>filter-pill--feed|scraper|lex|mail|leg</code>; active state <code>filter-pill--active</code>. Live dashboard filters: <code>views/dashboard_filters.php</code> (<code>filter-pill-label</code> + hidden checkbox + <code>filter-pill-text</code>); rules: <code>assets/css/style.css</code>.</p>

        <h2 class="section-title module-section-spaced">Tag inputs (Settings)</h2>
        <p class="admin-intro">
            Editable tag fields on module settings (feed category, global tag rename, etc.): <strong>Enter</strong> commits (async save + feedback), <strong>Escape</strong> restores the last committed value and clears transient state classes.
            While saving: gray border and light gray background (<code>.feed-tag-saving</code>). On success: green border and pale green background (<code>.feed-tag-saved</code>), then return to default after a short beat.
        </p>
        <div class="admin-form-card" style="max-width:40rem;">
            <h3 class="section-title" style="margin-top:0;">Examples</h3>
            <div class="admin-form-field">
                <p class="admin-intro" style="margin:0 0 6px;"><strong>Per-feed tag</strong></p>
                <label for="styleguide-tag-static" class="admin-form-field" style="display:block;">Tag</label>
                <div class="feed-tag-input-wrapper" style="max-width:24rem;">
                    <input id="styleguide-tag-static" type="text" class="feed-tag-input" value="example-tag" readonly aria-label="Static sample">
                </div>
            </div>
            <div class="admin-form-field">
                <p class="admin-intro" style="margin:0 0 6px;"><strong>All-tags rename</strong></p>
                <div class="feed-tag-input-wrapper" style="max-width:24rem;">
                    <input type="text" class="feed-tag-input" value="tag-name" readonly aria-label="Static sample">
                </div>
            </div>
            <div class="admin-form-field">
                <p class="admin-intro" style="margin:0 0 6px;"><strong>Save states</strong></p>
                <div class="admin-form-field" style="margin-bottom:10px;">
                    <span class="type-sample-small" style="display:block;margin-bottom:4px;">Default</span>
                    <div class="feed-tag-input-wrapper" style="max-width:24rem;">
                        <input type="text" class="feed-tag-input" value="normal" readonly aria-label="Default state">
                    </div>
                </div>
                <div class="admin-form-field" style="margin-bottom:10px;">
                    <span class="type-sample-small" style="display:block;margin-bottom:4px;">Saving</span>
                    <div class="feed-tag-input-wrapper" style="max-width:24rem;">
                        <input type="text" class="feed-tag-input feed-tag-saving" value="saving…" readonly aria-label="Saving state">
                    </div>
                </div>
                <div class="admin-form-field">
                    <span class="type-sample-small" style="display:block;margin-bottom:4px;">Saved</span>
                    <div class="feed-tag-input-wrapper" style="max-width:24rem;">
                        <input type="text" class="feed-tag-input feed-tag-saved" value="saved" readonly aria-label="Saved state">
                    </div>
                </div>
            </div>
            <div class="admin-form-field">
                <p class="admin-intro" style="margin:0 0 6px;"><strong>Interactive (demo)</strong> — edit text, Enter to simulate save, Escape to revert.</p>
                <div class="feed-tag-input-wrapper" style="max-width:24rem;">
                    <input type="text" id="styleguide-tag-demo" class="feed-tag-input" value="demo-tag" autocomplete="off" data-original="demo-tag" aria-label="Demo tag input">
                </div>
            </div>
        </div>
        <p class="admin-intro">Markup: <code>feed-tag-input-wrapper</code> + <code>feed-tag-input</code>; optional <code>feed-tag-indicator</code> for right-aligned hint text. CSS: <code>assets/css/style.css</code> (section “Tag Inputs (Settings)”). Wire the same classes when adding inline tag editors to <code>views/feeds.php</code> or Settings.</p>

        <h2 class="section-title module-section-spaced">Entry card sample</h2>
        <div class="entry-card">
            <div class="entry-header">
                <span class="entry-tag entry-tag--meta">feed_item</span>
                <span class="entry-tag">investigation_lead</span>
            </div>
            <div class="entry-content">
                <p>Card body — expand/collapse pattern matches the dashboard.</p>
            </div>
        </div>

        <p class="admin-intro module-section-spaced">Shared with Magnitu tooling; keep components aligned when changing <code>assets/css/style.css</code>.</p>
    </div>
    <script>
    (function () {
        var input = document.getElementById('styleguide-tag-demo');
        if (!input) return;
        function clearState() {
            input.classList.remove('feed-tag-saving', 'feed-tag-saved');
        }
        function revert() {
            var o = input.getAttribute('data-original') || '';
            input.value = o;
            clearState();
        }
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                revert();
                return;
            }
            if (e.key !== 'Enter') return;
            e.preventDefault();
            if (input.classList.contains('feed-tag-saving')) return;
            clearState();
            input.classList.add('feed-tag-saving');
            window.setTimeout(function () {
                input.classList.remove('feed-tag-saving');
                input.classList.add('feed-tag-saved');
                var v = input.value.trim();
                input.setAttribute('data-original', v);
                window.setTimeout(function () {
                    input.classList.remove('feed-tag-saved');
                }, 1400);
            }, 500);
        });
    })();
    </script>
</body>
</html>
