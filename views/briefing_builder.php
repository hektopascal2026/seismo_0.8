<?php
/**
 * AI Briefing Builder — filter entries and generate a Gemini summary.
 *
 * @var string $csrfField
 * @var string $basePath
 * @var bool $geminiConfigured
 * @var string $systemPrompt
 * @var bool $defaultPromptStored
 * @var list<array{id: string, name: string, content: string}> $savedPrompts
 * @var string|null $initialActivePromptTabId
 * @var int $defaultLookbackDays
 * @var int $defaultLimit
 * @var int $maxLimit
 * @var int $defaultItemCount
 * @var list<int> $itemCountOptions
 * @var float $alertThreshold Magnitu alert threshold (0–1) for this desk
 */

declare(strict_types=1);

$accent = seismoBrandAccent();

$headerTitle    = 'Briefing';
$headerSubtitle = '';
$activeNav      = 'briefing_builder';

$prepareUrl         = $basePath . '/index.php?action=briefing_builder_prepare';
$generateUrl        = $basePath . '/index.php?action=briefing_builder_generate';
$savePromptUrl      = $basePath . '/index.php?action=briefing_builder_save_prompt';
$saveLibraryUrl     = $basePath . '/index.php?action=save_briefing_prompt';
$deleteLibraryUrl   = $basePath . '/index.php?action=delete_briefing_prompt';

$defaultPromptStored = $defaultPromptStored ?? false;
$initialActivePromptTabId = $initialActivePromptTabId ?? null;
$saveDefaultPromptLabel = $defaultPromptStored ? 'Update prompt' : 'Save prompt';
$saveDefaultPromptTitle = $defaultPromptStored
    ? 'Update the default prompt for this instance'
    : 'Save as the default prompt for this instance';

