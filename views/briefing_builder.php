<?php
/**
 * AI Briefing Builder — filter entries and generate a Gemini summary.
 *
 * @var string $csrfField
 * @var string $basePath
 * @var bool $geminiConfigured
 * @var string $defaultSystemPrompt
 * @var int $defaultLookbackDays
 * @var int $defaultLimit
 * @var int $maxLimit
 */

declare(strict_types=1);

$accent = seismoBrandAccent();

$headerTitle    = 'AI Briefing';
$headerSubtitle = 'Gemini summary from recent entries';
$activeNav      = 'briefing_builder';

$generateUrl = $basePath . '/index.php?action=briefing_builder_generate';

$moduleOptions = [
    ['key' => 'feeds', 'label' => 'Feeds'],
    ['key' => 'media', 'label' => 'Media'],
    ['key' => 'scraper', 'label' => 'Scraper'],
    ['key' => 'email', 'label' => 'Mail'],
    ['key' => 'lex', 'label' => 'Lex'],
    ['key' => 'leg', 'label' => 'Leg'],
];
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
</head>
<body>
    <div class="container">
        <?php require __DIR__ . '/partials/site_header.php'; ?>

        <?php if (!$geminiConfigured): ?>
            <div class="message message-warning">
                No Gemini API key is configured.
                <a href="<?= e($basePath) ?>/index.php?action=settings&amp;tab=general">Add one in Settings → General</a>
                before generating a briefing.
            </div>
        <?php endif; ?>

        <div class="latest-entries-section">
            <h2 class="section-title">Filters</h2>
            <p class="admin-intro">
                Entries are scored labels from Magnitu or the recipe scorer.
                Only <strong>investigation lead</strong> rows are included by default; optionally add <strong>important</strong>.
                Each enabled module loads up to the entry limit below (export maximum <?= (int)$maxLimit ?>).
            </p>

            <form id="briefing-builder-form" class="admin-form-card">
                <div class="filter-page-actions" style="margin-bottom:0.75rem;">
                    <button type="button" class="btn btn-primary" id="briefing-modules-all">All sources</button>
                    <button type="button" class="btn btn-secondary" id="briefing-modules-none">None</button>
                </div>

                <div class="tag-filter-section tag-filter-section--spaced-bottom">
                    <div class="tag-filter-list">
                        <?php foreach ($moduleOptions as $mod): ?>
                        <label class="tag-filter-pill tag-filter-pill-active">
                            <input type="checkbox" name="modules[]" value="<?= e($mod['key']) ?>" checked
                                   class="briefing-module-cb">
                            <span><?= e($mod['label']) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="admin-form-field">
                    <label>Labels</label>
                    <p class="admin-intro" style="margin:0.25rem 0 0.5rem;">Investigation lead (always included)</p>
                    <label>
                        <input type="checkbox" name="include_important" value="1">
                        Also include important
                    </label>
                </div>

                <div class="admin-form-field">
                    <label for="briefing_lookback">Lookback window</label>
                    <select id="briefing_lookback" name="lookback_days" class="search-input" style="width:auto;">
                        <option value="1"<?= $defaultLookbackDays === 1 ? ' selected' : '' ?>>1 day</option>
                        <option value="3"<?= $defaultLookbackDays === 3 ? ' selected' : '' ?>>3 days</option>
                        <option value="7"<?= $defaultLookbackDays === 7 ? ' selected' : '' ?>>7 days</option>
                    </select>
                </div>

                <div class="admin-form-field">
                    <label for="briefing_limit">Entry limit (per module)</label>
                    <input type="number" id="briefing_limit" name="limit" class="search-input" style="width:7rem;"
                           min="1" max="<?= (int)$maxLimit ?>" value="<?= (int)$defaultLimit ?>">
                </div>

                <div class="admin-form-field">
                    <label for="briefing_system_prompt">System prompt</label>
                    <textarea id="briefing_system_prompt" name="system_prompt" rows="8" class="search-input"
                              style="width:100%; max-width:40rem;"><?= e($defaultSystemPrompt) ?></textarea>
                </div>

                <div class="admin-form-actions">
                    <button type="submit" class="btn btn-success" id="briefing-generate-btn"
                            <?= $geminiConfigured ? '' : ' disabled' ?>>Generate briefing</button>
                </div>
            </form>
        </div>

        <div class="latest-entries-section module-section-spaced">
            <h2 class="section-title">Summary</h2>
            <div id="briefing-output-error" class="message message-error" hidden></div>
            <div id="briefing-output" class="admin-form-card" style="white-space:pre-wrap; min-height:4rem;">
                <p class="admin-intro" id="briefing-output-placeholder">Generated text will appear here.</p>
            </div>
        </div>

        <div class="label-hidden-csrf" aria-hidden="true"><?= $csrfField ?></div>
    </div>

    <script>
    (function() {
        var form = document.getElementById('briefing-builder-form');
        var btn = document.getElementById('briefing-generate-btn');
        var out = document.getElementById('briefing-output');
        var placeholder = document.getElementById('briefing-output-placeholder');
        var errEl = document.getElementById('briefing-output-error');
        var csrfWrap = document.querySelector('.label-hidden-csrf');
        var generateUrl = <?= json_encode($generateUrl, JSON_UNESCAPED_SLASHES) ?>;
        var moduleCbs = document.querySelectorAll('.briefing-module-cb');
        var btnAll = document.getElementById('briefing-modules-all');
        var btnNone = document.getElementById('briefing-modules-none');

        function getCsrf() {
            var input = csrfWrap ? csrfWrap.querySelector('input[name="_csrf"]') : null;
            return input ? input.value : '';
        }

        function setModuleChecked(on) {
            moduleCbs.forEach(function(cb) { cb.checked = on; });
        }

        if (btnAll) {
            btnAll.addEventListener('click', function() { setModuleChecked(true); });
        }
        if (btnNone) {
            btnNone.addEventListener('click', function() { setModuleChecked(false); });
        }

        moduleCbs.forEach(function(cb) {
            cb.addEventListener('change', function() {
                var pill = cb.closest('.tag-filter-pill');
                if (pill) {
                    pill.classList.toggle('tag-filter-pill-active', cb.checked);
                }
            });
        });

        if (!form || !btn || !out) return;

        form.addEventListener('submit', function(ev) {
            ev.preventDefault();
            if (errEl) {
                errEl.hidden = true;
                errEl.textContent = '';
            }
            var checked = 0;
            moduleCbs.forEach(function(cb) { if (cb.checked) checked++; });
            if (checked === 0) {
                if (errEl) {
                    errEl.textContent = 'Select at least one source module.';
                    errEl.hidden = false;
                }
                return;
            }

            var fd = new FormData(form);
            fd.set('_csrf', getCsrf());

            btn.disabled = true;
            var prevLabel = btn.textContent;
            btn.textContent = 'Generating…';
            if (placeholder) placeholder.textContent = 'Calling Gemini — this may take 20–60 seconds…';

            fetch(generateUrl, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
            .then(function(r) {
                return r.text().then(function(t) {
                    return { status: r.status, body: t };
                });
            })
            .then(function(res) {
                var data;
                try {
                    data = JSON.parse(res.body);
                } catch (e) {
                    if (errEl) {
                        errEl.textContent = 'Invalid response (HTTP ' + res.status + ').';
                        errEl.hidden = false;
                    }
                    return;
                }
                if (!data.ok) {
                    if (errEl) {
                        errEl.textContent = data.error || 'Generation failed.';
                        errEl.hidden = false;
                    }
                    return;
                }
                if (placeholder) placeholder.remove();
                out.textContent = data.text || '';
                if (data.meta && data.meta.entry_count !== undefined) {
                    var note = document.createElement('p');
                    note.className = 'admin-intro';
                    note.style.marginTop = '1rem';
                    note.textContent = 'Based on ' + data.meta.entry_count + ' entries'
                        + (data.meta.since ? ' since ' + data.meta.since : '') + '.';
                    out.appendChild(note);
                }
            })
            .catch(function() {
                if (errEl) {
                    errEl.textContent = 'Network error — could not reach the server.';
                    errEl.hidden = false;
                }
            })
            .finally(function() {
                btn.disabled = <?= $geminiConfigured ? 'false' : 'true' ?>;
                btn.textContent = prevLabel;
            });
        });
    })();
    </script>
</body>
</html>
