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
$headerSubtitle = 'Executive Briefing (Politik & Wirtschaft)';
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
    <style>
    .briefing-output-status__lead {
        margin: 0 0 0.5rem;
        font-weight: 600;
    }
    .briefing-output-status__steps {
        list-style: none;
        margin: 0.5rem 0 0;
        padding: 0;
    }
    .briefing-output-status__step {
        padding: 0.3rem 0;
        color: var(--text-muted, #6b7280);
    }
    .briefing-output-status__step.is-active {
        color: inherit;
        font-weight: 600;
    }
    .briefing-output-status__step.is-done::before {
        content: '\2713  ';
        color: var(--seismo-accent, #2563eb);
    }
    </style>
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
                Each enabled module loads up to the entry limit below (default <?= (int)$defaultLimit ?>, maximum <?= (int)$maxLimit ?>).
                The full entry text is sent to Gemini in one pass so detail is preserved.
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
                    <textarea id="briefing_system_prompt" name="system_prompt" rows="14" class="search-input"
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
            <div id="briefing-output-warning" class="message message-warning" hidden></div>
            <div id="briefing-output" class="admin-form-card" style="white-space:pre-wrap; min-height:4rem;">
                <p class="admin-intro" id="briefing-output-placeholder">Generated text will appear here.</p>
            </div>
        </div>

        <div class="latest-entries-section module-section-spaced" id="briefing-sources-section" hidden>
            <h2 class="section-title">Source entries</h2>
            <p class="admin-intro" id="briefing-sources-intro">
                Same entries sent to Gemini, in relevance order (for validation).
            </p>
            <div id="briefing-sources-cards"></div>
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
        var warnEl = document.getElementById('briefing-output-warning');
        var sourcesSection = document.getElementById('briefing-sources-section');
        var sourcesCards = document.getElementById('briefing-sources-cards');
        var sourcesIntro = document.getElementById('briefing-sources-intro');
        var csrfWrap = document.querySelector('.label-hidden-csrf');
        var generateUrl = <?= json_encode($generateUrl, JSON_UNESCAPED_SLASHES) ?>;
        var moduleCbs = document.querySelectorAll('.briefing-module-cb');
        var btnAll = document.getElementById('briefing-modules-all');
        var btnNone = document.getElementById('briefing-modules-none');
        var statusTimerIds = [];
        var STATUS_STEPS = [
            { id: 'send', label: 'Sending request to the server' },
            { id: 'load', label: 'Loading and filtering entries from selected modules' },
            { id: 'context', label: 'Building markdown source context' },
            { id: 'gemini', label: 'Generating executive briefing with Gemini (often 20\u201360 seconds)' },
            { id: 'cards', label: 'Preparing source entry cards for validation' }
        ];

        function clearStatusTimers() {
            statusTimerIds.forEach(function(id) { clearTimeout(id); });
            statusTimerIds = [];
        }

        function scheduleStatus(delayMs, stepId) {
            statusTimerIds.push(setTimeout(function() { setActiveStatusStep(stepId); }, delayMs));
        }

        function setActiveStatusStep(stepId) {
            var list = document.getElementById('briefing-status-steps');
            if (!list) return;
            var found = false;
            list.querySelectorAll('.briefing-output-status__step').forEach(function(li) {
                var id = li.getAttribute('data-step');
                if (id === stepId) {
                    li.classList.add('is-active');
                    li.classList.remove('is-done');
                    found = true;
                    var leadText = document.getElementById('briefing-status-lead-text');
                    if (leadText) leadText.textContent = li.textContent;
                } else if (!found) {
                    li.classList.remove('is-active');
                    li.classList.add('is-done');
                } else {
                    li.classList.remove('is-active', 'is-done');
                }
            });
        }

        function showProcessingStatus() {
            clearStatusTimers();
            if (placeholder) placeholder.remove();
            out.style.whiteSpace = 'normal';
            out.innerHTML = '';
            out.setAttribute('aria-busy', 'true');

            var wrap = document.createElement('div');
            wrap.id = 'briefing-output-status';
            wrap.className = 'briefing-output-status';
            wrap.setAttribute('aria-live', 'polite');

            var lead = document.createElement('p');
            lead.id = 'briefing-status-lead';
            lead.className = 'briefing-output-status__lead';

            var leadText = document.createElement('span');
            leadText.id = 'briefing-status-lead-text';
            leadText.textContent = STATUS_STEPS[0].label;
            lead.appendChild(leadText);

            var dots = document.createElement('span');
            dots.className = 'loading-dots';
            dots.setAttribute('aria-hidden', 'true');
            dots.innerHTML = '<span class="loading-dots-char">.</span>'
                + '<span class="loading-dots-char">.</span>'
                + '<span class="loading-dots-char">.</span>';
            lead.appendChild(document.createTextNode(' '));
            lead.appendChild(dots);

            var list = document.createElement('ol');
            list.id = 'briefing-status-steps';
            list.className = 'briefing-output-status__steps';
            STATUS_STEPS.forEach(function(step, idx) {
                var li = document.createElement('li');
                li.className = 'briefing-output-status__step' + (idx === 0 ? ' is-active' : '');
                li.setAttribute('data-step', step.id);
                li.textContent = step.label;
                list.appendChild(li);
            });

            wrap.appendChild(lead);
            wrap.appendChild(list);
            out.appendChild(wrap);

            setActiveStatusStep('send');
            scheduleStatus(400, 'load');
            scheduleStatus(2000, 'context');
            scheduleStatus(5000, 'gemini');
        }

        function hideProcessingStatus() {
            clearStatusTimers();
            out.removeAttribute('aria-busy');
            var status = document.getElementById('briefing-output-status');
            if (status) status.remove();
        }

        function restoreOutputPlaceholder() {
            hideProcessingStatus();
            if (!out.querySelector('#briefing-output-placeholder') && !out.textContent.trim()) {
                out.style.whiteSpace = 'pre-wrap';
                var p = document.createElement('p');
                p.className = 'admin-intro';
                p.id = 'briefing-output-placeholder';
                p.textContent = 'Generated text will appear here.';
                out.appendChild(p);
                placeholder = p;
            }
        }

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
            if (warnEl) {
                warnEl.hidden = true;
                warnEl.textContent = '';
            }
            if (sourcesSection) sourcesSection.hidden = true;
            if (sourcesCards) sourcesCards.innerHTML = '';
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
            showProcessingStatus();

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
                setActiveStatusStep('cards');
                var data;
                try {
                    data = JSON.parse(res.body);
                } catch (e) {
                    if (errEl) {
                        errEl.textContent = 'Invalid response (HTTP ' + res.status + ').';
                        errEl.hidden = false;
                    }
                    restoreOutputPlaceholder();
                    return;
                }
                if (!data.ok) {
                    if (errEl) {
                        errEl.textContent = data.error || 'Generation failed.';
                        errEl.hidden = false;
                    }
                    restoreOutputPlaceholder();
                    return;
                }
                if (data.meta && data.meta.context_warning && warnEl) {
                    warnEl.textContent = data.meta.context_warning;
                    warnEl.hidden = false;
                }
                hideProcessingStatus();
                out.style.whiteSpace = 'pre-wrap';
                out.textContent = data.text || '';
                if (data.meta) {
                    var note = document.createElement('p');
                    note.className = 'admin-intro';
                    note.style.marginTop = '1rem';
                    var parts = [];
                    if (data.meta.entry_count !== undefined) {
                        parts.push(String(data.meta.entry_count) + ' entries');
                    }
                    if (data.meta.markdown_chars !== undefined) {
                        parts.push(Math.round(data.meta.markdown_chars / 1024) + ' KB source context');
                    }
                    if (data.meta.since) {
                        parts.push('since ' + data.meta.since);
                    }
                    if (parts.length) {
                        note.textContent = 'Based on ' + parts.join(' · ') + '.';
                        out.appendChild(note);
                    }
                }
                if (data.entries_html && sourcesCards) {
                    sourcesCards.innerHTML = data.entries_html;
                    if (sourcesIntro && data.meta && data.meta.entry_count !== undefined) {
                        sourcesIntro.textContent =
                            String(data.meta.entry_count) +
                            ' entries sent to Gemini, in relevance order (for validation).';
                    }
                    if (sourcesSection) sourcesSection.hidden = false;
                }
            })
            .catch(function() {
                if (errEl) {
                    errEl.textContent = 'Network error — could not reach the server.';
                    errEl.hidden = false;
                }
                restoreOutputPlaceholder();
            })
            .finally(function() {
                clearStatusTimers();
                out.removeAttribute('aria-busy');
                btn.disabled = <?= $geminiConfigured ? 'false' : 'true' ?>;
                btn.textContent = prevLabel;
            });
        });

        function collapseEntryCard(card, btn) {
            var preview = card.querySelector('.entry-preview');
            var full = card.querySelector('.entry-full-content');
            if (!preview || !full) return;
            full.style.display = 'none';
            preview.style.display = '';
            if (btn) btn.textContent = 'expand \u25BC';
        }
        function expandEntryCard(card, btn) {
            var preview = card.querySelector('.entry-preview');
            var full = card.querySelector('.entry-full-content');
            if (!preview || !full) return;
            preview.style.display = 'none';
            full.style.display = 'block';
            if (btn) btn.textContent = 'collapse \u25B2';
        }
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.entry-expand-btn');
            if (!btn || !sourcesCards || !sourcesCards.contains(btn)) return;
            var card = btn.closest('.entry-card');
            if (!card) return;
            var full = card.querySelector('.entry-full-content');
            if (!full) return;
            full.style.display === 'block'
                ? collapseEntryCard(card, btn)
                : expandEntryCard(card, btn);
        });
    })();
    </script>
</body>
</html>