$alertThresholdPct = (int)round(max(0.0, min(1.0, (float)($alertThreshold ?? 0.60))) * 100);
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
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css?v=<?= e(SEISMO_VERSION) ?>">
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
    .briefing-output-error-inline {
        margin: 0;
        color: #b91c1c;
        font-weight: 600;
    }
    .briefing-summary-block {
        max-width: 40rem;
    }
    .briefing-summary-toolbar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        margin-bottom: 0.75rem;
    }
    .briefing-summary-toolbar .section-title {
        margin: 0;
    }
    #briefing-copy-btn {
        display: none;
    }
    #briefing-copy-btn.briefing-copy-btn--visible {
        display: inline-block;
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
            <div class="admin-form-card briefing-selection-help" style="margin-bottom:1rem;">
                <h2 class="section-title" style="margin-top:0;">What gets selected</h2>
                <p class="admin-intro" style="margin:0.5rem 0 0;">
                    Entries sent to Gemini are chosen by <strong>relevance score</strong> (same rules as the
                    <a href="<?= e($basePath) ?>/index.php?action=magnitu">Highlights</a> tab), then narrowed by your
                    choices below.
                </p>
                <ul class="admin-intro" style="margin:0.5rem 0 0;padding-left:1.25rem;">
                    <li>
                        <strong>Always:</strong> scored items at or above the Highlights bar —
                        relevance ≥ <strong><?= (int)$alertThresholdPct ?>%</strong>
                        (<a href="<?= e($magnituSettingsUrl) ?>">Settings → Magnitu → Alert threshold</a>).
                        Badge colour (important vs investigation) still uses fixed bands at 50% and 75%;
                        both bands above your threshold are included.
                    </li>
                    <li>
                        <strong>Source modules:</strong> only checked families (Feeds, Media, Scraper, Mail, Lex, Leg).
                    </li>
                    <li>
                        <strong>Lookback:</strong> entries whose official date or Seismo ingest time falls inside the window
                        (feeds: published or cached; Leg: event or fetch date).
                    </li>
                    <li>
                        <strong>Per-module limit:</strong> caps how many recent rows are loaded per module before
                        score filtering; raise it if the context count looks too low.
                    </li>
                    <li>
                        <strong>Optional — Also include important band below threshold:</strong> adds scored items
                        with relevance <strong>&gt; 50%</strong> and <strong>&lt; <?= (int)$alertThresholdPct ?>%</strong>
                        (the yellow “important” band under your Highlights bar).
                    </li>
                </ul>
                <p class="admin-intro" style="margin:0.5rem 0 0;">
                    <strong>Number of items</strong> is separate: it fixes how many core items Gemini must cite in the
                    briefing, not how many sources are loaded.
                </p>
            </div>

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
                    <label>Relevance</label>
                    <p class="admin-intro" style="margin:0.25rem 0 0.5rem;">
                        Highlights tier (≥ <?= (int)$alertThresholdPct ?>%) is always included.
                    </p>
                    <label>
                        <input type="checkbox" name="include_important" value="1">
                        Also include important band below threshold (&gt; 50%, &lt; <?= (int)$alertThresholdPct ?>%)
                    </label>
                </div>

                <div class="admin-form-field">
                    <label for="briefing_lookback">Lookback window</label>
                    <select id="briefing_lookback" name="lookback_days" class="search-input" style="width:auto;">
                        <?php for ($d = 1; $d <= 7; $d++): ?>
                        <option value="<?= $d ?>"<?= $defaultLookbackDays === $d ? ' selected' : '' ?>>
                            <?= $d === 1 ? '1 day' : $d . ' days' ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="admin-form-field">
                    <label for="briefing_limit">Entry limit (per module)</label>
                    <input type="number" id="briefing_limit" name="limit" class="search-input" style="width:7rem;"
                           min="1" max="<?= (int)$maxLimit ?>" value="<?= (int)$defaultLimit ?>">
                </div>

                <div class="admin-form-field">
                    <label for="briefing_item_count">Number of items</label>
                    <select id="briefing_item_count" name="item_count" class="search-input" style="width:auto;">
                        <?php foreach ($itemCountOptions as $n): ?>
                        <option value="<?= (int)$n ?>"<?= $defaultItemCount === $n ? ' selected' : '' ?>>
                            <?= (int)$n ?> items
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="admin-intro" style="margin:0.25rem 0 0;">Fixes how many cited core items Gemini must return (and matching source cards). Headings and layout come from your prompt.</p>
                </div>

                <div class="admin-form-field">
                    <label class="admin-checkbox-label" style="display:flex; align-items:flex-start; gap:0.5rem; cursor:pointer;">
                        <input type="checkbox" id="briefing_two_pass" name="two_pass" value="1"
                               style="margin-top:0.2rem;">
                        <span>
                            <strong>Two-pass generation</strong> (experimental)
                            <span class="admin-intro" style="display:block; margin:0.25rem 0 0;">
                                Pass 1 selects entries with capped thinking; pass 2 writes the briefing from those only.
                                Slower (~2× API calls) but can improve story picking on large pools. Off by default.
                            </span>
                        </span>
                    </label>
                </div>

                <div class="admin-form-field">
                    <label for="briefing_system_prompt">System prompt</label>
                    <div class="prompt-tabs" id="prompt-tabs" role="tablist" aria-label="Saved prompts">
                        <?php foreach ($savedPrompts as $i => $sp): ?>
                        <div class="prompt-tab-wrap">
                            <button type="button" class="prompt-tab<?= $initialActivePromptTabId === $sp['id'] ? ' is-active' : '' ?>"
                                    role="tab"
                                    data-id="<?= e($sp['id']) ?>"
                                    aria-selected="<?= $initialActivePromptTabId === $sp['id'] ? 'true' : 'false' ?>">
                                <span class="prompt-tab__label"><?= e($sp['name']) ?></span>
                            </button>
                            <button type="button" class="prompt-tab-delete"
                                    data-id="<?= e($sp['id']) ?>"
                                    aria-label="Delete prompt <?= e($sp['name']) ?>"
                                    title="Delete">×</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <span id="prompt-library-msg" class="admin-intro" style="margin:0.25rem 0 0;" hidden></span>
                    <textarea id="briefing_system_prompt" name="system_prompt" rows="22" class="search-input"
                              style="width:100%; max-width:40rem;"><?= e($systemPrompt) ?></textarea>
                </div>

                <div class="admin-form-actions" style="display:flex; flex-wrap:wrap; gap:0.5rem; align-items:center;">
                    <button type="submit" class="btn btn-success" id="briefing-generate-btn"
                            <?= $geminiConfigured ? '' : ' disabled' ?>>Generate briefing</button>
                    <button type="button" class="btn btn-secondary" id="briefing-save-prompt-btn"
                            title="<?= e($saveDefaultPromptTitle) ?>"><?= e($saveDefaultPromptLabel) ?></button>
                    <button type="button" class="btn btn-secondary" id="save-prompt-library-btn"
                            title="Add the current textarea as a named prompt in the library">Save to library</button>
                    <span id="briefing-prompt-save-msg" class="admin-intro" style="margin:0;" hidden></span>
                </div>
            </form>
        </div>

        <div class="latest-entries-section module-section-spaced briefing-summary-block">
            <div class="briefing-summary-toolbar">
                <h2 class="section-title">Summary</h2>
                <button type="button" class="btn btn-secondary" id="briefing-copy-btn"
                        hidden aria-hidden="true" tabindex="-1">Copy to clipboard</button>
            </div>
            <div id="briefing-output-error" class="message message-error" hidden></div>
            <div id="briefing-output-warning" class="message message-warning" hidden></div>
            <div id="briefing-output" class="admin-form-card" style="white-space:pre-wrap; min-height:4rem; max-width:100%;">
                <p class="admin-intro" id="briefing-output-placeholder">Generated text will appear here.</p>
            </div>
        </div>

        <div class="latest-entries-section module-section-spaced" id="briefing-sources-section" hidden>
            <h2 class="section-title">Referenced source entries</h2>
            <p class="admin-intro" id="briefing-sources-intro">
                Entry cards cited for the top developments (attribution).
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
        var prepareUrl = <?= json_encode($prepareUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
        var generateUrl = <?= json_encode($generateUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
        var savePromptUrl = <?= json_encode($savePromptUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
        var saveLibraryUrl = <?= json_encode($saveLibraryUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
        var deleteLibraryUrl = <?= json_encode($deleteLibraryUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
        var savedPrompts = <?= json_encode($savedPrompts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
        var savePromptBtn = document.getElementById('briefing-save-prompt-btn');
        var savePromptMsg = document.getElementById('briefing-prompt-save-msg');
        var saveLibraryBtn = document.getElementById('save-prompt-library-btn');
        var promptTabsEl = document.getElementById('prompt-tabs');
        var promptLibraryMsg = document.getElementById('prompt-library-msg');
        var promptTextarea = document.getElementById('briefing_system_prompt');
        var activePromptId = null;
        var initialActivePromptTabId = <?= json_encode($initialActivePromptTabId, JSON_UNESCAPED_UNICODE) ?>;
        var defaultPromptStored = <?= $defaultPromptStored ? 'true' : 'false' ?>;
        var PROMPT_TAB_DEFAULT_NAME = 'Default';
        var copyBtn = document.getElementById('briefing-copy-btn');
        var lastBriefingText = '';
        var COPY_BTN_LABEL = 'Copy to clipboard';
        var moduleCbs = document.querySelectorAll('.briefing-module-cb');
        var btnAll = document.getElementById('briefing-modules-all');
        var btnNone = document.getElementById('briefing-modules-none');

        function syncModulePill(cb) {
            var pill = cb.closest('.tag-filter-pill');
            if (pill) {
                pill.classList.toggle('tag-filter-pill-active', cb.checked);
            }
        }

        function setModuleChecked(on) {
            moduleCbs.forEach(function(cb) {
                cb.checked = on;
                syncModulePill(cb);
            });
        }

        function initBriefingModuleToggles() {
            if (btnAll) {
                btnAll.addEventListener('click', function(ev) {
                    ev.preventDefault();
                    setModuleChecked(true);
                });
            }
            if (btnNone) {
                btnNone.addEventListener('click', function(ev) {
                    ev.preventDefault();
                    setModuleChecked(false);
                });
            }
            moduleCbs.forEach(function(cb) {
                syncModulePill(cb);
                cb.addEventListener('change', function() {
                    syncModulePill(cb);
                });
            });
        }

        initBriefingModuleToggles();

        var statusTimerIds = [];
        var STATUS_STEPS_SINGLE = [
            { id: 'send', label: 'Sending request to the server' },
            { id: 'load', label: 'Loading and filtering entries from selected modules' },
            { id: 'context', label: 'Building markdown source context' },
            { id: 'gemini', label: 'Generating executive briefing with Gemini (often 20\u201360 seconds)' },
            { id: 'cards', label: 'Preparing source entry cards for validation' }
        ];
        var STATUS_STEPS_TWO_PASS = [
            { id: 'send', label: 'Sending request to the server' },
            { id: 'load', label: 'Loading and filtering entries from selected modules' },
            { id: 'context', label: 'Building markdown source context' },
            { id: 'select', label: 'Gemini pass 1: selecting top entries (thinking)' },
            { id: 'write', label: 'Gemini pass 2: writing executive briefing' },
            { id: 'cards', label: 'Preparing source entry cards for validation' }
        ];

        function isTwoPassEnabled() {
            var cb = document.getElementById('briefing_two_pass');
            return cb && cb.checked;
        }

        function statusStepsForMode() {
            return isTwoPassEnabled() ? STATUS_STEPS_TWO_PASS : STATUS_STEPS_SINGLE;
        }

        function clearStatusTimers() {
            statusTimerIds.forEach(function(id) { clearTimeout(id); });
            statusTimerIds = [];
        }

        function scheduleStatus(delayMs, stepId) {
            statusTimerIds.push(setTimeout(function() { setActiveStatusStep(stepId); }, delayMs));
        }

        function setStatusStepLabel(stepId, label) {
            var list = document.getElementById('briefing-status-steps');
            if (!list) return;
            var li = list.querySelector('.briefing-output-status__step[data-step="' + stepId + '"]');
            if (li) li.textContent = label;
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

        function applyStatusEntryCount(entryCount) {
            clearStatusTimers();
            var n = parseInt(entryCount, 10);
            if (isNaN(n) || n < 0) return;
            var entryWord = n === 1 ? 'entry' : 'entries';
            if (n === 0) {
                setStatusStepLabel('load', 'No entries matched your filters');
                setActiveStatusStep('load');
                return;
            }
            setStatusStepLabel('load', 'Loaded and filtered ' + n + ' ' + entryWord + ' from selected modules');
            setStatusStepLabel('context', 'Built markdown source context from ' + n + ' ' + entryWord);
            if (isTwoPassEnabled()) {
                setStatusStepLabel(
                    'select',
                    'Sent ' + n + ' ' + entryWord + ' to Gemini — pass 1: selecting top items'
                );
                setStatusStepLabel(
                    'write',
                    'Gemini pass 2: writing full executive briefing (often 30\u201390 seconds total)'
                );
                setActiveStatusStep('select');
            } else {
                setStatusStepLabel(
                    'gemini',
                    'Sent ' + n + ' ' + entryWord + ' to Gemini — generating executive briefing (often 20\u201360 seconds)'
                );
                setActiveStatusStep('gemini');
            }
        }

        function postBriefingAction(url, formData) {
            return fetch(url, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function(r) {
                return r.text().then(function(t) {
                    var data;
                    try {
                        data = JSON.parse(t);
                    } catch (e) {
                        return { ok: false, error: 'Invalid response (HTTP ' + r.status + ').' };
                    }
                    if (!data.ok) {
                        data.error = data.error || 'Request failed.';
                    }
                    return data;
                });
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
            leadText.textContent = statusStepsForMode()[0].label;
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
            statusStepsForMode().forEach(function(step, idx) {
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
            if (isTwoPassEnabled()) {
                scheduleStatus(5000, 'select');
                scheduleStatus(14000, 'write');
            } else {
                scheduleStatus(5000, 'gemini');
            }
        }

        function hideProcessingStatus() {
            clearStatusTimers();
            out.removeAttribute('aria-busy');
            var status = document.getElementById('briefing-output-status');
            if (status) status.remove();
        }

        function restoreOutputPlaceholder() {
            hideCopyBtn();
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

        function hideCopyBtn() {
            lastBriefingText = '';
            if (copyBtn) {
                copyBtn.hidden = true;
                copyBtn.classList.remove('briefing-copy-btn--visible');
                copyBtn.setAttribute('aria-hidden', 'true');
                copyBtn.tabIndex = -1;
                copyBtn.textContent = COPY_BTN_LABEL;
            }
        }

        function showCopyBtn(text) {
            lastBriefingText = text || '';
            if (!copyBtn) return;
            var ready = lastBriefingText.trim() !== '';
            copyBtn.textContent = COPY_BTN_LABEL;
            if (ready) {
                copyBtn.hidden = false;
                copyBtn.classList.add('briefing-copy-btn--visible');
                copyBtn.setAttribute('aria-hidden', 'false');
                copyBtn.tabIndex = 0;
            } else {
                hideCopyBtn();
            }
        }

        function copyBriefingToClipboard() {
            if (!copyBtn || lastBriefingText.trim() === '') return;
            function copied() {
                copyBtn.textContent = 'Copied';
                setTimeout(function() { copyBtn.textContent = COPY_BTN_LABEL; }, 2000);
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(lastBriefingText).then(copied).catch(fallbackCopy);
                return;
            }
            fallbackCopy();
            function fallbackCopy() {
                var ta = document.createElement('textarea');
                ta.value = lastBriefingText;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                try {
                    if (document.execCommand('copy')) copied();
                } catch (e) { /* ignore */ }
                document.body.removeChild(ta);
            }
        }

        if (copyBtn) {
            copyBtn.addEventListener('click', copyBriefingToClipboard);
        }

        function showPromptLibraryMsg(text, isError) {
            if (!promptLibraryMsg) return;
            promptLibraryMsg.textContent = text;
            promptLibraryMsg.classList.toggle('message-error', !!isError);
            promptLibraryMsg.hidden = text === '';
        }

        function syncLibrarySaveButtonLabel() {
            if (!saveLibraryBtn) return;
            saveLibraryBtn.textContent = activePromptId ? 'Update prompt' : 'Save to library';
            saveLibraryBtn.title = activePromptId
                ? 'Save changes to the selected library prompt'
                : 'Add the current textarea as a named prompt in the library';
        }

        function syncInstanceSaveButton() {
            if (!savePromptBtn) return;
            var editingLibrary = activePromptId !== null;
            savePromptBtn.disabled = editingLibrary;
            savePromptBtn.title = editingLibrary
                ? 'Select the Default tab to update the reload default for this desk'
                : (defaultPromptStored
                    ? 'Update the default prompt for this instance'
                    : 'Save as the default prompt for this instance');
        }

        function syncPromptSaveButtons() {
            syncLibrarySaveButtonLabel();
            syncInstanceSaveButton();
        }

        function highlightPromptTab(id) {
            if (!promptTabsEl || !id) return;
            promptTabsEl.querySelectorAll('.prompt-tab').forEach(function(tab) {
                var on = tab.getAttribute('data-id') === id;
                tab.classList.toggle('is-active', on);
                tab.setAttribute('aria-selected', on ? 'true' : 'false');
            });
        }

        function highlightedPromptTabId() {
            if (activePromptId) {
                return activePromptId;
            }
            var def = savedPrompts.find(function(p) { return p.name === PROMPT_TAB_DEFAULT_NAME; });
            return def ? def.id : null;
        }

        function selectInstanceDefaultPrompt() {
            var row = savedPrompts.find(function(p) { return p.name === PROMPT_TAB_DEFAULT_NAME; });
            activePromptId = null;
            if (row && promptTextarea) {
                promptTextarea.value = row.content;
                highlightPromptTab(row.id);
            } else {
                highlightPromptTab(null);
            }
            syncPromptSaveButtons();
        }

        function selectLibraryPrompt(row) {
            if (!row || !promptTextarea) return;
            activePromptId = row.id;
            promptTextarea.value = row.content;
            highlightPromptTab(row.id);
            syncPromptSaveButtons();
        }

        function setActivePromptTab(id) {
            highlightPromptTab(id);
            activePromptId = id;
            syncPromptSaveButtons();
        }

        function renderPromptTabs(prompts) {
            savedPrompts = prompts || [];
            if (!promptTabsEl) return;
            promptTabsEl.innerHTML = '';
            if (!savedPrompts.length) {
                activePromptId = null;
                syncPromptSaveButtons();
                return;
            }
            var keepActive = highlightedPromptTabId();
            savedPrompts.forEach(function(p) {
                var wrap = document.createElement('div');
                wrap.className = 'prompt-tab-wrap';

                var tab = document.createElement('button');
                tab.type = 'button';
                tab.className = 'prompt-tab' + (p.id === keepActive ? ' is-active' : '');
                tab.setAttribute('role', 'tab');
                tab.setAttribute('data-id', p.id);
                tab.setAttribute('aria-selected', p.id === keepActive ? 'true' : 'false');
                var label = document.createElement('span');
                label.className = 'prompt-tab__label';
                label.textContent = p.name;
                tab.appendChild(label);

                var del = document.createElement('button');
                del.type = 'button';
                del.className = 'prompt-tab-delete';
                del.setAttribute('data-id', p.id);
                del.setAttribute('aria-label', 'Delete prompt ' + p.name);
                del.setAttribute('title', 'Delete');
                del.textContent = '\u00d7';

                wrap.appendChild(tab);
                wrap.appendChild(del);
                promptTabsEl.appendChild(wrap);
            });
            bindPromptTabEvents();
            syncPromptSaveButtons();
        }

        function bindPromptTabEvents() {
            if (!promptTabsEl) return;
            promptTabsEl.querySelectorAll('.prompt-tab').forEach(function(tab) {
                tab.addEventListener('click', function() {
                    var id = tab.getAttribute('data-id');
                    var row = savedPrompts.find(function(p) { return p.id === id; });
                    if (!row) return;
                    if (row.name === PROMPT_TAB_DEFAULT_NAME) {
                        selectInstanceDefaultPrompt();
                    } else {
                        selectLibraryPrompt(row);
                    }
                    showPromptLibraryMsg('', false);
                });
            });
            promptTabsEl.querySelectorAll('.prompt-tab-delete').forEach(function(btn) {
                btn.addEventListener('click', function(ev) {
                    ev.stopPropagation();
                    var id = btn.getAttribute('data-id');
                    var row = savedPrompts.find(function(p) { return p.id === id; });
                    var label = row ? row.name : 'this prompt';
                    if (!confirm('Delete prompt "' + label + '"?')) return;
                    var fd = new FormData();
                    fd.set('id', id);
                    fd.set('_csrf', getCsrf());
                    btn.disabled = true;
                    fetch(deleteLibraryUrl, {
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
                            showPromptLibraryMsg('Invalid response (HTTP ' + res.status + ').', true);
                            return;
                        }
                        if (!data.ok) {
                            showPromptLibraryMsg(data.error || 'Could not delete prompt.', true);
                            return;
                        }
                        savedPrompts = savedPrompts.filter(function(p) { return p.id !== id; });
                        if (activePromptId === id) {
                            activePromptId = null;
                        }
                        renderPromptTabs(savedPrompts);
                        if (activePromptId === null) {
                            selectInstanceDefaultPrompt();
                        }
                        showPromptLibraryMsg('', false);
                    })
                    .catch(function() {
                        showPromptLibraryMsg('Network error — could not reach the server.', true);
                    })
                    .finally(function() {
                        btn.disabled = false;
                    });
                });
            });
        }

        bindPromptTabEvents();
        if (initialActivePromptTabId) {
            highlightPromptTab(initialActivePromptTabId);
        }
        syncPromptSaveButtons();

        if (saveLibraryBtn && saveLibraryUrl && promptTextarea) {
            saveLibraryBtn.addEventListener('click', function() {
                var updating = !!activePromptId;
                var name = '';
                if (!updating) {
                    name = window.prompt('Prompt name');
                    if (name === null) return;
                    name = name.trim();
                    if (name === '') return;
                }
                showPromptLibraryMsg('', false);
                var fd = new FormData();
                if (updating) {
                    fd.set('id', activePromptId);
                } else {
                    fd.set('name', name);
                }
                fd.set('content', promptTextarea.value);
                fd.set('_csrf', getCsrf());
                saveLibraryBtn.disabled = true;
                saveLibraryBtn.textContent = 'Saving…';
                fetch(saveLibraryUrl, {
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
                        showPromptLibraryMsg('Invalid response (HTTP ' + res.status + ').', true);
                        return;
                    }
                    if (!data.ok || !data.prompts) {
                        showPromptLibraryMsg(data.error || 'Could not save prompt to library.', true);
                        return;
                    }
                    savedPrompts = data.prompts;
                    renderPromptTabs(data.prompts);
                    if (updating) {
                        var row = savedPrompts.find(function(p) { return p.id === activePromptId; });
                        if (row) {
                            selectLibraryPrompt(row);
                        }
                        showPromptLibraryMsg('Prompt updated.', false);
                    } else {
                        var newId = null;
                        if (data.prompts.length) {
                            newId = data.prompts[data.prompts.length - 1].id;
                        }
                        if (newId) {
                            var created = savedPrompts.find(function(p) { return p.id === newId; });
                            if (created) {
                                selectLibraryPrompt(created);
                            }
                        }
                        showPromptLibraryMsg('Prompt saved to library.', false);
                    }
                })
                .catch(function() {
                    showPromptLibraryMsg('Network error — could not reach the server.', true);
                })
                .finally(function() {
                    saveLibraryBtn.disabled = false;
                    syncPromptSaveButtons();
                });
            });
        }

        if (savePromptBtn && savePromptUrl) {
            savePromptBtn.addEventListener('click', function() {
                if (activePromptId !== null) {
                    if (savePromptMsg) {
                        savePromptMsg.textContent =
                            'Select the Default tab to update the reload default (or use Update prompt on the library tab).';
                        savePromptMsg.classList.add('message-error');
                        savePromptMsg.hidden = false;
                    }
                    return;
                }
                var promptEl = document.getElementById('briefing_system_prompt');
                if (!promptEl) return;
                if (savePromptMsg) {
                    savePromptMsg.hidden = true;
                    savePromptMsg.textContent = '';
                    savePromptMsg.classList.remove('message-error');
                }
                var fd = new FormData();
                fd.set('system_prompt', promptEl.value);
                fd.set('_csrf', getCsrf());
                savePromptBtn.disabled = true;
                savePromptBtn.textContent = 'Saving…';
                fetch(savePromptUrl, {
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
                        if (savePromptMsg) {
                            savePromptMsg.textContent = 'Invalid response (HTTP ' + res.status + ').';
                            savePromptMsg.classList.add('message-error');
                            savePromptMsg.hidden = false;
                        }
                        return;
                    }
                    if (!data.ok) {
                        if (savePromptMsg) {
                            savePromptMsg.textContent = data.error || 'Could not save prompt.';
                            savePromptMsg.classList.add('message-error');
                            savePromptMsg.hidden = false;
                        }
                        return;
                    }
                    var firstDefaultSave = !defaultPromptStored;
                    defaultPromptStored = true;
                    savePromptBtn.textContent = 'Update prompt';
                    var def = savedPrompts.find(function(p) { return p.name === PROMPT_TAB_DEFAULT_NAME; });
                    if (def) {
                        def.content = promptEl.value;
                    }
                    syncInstanceSaveButton();
                    if (savePromptMsg) {
                        savePromptMsg.textContent = firstDefaultSave
                            ? 'Prompt saved for this instance.'
                            : 'Prompt updated for this instance.';
                        savePromptMsg.classList.remove('message-error');
                        savePromptMsg.hidden = false;
                    }
                })
                .catch(function() {
                    if (savePromptMsg) {
                        savePromptMsg.textContent = 'Network error — could not reach the server.';
                        savePromptMsg.classList.add('message-error');
                        savePromptMsg.hidden = false;
                    }
                })
                .finally(function() {
                    if (savePromptBtn.textContent === 'Saving…') {
                        savePromptBtn.textContent = defaultPromptStored ? 'Update prompt' : 'Save prompt';
                    }
                    syncPromptSaveButtons();
                });
            });
        }

        if (!form || !btn || !out) return;

        form.addEventListener('submit', function(ev) {
            ev.preventDefault();
            hideCopyBtn();
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
            try {
                showProcessingStatus();
            } catch (statusErr) {
                console.error('Briefing status UI failed:', statusErr);
                if (placeholder) placeholder.remove();
                out.style.whiteSpace = 'pre-wrap';
                out.innerHTML = '';
                var fallback = document.createElement('p');
                fallback.className = 'admin-intro';
                fallback.textContent = 'Generating briefing…';
                out.appendChild(fallback);
                out.setAttribute('aria-busy', 'true');
            }

            postBriefingAction(prepareUrl, fd)
            .then(function(prep) {
                if (!prep.ok) {
                    throw prep;
                }
                if (prep.meta && prep.meta.context_warning && warnEl) {
                    warnEl.textContent = prep.meta.context_warning;
                    warnEl.hidden = false;
                }
                if (prep.meta && prep.meta.entry_count !== undefined) {
                    applyStatusEntryCount(prep.meta.entry_count);
                }
                if (prep.meta && prep.meta.gather_stats && warnEl) {
                    var gs = prep.meta.gather_stats;
                    var before = parseInt(gs.entries_before_score_filter, 10);
                    var after = parseInt(gs.entries_after_score_filter, 10);
                    if (!isNaN(before) && !isNaN(after) && before > after) {
                        var dropped = before - after;
                        var dropNote = dropped + ' in-window '
                            + (dropped === 1 ? 'entry' : 'entries')
                            + ' dropped by relevance filter (unscored or below 50% / Highlights bar).';
                        warnEl.textContent = warnEl.textContent
                            ? warnEl.textContent + ' ' + dropNote
                            : dropNote;
                        warnEl.hidden = false;
                    }
                }
                return postBriefingAction(generateUrl, fd);
            })
            .then(function(data) {
                if (!data) return;
                if (!data.ok) {
                    hideCopyBtn();
                    var errMsg = data.error || 'Generation failed.';
                    if (errEl) {
                        errEl.textContent = errMsg;
                        errEl.hidden = false;
                    }
                    hideProcessingStatus();
                    out.style.whiteSpace = 'pre-wrap';
                    out.innerHTML = '';
                    var errInBox = document.createElement('p');
                    errInBox.className = 'briefing-output-error-inline';
                    errInBox.textContent = errMsg;
                    out.appendChild(errInBox);
                    return;
                }
                function renderBriefingSuccess(payload) {
                    if (warnEl) {
                        var warnParts = [];
                        if (payload.meta && payload.meta.context_warning) {
                            warnParts.push(payload.meta.context_warning);
                        }
                        if (payload.meta && payload.meta.attribution_warning) {
                            warnParts.push(payload.meta.attribution_warning);
                        }
                        if (warnParts.length) {
                            warnEl.textContent = warnParts.join(' ');
                            warnEl.hidden = false;
                        }
                    }
                    hideProcessingStatus();
                    out.style.whiteSpace = 'pre-wrap';
                    var briefingText = (payload.text && String(payload.text).trim()) ? String(payload.text) : '';
                    if (briefingText === '') {
                        var emptyMsg = 'Gemini returned an empty briefing. Try again, reduce modules or lookback, or enable two-pass.';
                        if (errEl) {
                            errEl.textContent = emptyMsg;
                            errEl.hidden = false;
                        }
                        out.textContent = emptyMsg;
                        hideCopyBtn();
                        return;
                    }
                    out.textContent = briefingText;
                    showCopyBtn(briefingText);
                    if (payload.meta) {
                        var note = document.createElement('p');
                        note.className = 'admin-intro';
                        note.style.marginTop = '1rem';
                        var parts = [];
                        if (payload.meta.entry_count !== undefined) {
                            parts.push(String(payload.meta.entry_count) + ' entries in context');
                        }
                        if (payload.meta.item_count !== undefined) {
                            parts.push(String(payload.meta.item_count) + ' developments requested');
                        }
                        if (payload.meta.cited_entry_count !== undefined) {
                            parts.push(String(payload.meta.cited_entry_count) + ' cited');
                        }
                        if (payload.meta.two_pass) {
                            parts.push('two-pass');
                        }
                        if (payload.meta.markdown_chars !== undefined) {
                            parts.push(Math.round(payload.meta.markdown_chars / 1024) + ' KB source context');
                        }
                        if (payload.meta.since) {
                            parts.push('since ' + payload.meta.since);
                        }
                        if (parts.length) {
                            note.textContent = 'Based on ' + parts.join(' · ') + '.';
                            out.appendChild(note);
                        }
                    }
                    if (payload.entries_html && sourcesCards) {
                        sourcesCards.innerHTML = payload.entries_html;
                        if (sourcesIntro && payload.meta) {
                            var introParts = [];
                            if (payload.meta.attribution_filtered && payload.meta.attributed_entry_count !== undefined) {
                                introParts.push(
                                    String(payload.meta.attributed_entry_count) +
                                    ' entries cited in the briefing (attribution order)'
                                );
                                if (payload.meta.context_entry_count !== undefined) {
                                    introParts.push(
                                        String(payload.meta.context_entry_count) + ' sent as context'
                                    );
                                }
                            } else if (payload.meta.context_entry_count !== undefined) {
                                introParts.push(
                                    String(payload.meta.context_entry_count) +
                                    ' context entries shown (attribution unavailable)'
                                );
                            }
                            if (introParts.length) {
                                sourcesIntro.textContent = introParts.join(' · ') + '.';
                            }
                        }
                        if (sourcesSection) sourcesSection.hidden = false;
                    }
                }

                if (data.meta && data.meta.entry_count !== undefined) {
                    applyStatusEntryCount(data.meta.entry_count);
                }
                setActiveStatusStep('cards');
                renderBriefingSuccess(data);
            })
            .catch(function(err) {
                hideCopyBtn();
                var msg = (err && err.error) ? err.error : 'Network error — could not reach the server.';
                if (errEl) {
                    errEl.textContent = msg;
                    errEl.hidden = false;
                }
                hideProcessingStatus();
                out.style.whiteSpace = 'pre-wrap';
                out.innerHTML = '';
                if (msg) {
                    var errInBox = document.createElement('p');
                    errInBox.className = 'briefing-output-error-inline';
                    errInBox.textContent = msg;
                    out.appendChild(errInBox);
                } else {
                    restoreOutputPlaceholder();
                }
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
