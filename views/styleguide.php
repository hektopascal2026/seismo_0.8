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
        <p class="admin-intro">Body uses the system stack at 0.875rem (14px at default root). <span class="type-sample-big">Big (1.125rem)</span> for titles. <span class="type-sample-small">Small (0.75rem)</span> for meta.</p>

        <h2 class="section-title module-section-spaced">Buttons</h2>
        <p class="admin-form-actions">
            <a href="#" class="btn btn-primary" onclick="return false;">Primary</a>
            <a href="#" class="btn btn-secondary" onclick="return false;">Secondary</a>
            <a href="#" class="btn btn-success" onclick="return false;">Success</a>
            <a href="#" class="btn btn-warning" onclick="return false;">Warning</a>
            <a href="#" class="btn btn-danger" onclick="return false;">Danger</a>
        </p>

        <h2 class="section-title module-section-spaced">Messages</h2>
        <div class="message message-success">Success — 0.75rem, 0.125rem border</div>
        <div class="message message-error">Error</div>
        <div class="message message-info">Info</div>

        <h2 class="section-title module-section-spaced">Source pill colours</h2>
        <p class="admin-intro">
            Timeline cards and the filter page (<code>?action=filter</code>) share the same square pills: <strong>2px black border</strong>, <strong>no border-radius</strong>, <strong>0.75rem</strong> text.
            Filter pills keep the compact padding (<code>0.25rem 0.625rem</code>); card pills use the same size via <code>.entry-tag</code>.
            Colours are defined once in <code>:root</code> as <code>--seismo-pill-*</code> in <code>assets/css/style.css</code>.
        </p>
        <table class="data-table" style="max-width:36rem;margin-bottom:1rem;">
            <thead>
                <tr><th>Source</th><th>CSS (card)</th><th>CSS (filter)</th><th>Background</th></tr>
            </thead>
            <tbody>
                <tr><td>RSS / default feed</td><td><code>entry-tag--feed-rss</code></td><td><code>filter-pill-text--feed</code></td><td><code>#add8e6</code></td></tr>
                <tr><td>Substack</td><td><code>entry-tag--feed-substack</code></td><td><code>filter-pill-text--feed-substack</code></td><td><code>#c5b4d1</code></td></tr>
                <tr><td>Scraper</td><td><code>entry-tag--scraper</code></td><td><code>filter-pill-text--scraper</code></td><td><code>#add8e6</code> (blue)</td></tr>
                <tr><td>Media</td><td><code>entry-tag--feed-media</code></td><td><code>filter-pill-text--feed-media</code></td><td><code>#fff7f7</code></td></tr>
                <tr><td>Lex</td><td><code>entry-tag--lex-source</code></td><td><code>filter-pill-text--lex</code></td><td><code>#f5f562</code></td></tr>
                <tr><td>Leg</td><td><code>entry-tag--leg-type</code></td><td><code>filter-pill-text--leg</code></td><td><code>#d4edda</code></td></tr>
                <tr><td>Email</td><td><code>entry-tag--email-sender</code></td><td><code>filter-pill-text--mail</code></td><td><code>#ffdbbb</code></td></tr>
            </tbody>
        </table>

        <h3 class="section-title" style="margin-top:0;">Timeline card pills</h3>
        <p class="admin-intro">Always show full source colour on the card (no on/off state).</p>
        <div class="entry-card" style="margin-bottom:0.75rem;">
            <div class="entry-header">
                <span class="entry-tag entry-tag--feed-rss">RSS feed</span>
                <span class="entry-tag entry-tag--feed-substack">Substack</span>
                <span class="entry-tag entry-tag--feed-media">Media</span>
                <span class="entry-tag entry-tag--scraper">🌐 Scraper</span>
                <span class="entry-tag entry-tag--lex-source">🇪🇺 Lex</span>
                <span class="entry-tag entry-tag--leg-type">Leg</span>
                <span class="entry-tag entry-tag--email-sender">Email</span>
            </div>
        </div>

        <h3 class="section-title" style="margin-top:1rem;">Filter page pills</h3>
        <p class="admin-intro">Same colours and square shape; unchecked pills use 50% opacity, checked pills are fully opaque with a light offset shadow.</p>
        <div class="tag-pills-section filter-toolbar">
            <div class="filter-toolbar__row">
                <span class="filter-toolbar__hint">Feed</span>
                <label class="filter-pill-label"><input type="checkbox" class="filter-pill-input" checked disabled><span class="filter-pill-text filter-pill-text--feed">RSS on</span></label>
                <label class="filter-pill-label"><input type="checkbox" class="filter-pill-input" disabled><span class="filter-pill-text filter-pill-text--feed">RSS off</span></label>
                <label class="filter-pill-label"><input type="checkbox" class="filter-pill-input" checked disabled><span class="filter-pill-text filter-pill-text--feed-substack">Substack</span></label>
                <label class="filter-pill-label"><input type="checkbox" class="filter-pill-input" checked disabled><span class="filter-pill-text filter-pill-text--feed-media">Media</span></label>
                <label class="filter-pill-label"><input type="checkbox" class="filter-pill-input" checked disabled><span class="filter-pill-text filter-pill-text--scraper">Scraper</span></label>
            </div>
            <div class="filter-toolbar__row">
                <span class="filter-toolbar__hint">Lex / Leg / Mail</span>
                <label class="filter-pill-label"><input type="checkbox" class="filter-pill-input" checked disabled><span class="filter-pill-text filter-pill-text--lex">Lex</span></label>
                <label class="filter-pill-label"><input type="checkbox" class="filter-pill-input" checked disabled><span class="filter-pill-text filter-pill-text--leg">Leg</span></label>
                <label class="filter-pill-label"><input type="checkbox" class="filter-pill-input" checked disabled><span class="filter-pill-text filter-pill-text--mail">Mail</span></label>
            </div>
        </div>
        <p class="admin-intro">Live markup: <code>views/dashboard_filters.php</code> (<code>filter-pill-label</code> + <code>filter-pill-input</code> + <code>filter-pill-text</code>). Card partials: <code>entry_card_rss_substack.php</code>, <code>entry_card_scraper.php</code>.</p>

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
                <span class="entry-tag entry-tag--feed-rss">Sample outlet</span>
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
