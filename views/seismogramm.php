<?php
/**
 * Seismogramm - Simplified, preset-driven AI briefing template.
 *
 * @var string $csrfField
 * @var string $basePath
 * @var bool $geminiConfigured
 * @var list<array{id: string, name: string, content: string}> $savedPrompts
 * @var string|null $initialActivePromptTabId
 * @var int $defaultLookbackDays
 * @var int $defaultLimit
 * @var int $maxLimit
 * @var int $defaultItemCount
 * @var list<int> $itemCountOptions
 * @var float $alertThreshold
 * @var int $maxContextEntries
 */

declare(strict_types=1);

$accent = seismoBrandAccent();
$headerTitle = 'Seismogramm';
$activeNav = 'seismogramm';

$prepareUrl = $basePath . '/index.php?action=seismogramm_prepare';
$generateUrl = $basePath . '/index.php?action=seismogramm_generate';
$promptHelperUrl = $basePath . '/index.php?action=seismogramm_prompt_helper';

$alertThresholdPct = (int)round($alertThreshold * 100);

$moduleOptions = [
    ['key' => 'feeds', 'label' => 'Feeds'],
    ['key' => 'media', 'label' => 'Media'],
    ['key' => 'email', 'label' => 'Mail'],
    ['key' => 'newsletter', 'label' => 'Newsletter'],
    ['key' => 'scraper', 'label' => 'Scraper'],
    ['key' => 'lex', 'label' => 'Lex'],
    ['key' => 'leg', 'label' => 'Leg'],
    ['key' => 'mem', 'label' => 'Mem'],
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
    .seismogramm-preset-bar {
        display: flex;
        gap: 0.5rem;
        margin-bottom: 1.5rem;
    }
    .seismogramm-preset-btn {
        flex: 1;
        padding: 0.75rem;
        font-family: var(--font-header, inherit);
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        background: #f8f8f8;
        border: 0.125rem solid #000;
        box-shadow: 0.125rem 0.125rem 0 #000;
        cursor: pointer;
        transition: all 0.1s ease;
    }
    .seismogramm-preset-btn:hover {
        background: #eee;
    }
    .seismogramm-preset-btn.is-active {
        background: var(--seismo-accent, #000);
        color: #fff;
        box-shadow: none;
        transform: translate(0.125rem, 0.125rem);
    }
    .seismogramm-sandbox-panel {
        border-top: 0.125rem dashed #000;
        margin-top: 1.5rem;
        padding-top: 1.5rem;
    }
    .seismogramm-output-status__step.is-done::before {
        content: '✓ ';
        color: var(--seismo-accent, #2563eb);
    }
    .seismogramm-summary-block {
        max-width: 40rem;
    }
    .seismogramm-summary-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 0.75rem;
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

        <!-- Preset Selection Bar -->
        <div class="seismogramm-preset-bar">
            <button type="button" class="seismogramm-preset-btn is-active" data-preset="Briefing">Briefing</button>
            <button type="button" class="seismogramm-preset-btn" data-preset="Blindspot">Blindspot</button>
            <button type="button" class="seismogramm-preset-btn" data-preset="Research">Research</button>
        </div>

        <div class="latest-entries-section">
            <form id="seismogramm-builder-form" class="admin-form-card">
                
                <!-- Briefing Persona Field -->
                <div class="admin-form-field" id="seismogramm-persona-field" style="margin-bottom: 1.5rem;">
                    <label for="seismogramm_persona" style="font-weight: 700; margin-bottom: 0.25rem; display:block;">Briefing Persona &amp; Goal</label>
                    <textarea id="seismogramm_persona" name="briefing_persona" rows="3" class="search-input" style="width:100%; max-width:40rem;" placeholder="Who are you, for whom are you writing and what's the briefing about?">Du bist ein leitender politischer und wirtschaftlicher Intelligence-Analyst in der Schweiz. Deine Aufgabe ist es, für C-Level-Entscheider (CEOs, Verwaltungsräte) die absolut wichtigsten und strategisch relevantesten Signale aus den vorliegenden Daten herauszufiltern und kompakt aufzubereiten.</textarea>
                </div>

                <!-- Research Dynamic Query Field -->
                <div class="admin-form-field" id="seismogramm-query-field" style="display: none; margin-bottom: 1.5rem;">
                    <label for="seismogramm_query" style="font-weight: 700; margin-bottom: 0.25rem; display:block;">Research Topic / Query</label>
                    <input type="text" id="seismogramm_query" name="research_query" class="search-input" style="width:100%; max-width:40rem;" placeholder="e.g. interest rates, UBS, Swiss energy policy...">
                </div>

                <!-- Basic parameters -->
                <div class="admin-form-field" style="margin-bottom: 1.5rem;">
                    <label style="margin-bottom:0.5rem; display:block;">Included Sources</label>
                    <div class="tag-filter-section">
                        <div class="tag-filter-list">
                            <?php foreach ($moduleOptions as $mod): ?>
                            <label class="tag-filter-pill tag-filter-pill-active tag-filter-pill--<?= e($mod['key']) ?>">
                                <input type="checkbox" name="modules[]" value="<?= e($mod['key']) ?>" checked class="seismogramm-module-cb">
                                <span><?= e($mod['label']) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="admin-form-field" style="margin-bottom: 1.5rem;">
                    <label for="seismogramm_lookback" style="margin-bottom: 0.25rem; display:block;">Lookback window</label>
                    <select id="seismogramm_lookback" name="lookback_days" class="search-input" style="width:auto;">
                        <?php for ($d = 1; $d <= 7; $d++): ?>
                        <option value="<?= $d ?>"<?= $defaultLookbackDays === $d ? ' selected' : '' ?>><?= $d === 1 ? '1 day' : $d . ' days' ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="admin-form-field" style="margin-bottom: 1.5rem;">
                    <label for="seismogramm_item_count" style="margin-bottom: 0.25rem; display:block;">Number of featured stories</label>
                    <select id="seismogramm_item_count" name="item_count" class="search-input" style="width:auto;">
                        <?php foreach ($itemCountOptions as $n): ?>
                        <option value="<?= (int)$n ?>"<?= $defaultItemCount === $n ? ' selected' : '' ?>><?= (int)$n ?> items</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Default vs. Custom Toggle -->
                <div class="admin-form-field" style="margin-bottom: 1.5rem; user-select:none;">
                    <label style="font-weight: 500; cursor: pointer;">
                        <input type="checkbox" id="seismogramm-custom-toggle" value="1"> Show prompt &amp; advanced settings
                    </label>
                </div>

                <!-- Custom Prompt and Advanced Sandboxing Panel -->
                <div id="seismogramm-custom-panel" class="seismogramm-sandbox-panel" style="display: none;">
                    
                    <div class="view-toggle view-toggle-bar" id="seismogramm-prompt-view-toggle" style="margin-bottom: 1.5rem; user-select: none;">
                        <span class="view-toggle-label" style="font-weight: 600; margin-right: 0.5rem;">View:</span>
                        <button type="button" class="btn btn-primary" id="seismogramm-view-prompt" data-view="prompt" style="font-family: var(--font-header, inherit); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; padding: 0.4rem 0.8rem; border: 0.125rem solid #000; box-shadow: 0.125rem 0.125rem 0 #000; cursor: pointer;">Prompt</button>
                        <button type="button" class="btn btn-secondary" id="seismogramm-view-helper" data-view="helper" style="font-family: var(--font-header, inherit); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; padding: 0.4rem 0.8rem; border: 0.125rem solid #000; box-shadow: 0.125rem 0.125rem 0 #000; cursor: pointer; margin-left: 0.5rem;">Helper</button>
                    </div>

                    <div class="admin-form-field" id="seismogramm-prompt-editor-panel" style="margin-bottom: 1.5rem;">
                        <label for="seismogramm_system_prompt" style="display:block; margin-bottom:0.5rem; font-weight:600;">System Prompt Instructions</label>
                        <textarea id="seismogramm_system_prompt" name="system_prompt" rows="18" class="search-input" style="width:100%; max-width:40rem; font-family: monospace;"></textarea>
                    </div>

                    <div class="admin-form-field" id="seismogramm-helper-panel" style="display: none; margin-bottom: 1.5rem;">
                        <label for="seismogramm_helper_intent" style="display:block; margin-bottom:0.5rem; font-weight:600;">What should this prompt focus on?</label>
                        <p class="admin-intro" style="margin:0 0 0.5rem; font-size: 0.875rem;">
                            Rough notes are enough. Gemini drafts a full prompt in the style of your default.
                        </p>
                        <textarea id="seismogramm_helper_intent" rows="5" class="search-input" style="width:100%; max-width:40rem; margin-bottom: 0.75rem;" placeholder="e.g. Swiss energy regulation and grid policy; prefer Lex and Leg; exclude consumer news."></textarea>
                        <div>
                            <button type="button" class="btn btn-secondary" id="seismogramm-helper-generate-btn" <?= $geminiConfigured ? '' : ' disabled' ?>>Generate prompt</button>
                        </div>
                        <div id="seismogramm-helper-msg" class="message" style="margin-top:0.75rem; max-width:40rem; display: none;" role="status" aria-live="polite"></div>
                    </div>

                    <div class="admin-form-field" style="margin-bottom: 1.5rem;">
                        <label style="display:block; margin-bottom:0.5rem; font-weight:600;">Relevance scoring</label>
                        <label style="display:block; margin-bottom:0.5rem; font-weight:normal;">
                            <input type="checkbox" name="include_important" value="1">
                            Include secondary news (scores below <?= (int)$alertThresholdPct ?>%)
                        </label>
                        <label style="display:block; margin-bottom:0.5rem; font-weight:normal;">
                            <input type="checkbox" name="disregard_magnitu" value="1">
                            Bypass relevance scoring (expert mode)
                        </label>
                    </div>

                    <div class="admin-form-field" style="margin-bottom: 1.5rem;">
                        <label style="display:block; margin-bottom:0.5rem; font-weight:600;">Context Pool</label>
                        <label style="display:block; margin-bottom:0.5rem; font-weight:normal;">
                            <input type="checkbox" name="use_recipe_snippets" value="1" checked>
                            Use Magnitu Snippets (200-word passages)
                        </label>
                        <label for="seismogramm_max_context" style="display:block; margin-top:0.5rem; font-size:0.875rem;">Maximum items sent to Gemini: <span id="seismogramm_max_context_val" style="font-weight:700;"><?= $maxContextEntries ?></span></label>
                        <input type="range" id="seismogramm_max_context" name="max_context_entries" min="20" max="500" value="<?= $maxContextEntries ?>" class="search-input" style="width:100%; max-width:20rem;">
                    </div>
                </div>

                <!-- Selection mode hidden input -->
                <input type="hidden" name="selection_mode" id="seismogramm_selection_mode" value="standard">

                <!-- Context Warning Banner -->
                <div id="seismogramm-context-warning" class="message message-warning" style="display: none; margin-bottom: 1rem;"></div>

                <div class="admin-form-actions">
                    <button type="submit" class="btn btn-success" id="seismogramm-generate-btn" <?= $geminiConfigured ? '' : ' disabled' ?>>Generate briefing</button>
                </div>
            </form>
        </div>

        <!-- Output section -->
        <div class="latest-entries-section module-section-spaced seismogramm-summary-block">
            <div class="seismogramm-summary-toolbar">
                <h2 class="section-title">Summary Briefing</h2>
                <button type="button" class="btn btn-secondary" id="seismogramm-copy-btn" style="display: none;">Copy to clipboard</button>
            </div>
            <div id="seismogramm-output-error" class="message message-error" style="display: none;"></div>
            <div id="seismogramm-output" class="admin-form-card" style="white-space:pre-wrap; min-height:4rem; max-width:100%;">
                <p class="admin-intro" id="seismogramm-placeholder">Generated text will appear here.</p>
            </div>
            <!-- Cost Estimate Display -->
            <div id="seismogramm-cost-estimate" class="seismogramm-cost-estimate" style="display: none; margin-top: 0.65rem; padding: 0.55rem 0.7rem; font-size: 0.8125rem; line-height: 1.45; background: #f8f8f8; border: 0.0625rem solid #000000; max-width: 100%;"></div>
        </div>

        <!-- Citation validation section (Always shown underneath) -->
        <div class="latest-entries-section module-section-spaced" id="seismogramm-sources-section" style="display: none;">
            <h2 class="section-title">Referenced Source Entries</h2>
            <p class="admin-intro">Citations parsed from generated summary (validation cards):</p>
            <div id="seismogramm-sources-cards"></div>
        </div>

        <div style="display:none;"><?= $csrfField ?></div>
    </div>

    <!-- Frontend JS logic -->
    <script>
    (function() {
        var presets = <?= json_encode($savedPrompts, JSON_UNESCAPED_UNICODE) ?>;
        var activePreset = 'Briefing';
        
        var presetBtns = document.querySelectorAll('.seismogramm-preset-btn');
        var queryField = document.getElementById('seismogramm-query-field');
        var personaField = document.getElementById('seismogramm-persona-field');
        var selectionModeInput = document.getElementById('seismogramm_selection_mode');
        var customToggle = document.getElementById('seismogramm-custom-toggle');
        var customPanel = document.getElementById('seismogramm-custom-panel');
        var systemPromptTa = document.getElementById('seismogramm_system_prompt');
        var form = document.getElementById('seismogramm-builder-form');
        var generateBtn = document.getElementById('seismogramm-generate-btn');
        var out = document.getElementById('seismogramm-output');
        var errEl = document.getElementById('seismogramm-output-error');
        var sourcesSection = document.getElementById('seismogramm-sources-section');
        var sourcesCards = document.getElementById('seismogramm-sources-cards');
        var copyBtn = document.getElementById('seismogramm-copy-btn');
        var placeholder = document.getElementById('seismogramm-placeholder');
         var costEstimateEl = document.getElementById('seismogramm-cost-estimate');
        var lastBriefingText = '';

        var viewPromptBtn = document.getElementById('seismogramm-view-prompt');
        var viewHelperBtn = document.getElementById('seismogramm-view-helper');
        var promptEditorPanel = document.getElementById('seismogramm-prompt-editor-panel');
        var helperPanel = document.getElementById('seismogramm-helper-panel');
        var helperIntentTa = document.getElementById('seismogramm_helper_intent');
        var helperGenerateBtn = document.getElementById('seismogramm-helper-generate-btn');
        var helperMsg = document.getElementById('seismogramm-helper-msg');

        var promptView = 'prompt';
        function setPromptView(view) {
            promptView = view;
            if (view === 'helper') {
                viewPromptBtn.classList.remove('btn-primary');
                viewPromptBtn.classList.add('btn-secondary');
                viewHelperBtn.classList.remove('btn-secondary');
                viewHelperBtn.classList.add('btn-primary');
                promptEditorPanel.style.display = 'none';
                helperPanel.style.display = 'block';
            } else {
                viewPromptBtn.classList.remove('btn-secondary');
                viewPromptBtn.classList.add('btn-primary');
                viewHelperBtn.classList.remove('btn-primary');
                viewHelperBtn.classList.add('btn-secondary');
                promptEditorPanel.style.display = 'block';
                helperPanel.style.display = 'none';
            }
        }
        
        if (viewPromptBtn) {
            viewPromptBtn.addEventListener('click', function() { setPromptView('prompt'); });
        }
        if (viewHelperBtn) {
            viewHelperBtn.addEventListener('click', function() { setPromptView('helper'); });
        }

        if (helperGenerateBtn) {
            helperGenerateBtn.addEventListener('click', function() {
                var intent = helperIntentTa.value.trim();
                if (!intent) {
                    helperMsg.textContent = 'Please enter what this prompt should focus on.';
                    helperMsg.className = 'message message-warning';
                    helperMsg.style.display = 'block';
                    return;
                }
                
                helperGenerateBtn.disabled = true;
                helperMsg.textContent = 'Formulating prompt... Please wait.';
                helperMsg.className = 'message message-info';
                helperMsg.style.display = 'block';
                
                var formData = new FormData();
                formData.append('intent', intent);
                var csrfInput = document.querySelector('input[name="_csrf"]');
                if (csrfInput) {
                    formData.append('_csrf', csrfInput.value);
                }
                
                fetch('<?= $promptHelperUrl ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    helperGenerateBtn.disabled = false;
                    if (!data.ok) {
                        throw new Error(data.error || 'Helper failed.');
                    }
                    systemPromptTa.value = data.prompt;
                    helperMsg.textContent = 'Prompt successfully generated! Switched back to editor view.';
                    helperMsg.className = 'message message-success';
                    setTimeout(function() {
                        helperMsg.style.display = 'none';
                        setPromptView('prompt');
                    }, 2000);
                })
                .catch(function(err) {
                    helperGenerateBtn.disabled = false;
                    helperMsg.textContent = err.message;
                    helperMsg.className = 'message message-error';
                });
            });
        }

        var maxContextSlider = document.getElementById('seismogramm_max_context');
        var maxContextVal = document.getElementById('seismogramm_max_context_val');
        if (maxContextSlider && maxContextVal) {
            maxContextSlider.addEventListener('input', function() {
                maxContextVal.textContent = maxContextSlider.value;
            });
        }

        // Preset click handler
        presetBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                presetBtns.forEach(function(b) { b.classList.remove('is-active'); });
                btn.classList.add('is-active');
                activePreset = btn.getAttribute('data-preset');

                // Dynamic query text field visibility & selection mode syncing
                if (activePreset === 'Research') {
                    queryField.style.display = 'block';
                    personaField.style.display = 'none';
                    selectionModeInput.value = 'standard';
                } else if (activePreset === 'Blindspot') {
                    queryField.style.display = 'none';
                    personaField.style.display = 'none';
                    selectionModeInput.value = 'relational';
                } else {
                    queryField.style.display = 'none';
                    personaField.style.display = 'block';
                    selectionModeInput.value = 'standard';
                }

                // Load associated system prompt
                var promptData = presets.find(function(p) { return p.name === activePreset; });
                if (promptData) {
                    systemPromptTa.value = promptData.content;
                }

                // Preset-driven source checkboxes
                var sourceCbs = document.querySelectorAll('.seismogramm-module-cb');
                sourceCbs.forEach(function(cb) {
                    if (activePreset === 'Blindspot') {
                        cb.checked = (cb.value === 'lex' || cb.value === 'leg' || cb.value === 'media');
                    } else {
                        cb.checked = true;
                    }
                    var pill = cb.closest('.tag-filter-pill');
                    if (pill) {
                        pill.classList.toggle('tag-filter-pill-active', cb.checked);
                    }
                });
            });
        });

        // Initialize first prompt content
        var defaultPrompt = presets.find(function(p) { return p.name === 'Briefing'; });
        if (defaultPrompt) {
            systemPromptTa.value = defaultPrompt.content;
        }

        // Custom sandbox toggle
        customToggle.addEventListener('change', function() {
            customPanel.style.display = customToggle.checked ? 'block' : 'none';
        });

        // Toggle module pills visually
        var sourceCbs = document.querySelectorAll('.seismogramm-module-cb');
        sourceCbs.forEach(function(cb) {
            cb.addEventListener('change', function() {
                var pill = cb.closest('.tag-filter-pill');
                if (pill) {
                    pill.classList.toggle('tag-filter-pill-active', cb.checked);
                }
            });
        });

        var warningEl = document.getElementById('seismogramm-context-warning');

        // Form Submission
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            var formData = new FormData(form);
            var csrfInput = document.querySelector('input[name="_csrf"]');
            if (csrfInput) {
                formData.set('_csrf', csrfInput.value);
            }

            errEl.style.display = 'none';
            if (warningEl) {
                warningEl.style.display = 'none';
                warningEl.textContent = '';
            }
            if (costEstimateEl) {
                costEstimateEl.style.display = 'none';
                costEstimateEl.innerHTML = '';
            }
            sourcesSection.style.display = 'none';
            out.innerHTML = '<p class="admin-intro">Generating briefing... Please wait.</p>';
            generateBtn.disabled = true;

            // Prepare context pass, then generate
            fetch('<?= $prepareUrl ?>', {
                method: 'POST',
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.ok) {
                    throw new Error(data.error || 'Failed to prepare context.');
                }

                if (data.meta && data.meta.context_warning && warningEl) {
                    warningEl.textContent = data.meta.context_warning;
                    warningEl.style.display = 'block';
                }
                
                return fetch('<?= $generateUrl ?>', {
                    method: 'POST',
                    body: formData
                });
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                generateBtn.disabled = false;
                if (!data.ok) {
                    throw new Error(data.error || 'Failed to generate briefing.');
                }

                lastBriefingText = data.text;
                out.style.whiteSpace = 'pre-wrap';
                out.textContent = data.text;
                copyBtn.style.display = 'inline-block';

                // Display cost estimate
                if (data.cost_estimate && costEstimateEl) {
                    var est = data.cost_estimate;
                    costEstimateEl.innerHTML = '';
                    
                    var amount = document.createElement('p');
                    amount.style.margin = '0 0 0.25rem';
                    amount.style.fontWeight = '600';
                    amount.style.fontSize = '0.9375rem';
                    amount.textContent = 'Estimated cost: ' + String(est.estimated_usd_display) + ' USD';
                    
                    var detail = document.createElement('p');
                    detail.style.margin = '0';
                    detail.style.opacity = '0.9';
                    detail.style.fontSize = '0.75rem';
                    
                    var formatInt = function(n) {
                        return parseInt(n, 10).toLocaleString();
                    };
                    
                    var pipelineLabel = est.pipeline || 'standard';
                    detail.textContent = pipelineLabel.toUpperCase() + ' pipeline · Gemini 3.5 Flash · ' +
                        formatInt(est.prompt_tokens) + ' input + ' + formatInt(est.output_tokens) + ' output tokens · ' +
                        String(est.api_calls || 0) + ' API call' + (est.api_calls === 1 ? '' : 's');
                    
                    costEstimateEl.appendChild(amount);
                    costEstimateEl.appendChild(detail);
                    costEstimateEl.style.display = 'block';
                }

                // Display source cards
                if (data.entries_html && data.entries_html.trim() !== '') {
                    sourcesCards.innerHTML = data.entries_html;
                    sourcesSection.style.display = 'block';
                }
            })
            .catch(function(err) {
                generateBtn.disabled = false;
                out.innerHTML = '';
                if (placeholder) {
                    out.appendChild(placeholder);
                }
                errEl.textContent = err.message;
                errEl.style.display = 'block';
            });
        });

        // Copy button
        copyBtn.addEventListener('click', function() {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(lastBriefingText).then(function() {
                    copyBtn.textContent = 'Copied!';
                    setTimeout(function() { copyBtn.textContent = 'Copy to clipboard'; }, 2000);
                });
            }
        });
    })();
    </script>
</body>
</html>
