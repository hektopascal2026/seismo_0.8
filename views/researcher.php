<?php
/**
 * AI Researcher — filter entries and generate a Gemini summary.
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
 * @var int $maxContextEntries Saved cap for Gemini XML pool
 * @var int $maxContextDefault Default cap when unset
 * @var int $maxContextMin Minimum allowed cap
 * @var int $maxContextMax Maximum allowed cap
 */

declare(strict_types=1);

$accent = seismoBrandAccent();

$headerTitle    = 'Researcher';
$headerSubtitle = '';
$activeNav      = 'researcher';

$prepareUrl         = $basePath . '/index.php?action=researcher_prepare';
$generateUrl        = $basePath . '/index.php?action=researcher_generate';
$savePromptUrl      = $basePath . '/index.php?action=researcher_save_prompt';
$promptHelperUrl    = $basePath . '/index.php?action=researcher_prompt_helper';
$saveLibraryUrl     = $basePath . '/index.php?action=save_researcher_prompt';
$deleteLibraryUrl   = $basePath . '/index.php?action=delete_researcher_prompt';

$defaultPromptStored = $defaultPromptStored ?? false;
$initialActivePromptTabId = $initialActivePromptTabId ?? null;
$saveDefaultPromptLabel = $defaultPromptStored ? 'Update prompt (default)' : 'Save prompt (default)';
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
    .researcher-output-status__lead {
        margin: 0 0 0.5rem;
        font-weight: 600;
    }
    .researcher-output-status__steps {
        list-style: none;
        margin: 0.5rem 0 0;
        padding: 0;
    }
    .researcher-output-status__step {
        padding: 0.3rem 0;
        color: var(--text-muted, #6b7280);
    }
    .researcher-output-status__step.is-active {
        color: inherit;
        font-weight: 600;
    }
    .researcher-output-status__step.is-done::before {
        content: '\2713  ';
        color: var(--seismo-accent, #2563eb);
    }
    .researcher-output-error-inline {
        margin: 0;
        color: #b91c1c;
        font-weight: 600;
    }
    .researcher-summary-block {
        max-width: 40rem;
    }
    .researcher-summary-toolbar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        margin-bottom: 0.75rem;
    }
    .researcher-summary-toolbar .section-title {
        margin: 0;
    }
    #researcher-copy-btn {
        display: none;
    }
    #researcher-copy-btn.researcher-copy-btn--visible {
        display: inline-block;
    }
    .researcher-output-meta {
        margin-top: 1rem;
        padding-top: 0.75rem;
        border-top: 0.0625rem solid #000000;
        max-width: 100%;
    }
    .researcher-output-meta__title {
        margin: 0 0 0.35rem;
        font-size: 0.8125rem;
        font-weight: 600;
    }
    .researcher-output-meta__pre {
        margin: 0;
        padding: 0.5rem 0.65rem;
        font-size: 0.75rem;
        line-height: 1.35;
        background: #f8f8f8;
        border: 0.0625rem solid #000000;
        overflow-x: auto;
        white-space: pre;
        max-height: 24rem;
        overflow-y: auto;
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
                before generating a researcher.
            </div>
        <?php endif; ?>

        <div class="view-toggle view-toggle-bar" id="researcher-prompt-view-toggle">
            <span class="view-toggle-label">View:</span>
            <button type="button" class="btn btn-primary" id="researcher-view-prompt" data-view="prompt">Prompt</button>
            <button type="button" class="btn btn-secondary" id="researcher-view-helper" data-view="helper">Helper</button>
        </div>

        <div class="latest-entries-section">
            <form id="researcher-builder-form" class="admin-form-card">
                <!-- Step 1: Data Sources & Time Window -->
                <fieldset style="border: none; padding: 0; margin: 0 0 2rem 0;">
                    <legend style="font-weight: 700; font-size: 1rem; border-bottom: 0.125rem dashed #000000; width: 100%; padding-bottom: 0.25rem; margin-bottom: 1.25rem; color: #000000; text-transform: uppercase; letter-spacing: 0.02em;">Step 1: Data Sources & Time Window</legend>

                    <div class="admin-form-field" style="margin-bottom: 1.5rem;">
                        <label style="margin-bottom:0.5rem; display:block;">Included Sources</label>
                        <div class="filter-page-actions" style="margin-bottom:0.75rem;">
                            <button type="button" class="btn btn-primary" id="researcher-modules-all">All sources</button>
                            <button type="button" class="btn btn-secondary" id="researcher-modules-none">None</button>
                        </div>
                        <div class="tag-filter-section tag-filter-section--spaced-bottom">
                            <div class="tag-filter-list">
                                <?php foreach ($moduleOptions as $mod): ?>
                                <?php if ($mod['key'] === 'lex'): ?>
                                <label class="tag-filter-pill tag-filter-pill-active" id="lex-pill" style="user-select: none;">
                                    <input type="hidden" name="modules[]" value="lex" id="lex-input">
                                    <span id="lex-label">Lex</span>
                                </label>
                                <?php else: ?>
                                <?php
                                    $isMem = $mod['key'] === 'mem';
                                    $pillClass = $isMem ? 'tag-filter-pill' : 'tag-filter-pill tag-filter-pill-active';
                                    $checkedAttr = $isMem ? '' : ' checked';
                                ?>
                                <label class="<?= $pillClass ?>">
                                    <input type="checkbox" name="modules[]" value="<?= e($mod['key']) ?>"<?= $checkedAttr ?>
                                           class="researcher-module-cb">
                                    <span><?= e($mod['label']) ?></span>
                                </label>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="admin-form-field" style="margin-bottom: 1.5rem;">
                        <label for="researcher_lookback" style="margin-bottom: 0.25rem; display:block;">Lookback window</label>
                        <select id="researcher_lookback" name="lookback_days" class="search-input" style="width:auto; margin-bottom: 0.25rem;">
                            <?php for ($d = 1; $d <= 7; $d++): ?>
                            <option value="<?= $d ?>"<?= $defaultLookbackDays === $d ? ' selected' : '' ?>>
                                <?= $d === 1 ? '1 day' : $d . ' days' ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                        <p class="admin-intro" style="margin:0; font-size:0.8125rem; opacity:0.85;">How far back should we search for news and updates?</p>
                    </div>

                    <input type="hidden" name="limit" value="<?= (int)$defaultLimit ?>">
                </fieldset>

                <div style="margin-bottom: 2rem; border-top: 0.125rem dashed #000000; padding-top: 1.25rem;">
                    <button type="button" class="btn btn-secondary" id="toggle-advanced-settings-btn" style="width: 100%; text-align: center; font-weight: bold; letter-spacing: 0.05em; text-transform: uppercase;">
                        Show Advanced Settings (Steps 2–4) ▾
                    </button>
                </div>

                <div id="advanced-settings-wrapper" style="display: none;">
                    <!-- Step 2: Relevance & Filtering -->
                    <fieldset style="border: none; padding: 0; margin: 0 0 2rem 0;">
                        <legend style="font-weight: 700; font-size: 1rem; border-bottom: 0.125rem dashed #000000; width: 100%; padding-bottom: 0.25rem; margin-bottom: 1.25rem; color: #000000; text-transform: uppercase; letter-spacing: 0.02em;">Step 2: Relevance & Filtering</legend>

                    <div class="admin-form-field">
                        <label style="margin-bottom: 0.5rem; display:block;">Relevance scoring</label>
                        <p class="admin-intro" id="researcher-relevance-intro" style="margin:0 0 0.5rem; font-size:0.875rem;">
                            High-priority news (Highlights tier ≥ <?= (int)$alertThresholdPct ?>%) is always included.
                        </p>
                        
                        <label id="researcher-include-important-label" style="display:block; margin-bottom:0.75rem; font-weight:normal; user-select:none;">
                            <input type="checkbox" id="researcher_include_important" name="include_important" value="1">
                            Include secondary news (scores below <?= (int)$alertThresholdPct ?>%)
                        </label>
                        <p class="admin-intro" style="margin:-0.5rem 0 1rem 1.5rem; font-size:0.8125rem; opacity:0.8;">If checked, we also consider news with slightly lower priority scores. If unchecked, we strictly focus only on highest-priority alerts.</p>

                        <label style="display:block; margin-bottom:0.5rem; font-weight:normal; user-select:none;">
                            <input type="checkbox" id="researcher_disregard_magnitu" name="disregard_magnitu" value="1">
                            Bypass relevance scoring (expert mode)
                        </label>
                        <p class="admin-intro" style="margin:-0.25rem 0 0 1.5rem; font-size:0.8125rem; opacity:0.8;" id="researcher-disregard-magnitu-hint" hidden>
                            Bypasses our smart classification filters entirely. The AI will receive items purely in chronological order, regardless of their priority.
                        </p>
                    </div>
                </fieldset>

                <!-- Step 3: Pool -->
                <fieldset style="border: none; padding: 0; margin: 0 0 2rem 0;">
                    <legend style="font-weight: 700; font-size: 1rem; border-bottom: 0.125rem dashed #000000; width: 100%; padding-bottom: 0.25rem; margin-bottom: 1.25rem; color: #000000; text-transform: uppercase; letter-spacing: 0.02em;">Step 3: Pool</legend>

                    <div class="admin-form-field" style="margin-bottom: 1.5rem;">
                        <label style="display:block; margin-bottom:0.5rem; user-select:none; font-weight:600;">
                            <input type="checkbox" id="researcher_use_recipe_snippets" name="use_recipe_snippets" value="1">
                            Use Magnitu Snippets (Highly Recommended)
                        </label>
                        <p class="admin-intro" style="margin:0 0 1.25rem 1.5rem; font-size:0.8125rem; opacity:0.85; line-height: 1.4;">
                            Highly recommended. Instead of sending full items, this extracts exactly 200 words surrounding the high-impact keywords that triggered the classification. If you choose this, the AI can scan vast quantities of news without running out of memory. If you uncheck it, full items are sent, but you may exceed the memory cap.
                        </p>

                        <div class="admin-form-field" style="margin-bottom: 0;">
                            <label for="researcher_max_context_entries" style="margin-bottom: 0.25rem; display:block;">Maximum items sent to AI: <span id="researcher_max_val" style="font-weight:bold;"><?= (int)$maxContextEntries ?></span></label>
                            <div style="display:flex; align-items:center; gap:1rem; margin-bottom: 0.25rem;">
                                <input type="range" id="researcher_max_context_entries" name="max_context_entries"
                                       min="<?= (int)$maxContextMin ?>" max="500"
                                       value="<?= (int)$maxContextEntries ?>"
                                       class="search-input" style="flex-grow:1; max-width:20rem; padding: 0.25rem 0;">
                            </div>
                            <p class="admin-intro" style="margin:0; font-size:0.8125rem; opacity:0.85;" id="max-context-entries-help">
                                Caps the number of qualified items sent to the AI. If you enabled Magnitu Snippets above, you can safely drag this slider up to 500 to scan massive datasets. Without snippets, keep this under 100 to prevent memory failures.
                            </p>
                        </div>
                    </div>
                </fieldset>

                <!-- Step 4: AI Report Generation -->
                <fieldset style="border: none; padding: 0; margin: 0 0 2rem 0;">
                    <legend style="font-weight: 700; font-size: 1rem; border-bottom: 0.125rem dashed #000000; width: 100%; padding-bottom: 0.25rem; margin-bottom: 1.25rem; color: #000000; text-transform: uppercase; letter-spacing: 0.02em;">Step 4: AI Report Generation</legend>

                    <div class="admin-form-field" style="margin-bottom: 1.5rem;">
                        <label for="researcher_item_count" style="margin-bottom: 0.25rem; display:block;">Number of featured stories in report</label>
                        <select id="researcher_item_count" name="item_count" class="search-input" style="width:auto; margin-bottom: 0.25rem;">
                            <?php foreach ($itemCountOptions as $n): ?>
                            <option value="<?= (int)$n ?>"<?= $defaultItemCount === $n ? ' selected' : '' ?>>
                                <?= (int)$n ?> items
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="admin-intro" style="margin:0; font-size:0.8125rem; opacity:0.85;">The exact number of top-priority stories the AI will write about in the final executive briefing.</p>
                    </div>

                    <div class="admin-form-field" id="researcher-prompt-field" style="margin-bottom: 1.5rem;">
                        <div id="researcher-prompt-panel">
                            <label for="researcher_system_prompt" style="display:block; margin-bottom:0.35rem; font-weight: 600;">System prompt instructions</label>
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
                        <span id="prompt-library-msg" class="message" style="margin:0.25rem 0 0;" hidden role="status" aria-live="polite"></span>
                        <textarea id="researcher_system_prompt" name="system_prompt" rows="22" class="search-input"
                                  style="width:100%; max-width:40rem;"><?= e($systemPrompt) ?></textarea>
                    </div>

                    <div id="researcher-helper-panel" hidden>
                        <label for="researcher_helper_intent">What should this researcher focus on?</label>
                        <p class="admin-intro" style="margin:0.25rem 0 0.5rem;">
                            Rough notes are enough. Gemini drafts a full prompt in the style of your desk default.
                        </p>
                        <textarea id="researcher_helper_intent" rows="5" class="search-input"
                                  style="width:100%; max-width:40rem;"
                                  placeholder="e.g. Swiss energy regulation and grid policy; prefer Lex and Leg; exclude consumer news."></textarea>
                        <div style="margin:0.5rem 0;">
                            <button type="button" class="btn btn-secondary" id="researcher-helper-generate-btn"
                                    <?= $geminiConfigured ? '' : ' disabled' ?>>Generate prompt</button>
                        </div>
                        <span id="researcher-helper-msg" class="message" style="margin:0.25rem 0 0;" hidden role="status" aria-live="polite"></span>
                        <label for="researcher_helper_result">Generated prompt</label>
                        <p class="admin-intro" style="margin:0.25rem 0 0.5rem;">
                            Review and edit, then save to the library or as the instance default. Generate researcher uses the Prompt view only.
                        </p>
                        <textarea id="researcher_helper_result" rows="22" class="search-input"
                                  style="width:100%; max-width:40rem;"></textarea>
                    </div>
                </fieldset>
                </div>

                <div class="admin-form-actions" style="display:flex; flex-wrap:wrap; gap:0.5rem; align-items:center;">
                    <button type="submit" class="btn btn-success" id="researcher-generate-btn"
                            <?= $geminiConfigured ? '' : ' disabled' ?>>Generate researcher</button>
                    <button type="button" class="btn btn-secondary" id="researcher-save-prompt-btn"
                            title="<?= e($saveDefaultPromptTitle) ?>"><?= e($saveDefaultPromptLabel) ?></button>
                    <button type="button" class="btn btn-secondary" id="save-prompt-library-btn"
                            title="Add the current textarea as a named prompt in the library">Save to library</button>
                    <span id="researcher-prompt-save-msg" class="message" style="margin:0; flex-basis:100%;" hidden role="status" aria-live="polite"></span>
                </div>
            </form>
        </div>

        <div class="latest-entries-section module-section-spaced researcher-summary-block">
            <div class="researcher-summary-toolbar">
                <h2 class="section-title">Summary</h2>
                <button type="button" class="btn btn-secondary" id="researcher-copy-btn"
                        hidden aria-hidden="true" tabindex="-1">Copy to clipboard</button>
            </div>
            <div id="researcher-output-error" class="message message-error" hidden></div>
            <div id="researcher-output-warning" class="message message-warning" hidden></div>
            <div id="researcher-output" class="admin-form-card" style="white-space:pre-wrap; min-height:4rem; max-width:100%;">
                <p class="admin-intro" id="researcher-output-placeholder">Generated text will appear here.</p>
            </div>
        </div>

        <div class="latest-entries-section module-section-spaced" id="researcher-sources-section" hidden>
            <h2 class="section-title">Referenced source entries</h2>
            <p class="admin-intro" id="researcher-sources-intro">
                Entry cards cited for the top developments (attribution).
            </p>
            <div id="researcher-sources-cards"></div>
        </div>

        <div class="label-hidden-csrf" aria-hidden="true"><?= $csrfField ?></div>
    </div>

    <script>
    (function() {
        var form = document.getElementById('researcher-builder-form');
        var btn = document.getElementById('researcher-generate-btn');
        var out = document.getElementById('researcher-output');
        var placeholder = document.getElementById('researcher-output-placeholder');
        var errEl = document.getElementById('researcher-output-error');
        var warnEl = document.getElementById('researcher-output-warning');
        var sourcesSection = document.getElementById('researcher-sources-section');
        var sourcesCards = document.getElementById('researcher-sources-cards');
        var sourcesIntro = document.getElementById('researcher-sources-intro');
        var csrfWrap = document.querySelector('.label-hidden-csrf');
        var prepareUrl = <?= json_encode($prepareUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
        var generateUrl = <?= json_encode($generateUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
        var savePromptUrl = <?= json_encode($savePromptUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
        var promptHelperUrl = <?= json_encode($promptHelperUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
        var saveLibraryUrl = <?= json_encode($saveLibraryUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
        var deleteLibraryUrl = <?= json_encode($deleteLibraryUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
        var savedPrompts = <?= json_encode($savedPrompts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
        var savePromptBtn = document.getElementById('researcher-save-prompt-btn');
        var savePromptMsg = document.getElementById('researcher-prompt-save-msg');
        var saveLibraryBtn = document.getElementById('save-prompt-library-btn');
        var promptTabsEl = document.getElementById('prompt-tabs');
        var promptLibraryMsg = document.getElementById('prompt-library-msg');
        var promptTextarea = document.getElementById('researcher_system_prompt');
        var helperIntentEl = document.getElementById('researcher_helper_intent');
        var helperResultEl = document.getElementById('researcher_helper_result');
        var helperGenerateBtn = document.getElementById('researcher-helper-generate-btn');
        var helperMsgEl = document.getElementById('researcher-helper-msg');
        var promptPanelEl = document.getElementById('researcher-prompt-panel');
        var helperPanelEl = document.getElementById('researcher-helper-panel');
        var viewPromptBtn = document.getElementById('researcher-view-prompt');
        var viewHelperBtn = document.getElementById('researcher-view-helper');
        var researcherPromptView = 'prompt';
        var activePromptId = null;
        var initialActivePromptTabId = <?= json_encode($initialActivePromptTabId, JSON_UNESCAPED_UNICODE) ?>;
        var defaultPromptStored = <?= $defaultPromptStored ? 'true' : 'false' ?>;
        var instanceSavePromptLabel = <?= json_encode('Save prompt (default)', JSON_UNESCAPED_UNICODE) ?>;
        var instanceUpdatePromptLabel = <?= json_encode('Update prompt (default)', JSON_UNESCAPED_UNICODE) ?>;
        var PROMPT_TAB_DEFAULT_NAME = 'Default';
        var copyBtn = document.getElementById('researcher-copy-btn');
        var lastResearcherText = '';
        var COPY_BTN_LABEL = 'Copy to clipboard';
        var moduleCbs = document.querySelectorAll('.researcher-module-cb');
        var btnAll = document.getElementById('researcher-modules-all');
        var btnNone = document.getElementById('researcher-modules-none');
        var lexPill = document.getElementById('lex-pill');
        var lexInput = document.getElementById('lex-input');
        var lexLabel = document.getElementById('lex-label');
        var lexState = 1; // 0 = Deselected, 1 = Lex (all), 2 = CH Lex (Fedlex only)

        function syncModulePill(cb) {
            var pill = cb.closest('.tag-filter-pill');
            if (pill) {
                pill.classList.toggle('tag-filter-pill-active', cb.checked);
            }
        }

        function setModuleChecked(on) {
            moduleCbs.forEach(function(cb) {
                if (on && cb.value === 'mem') {
                    cb.checked = false;
                } else {
                    cb.checked = on;
                }
                syncModulePill(cb);
            });
            if (lexPill && lexInput && lexLabel) {
                if (on) {
                    lexState = 1;
                    lexInput.value = 'lex';
                    lexInput.disabled = false;
                    lexPill.className = 'tag-filter-pill tag-filter-pill-active';
                    lexLabel.textContent = 'Lex';
                } else {
                    lexState = 0;
                    lexInput.disabled = true;
                    lexPill.className = 'tag-filter-pill';
                    lexLabel.textContent = 'Lex';
                }
            }
        }

        function initResearcherModuleToggles() {
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

            if (lexPill && lexInput && lexLabel) {
                lexPill.addEventListener('click', function(ev) {
                    ev.preventDefault();
                    if (lexState === 1) {
                        // All -> CH Lex
                        lexState = 2;
                        lexInput.value = 'lex_ch';
                        lexInput.disabled = false;
                        lexPill.className = 'tag-filter-pill tag-filter-pill-active tag-filter-pill--ch-lex';
                        lexLabel.textContent = 'CH Lex';
                    } else if (lexState === 2) {
                        // CH Lex -> Off
                        lexState = 0;
                        lexInput.disabled = true;
                        lexPill.className = 'tag-filter-pill';
                        lexLabel.textContent = 'Lex';
                    } else {
                        // Off -> All
                        lexState = 1;
                        lexInput.value = 'lex';
                        lexInput.disabled = false;
                        lexPill.className = 'tag-filter-pill tag-filter-pill-active';
                        lexLabel.textContent = 'Lex';
                    }
                });
            }
        }

        function syncDisregardMagnituUi() {
            var disregardCb = document.getElementById('researcher_disregard_magnitu');
            var includeCb = document.getElementById('researcher_include_important');
            var includeLabel = document.getElementById('researcher-include-important-label');
            var hint = document.getElementById('researcher-disregard-magnitu-hint');
            var relevanceIntro = document.getElementById('researcher-relevance-intro');
            if (!disregardCb) {
                return;
            }
            var off = disregardCb.checked;
            if (relevanceIntro) {
                relevanceIntro.hidden = off;
            }
            if (includeCb) {
                includeCb.disabled = off;
                if (off) {
                    includeCb.checked = false;
                }
            }
            if (includeLabel) {
                includeLabel.style.opacity = off ? '0.5' : '';
            }
            if (hint) {
                hint.hidden = !off;
            }
        }

        function initDisregardMagnituToggle() {
            var disregardCb = document.getElementById('researcher_disregard_magnitu');
            if (!disregardCb) {
                return;
            }
            disregardCb.addEventListener('change', syncDisregardMagnituUi);
            syncDisregardMagnituUi();
        }

        initResearcherModuleToggles();
        initDisregardMagnituToggle();

        var statusTimerIds = [];
        var STATUS_STEPS = [
            { id: 'send', label: 'Sending request to the server' },
            { id: 'load', label: 'Loading and filtering entries from selected modules' },
            { id: 'context', label: 'Building markdown source context' },
            { id: 'select', label: 'Gemini pass 1: selecting top entries (thinking)' },
            { id: 'write', label: 'Gemini pass 2: writing executive researcher' },
            { id: 'cards', label: 'Preparing source entry cards for validation' }
        ];

        function statusStepsForMode() {
            return STATUS_STEPS;
        }

        function clearStatusTimers() {
            statusTimerIds.forEach(function(id) { clearTimeout(id); });
            statusTimerIds = [];
        }

        function scheduleStatus(delayMs, stepId) {
            statusTimerIds.push(setTimeout(function() { setActiveStatusStep(stepId); }, delayMs));
        }

        function setStatusStepLabel(stepId, label) {
            var list = document.getElementById('researcher-status-steps');
            if (!list) return;
            var li = list.querySelector('.researcher-output-status__step[data-step="' + stepId + '"]');
            if (li) li.textContent = label;
        }

        function setActiveStatusStep(stepId) {
            var list = document.getElementById('researcher-status-steps');
            if (!list) return;
            var found = false;
            list.querySelectorAll('.researcher-output-status__step').forEach(function(li) {
                var id = li.getAttribute('data-step');
                if (id === stepId) {
                    li.classList.add('is-active');
                    li.classList.remove('is-done');
                    found = true;
                    var leadText = document.getElementById('researcher-status-lead-text');
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
            setStatusStepLabel(
                'select',
                'Sent ' + n + ' ' + entryWord + ' to Gemini — pass 1: selecting top items'
            );
            setStatusStepLabel(
                'write',
                'Gemini pass 2: writing full executive researcher (often 30\u201390 seconds total)'
            );
            setActiveStatusStep('select');
        }

        function postResearcherAction(url, formData) {
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
                        var invalidMsg = 'Invalid response (HTTP ' + r.status + ').';
                        if (r.status === 502 || r.status === 504) {
                            invalidMsg = 'Server error (HTTP ' + r.status
                                + '): PHP may have run out of memory or hit a timeout during entry load or Gemini. '
                                + 'Try fewer modules, a shorter lookback, or lower max context entries, then retry.';
                        } else if (r.status === 0 || r.status >= 500) {
                            invalidMsg += ' The server may have timed out during entry load or Gemini generation.';
                        }
                        return { ok: false, error: invalidMsg, httpStatus: r.status };
                    }
                    if (!data.ok) {
                        if (!data.error) {
                            data.error = r.status >= 400
                                ? 'Request failed (HTTP ' + r.status + ').'
                                : 'Request failed (no error detail from server).';
                        }
                    }
                    data.httpStatus = r.status;
                    return data;
                });
            }).catch(function(err) {
                var detail = (err && err.message) ? String(err.message) : '';
                var msg = 'Network error — could not reach the server.';
                if (detail !== '') {
                    msg += ' (' + detail + ')';
                }
                msg += ' If this happens after ~30–60s, check PHP max_execution_time and nginx fastcgi_read_timeout (researcher needs up to ~3 minutes).';
                return { ok: false, error: msg };
            });
        }

        function showProcessingStatus() {
            clearStatusTimers();
            if (placeholder) placeholder.remove();
            out.style.whiteSpace = 'normal';
            out.innerHTML = '';
            out.setAttribute('aria-busy', 'true');

            var wrap = document.createElement('div');
            wrap.id = 'researcher-output-status';
            wrap.className = 'researcher-output-status';
            wrap.setAttribute('aria-live', 'polite');

            var lead = document.createElement('p');
            lead.id = 'researcher-status-lead';
            lead.className = 'researcher-output-status__lead';

            var leadText = document.createElement('span');
            leadText.id = 'researcher-status-lead-text';
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
            list.id = 'researcher-status-steps';
            list.className = 'researcher-output-status__steps';
            statusStepsForMode().forEach(function(step, idx) {
                var li = document.createElement('li');
                li.className = 'researcher-output-status__step' + (idx === 0 ? ' is-active' : '');
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
            scheduleStatus(5000, 'select');
            scheduleStatus(14000, 'write');
        }

        function hideProcessingStatus() {
            clearStatusTimers();
            out.removeAttribute('aria-busy');
            var status = document.getElementById('researcher-output-status');
            if (status) status.remove();
        }

        function restoreOutputPlaceholder() {
            hideCopyBtn();
            hideProcessingStatus();
            if (!out.querySelector('#researcher-output-placeholder') && !out.textContent.trim()) {
                out.style.whiteSpace = 'pre-wrap';
                var p = document.createElement('p');
                p.className = 'admin-intro';
                p.id = 'researcher-output-placeholder';
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
            lastResearcherText = '';
            if (copyBtn) {
                copyBtn.hidden = true;
                copyBtn.classList.remove('researcher-copy-btn--visible');
                copyBtn.setAttribute('aria-hidden', 'true');
                copyBtn.tabIndex = -1;
                copyBtn.textContent = COPY_BTN_LABEL;
            }
        }

        function formatContextCapSummary(meta) {
            if (!meta || meta.entries_sent_to_gemini === undefined) {
                return '';
            }
            var sent = meta.entries_sent_to_gemini;
            var parts = [sent + ' sent to Gemini'];
            if (meta.max_context_entries !== undefined) {
                parts.push('cap ' + meta.max_context_entries);
            }
            if (meta.entries_omitted_by_cap > 0) {
                parts.push(meta.entries_omitted_by_cap + ' omitted by cap');
            }
            if (meta.entries_eligible_before_cap !== undefined) {
                parts.push(meta.entries_eligible_before_cap + ' eligible before cap');
            }
            if (meta.item_count !== undefined) {
                parts.push(meta.item_count + ' developments requested');
            }
            if (meta.cited_entry_count !== undefined) {
                parts.push(meta.cited_entry_count + ' cited in researcher');
            }
            return parts.join(' · ');
        }

        function appendResearcherMetaDebug(container, meta) {
            if (!container || !meta || typeof meta !== 'object') {
                return;
            }
            var keys = Object.keys(meta);
            if (!keys.length) {
                return;
            }
            var wrap = document.createElement('div');
            wrap.className = 'researcher-output-meta';
            var title = document.createElement('p');
            title.className = 'researcher-output-meta__title';
            title.textContent = 'Generation meta (debug)';
            var summary = formatContextCapSummary(meta);
            if (summary !== '') {
                var lead = document.createElement('p');
                lead.className = 'admin-intro';
                lead.style.margin = '0 0 0.5rem';
                lead.textContent = summary;
                wrap.appendChild(lead);
            }
            var pre = document.createElement('pre');
            pre.className = 'researcher-output-meta__pre';
            try {
                pre.textContent = JSON.stringify(meta, null, 2);
            } catch (e) {
                pre.textContent = String(meta);
            }
            wrap.appendChild(title);
            wrap.appendChild(pre);
            container.appendChild(wrap);
        }

        function showCopyBtn(text) {
            lastResearcherText = text || '';
            if (!copyBtn) return;
            var ready = lastResearcherText.trim() !== '';
            copyBtn.textContent = COPY_BTN_LABEL;
            if (ready) {
                copyBtn.hidden = false;
                copyBtn.classList.add('researcher-copy-btn--visible');
                copyBtn.setAttribute('aria-hidden', 'false');
                copyBtn.tabIndex = 0;
            } else {
                hideCopyBtn();
            }
        }

        function copyResearcherToClipboard() {
            if (!copyBtn || lastResearcherText.trim() === '') return;
            function copied() {
                copyBtn.textContent = 'Copied';
                setTimeout(function() { copyBtn.textContent = COPY_BTN_LABEL; }, 2000);
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(lastResearcherText).then(copied).catch(fallbackCopy);
                return;
            }
            fallbackCopy();
            function fallbackCopy() {
                var ta = document.createElement('textarea');
                ta.value = lastResearcherText;
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
            copyBtn.addEventListener('click', copyResearcherToClipboard);
        }

        function showInlineMessage(el, text, isError) {
            if (!el) return;
            el.textContent = text;
            el.hidden = text === '';
            el.classList.remove('message-success', 'message-error');
            if (text !== '') {
                el.classList.add(isError ? 'message-error' : 'message-success');
                el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }

        function showPromptActionMsg(text, isError) {
            showInlineMessage(savePromptMsg, text, isError);
        }

        function showPromptLibraryMsg(text, isError) {
            showInlineMessage(promptLibraryMsg, text, isError);
        }

        function showHelperMsg(text, isError) {
            showInlineMessage(helperMsgEl, text, isError);
        }

        function activePromptContent() {
            if (researcherPromptView === 'helper' && helperResultEl) {
                return helperResultEl.value;
            }
            return promptTextarea ? promptTextarea.value : '';
        }

        function syncPromptEditorAfterSave(content) {
            if (promptTextarea) {
                promptTextarea.value = content;
            }
            var def = savedPrompts.find(function(p) { return p.name === PROMPT_TAB_DEFAULT_NAME; });
            if (def) {
                def.content = content;
            }
        }

        function setResearcherPromptView(view) {
            researcherPromptView = view === 'helper' ? 'helper' : 'prompt';
            if (promptPanelEl) {
                promptPanelEl.hidden = researcherPromptView !== 'prompt';
            }
            if (helperPanelEl) {
                helperPanelEl.hidden = researcherPromptView !== 'helper';
            }
            if (viewPromptBtn) {
                viewPromptBtn.classList.toggle('btn-primary', researcherPromptView === 'prompt');
                viewPromptBtn.classList.toggle('btn-secondary', researcherPromptView !== 'prompt');
            }
            if (viewHelperBtn) {
                viewHelperBtn.classList.toggle('btn-primary', researcherPromptView === 'helper');
                viewHelperBtn.classList.toggle('btn-secondary', researcherPromptView !== 'helper');
            }
            syncPromptSaveButtons();
        }

        function initResearcherPromptViewToggle() {
            if (viewPromptBtn) {
                viewPromptBtn.addEventListener('click', function() {
                    setResearcherPromptView('prompt');
                });
            }
            if (viewHelperBtn) {
                viewHelperBtn.addEventListener('click', function() {
                    setResearcherPromptView('helper');
                });
            }
            setResearcherPromptView('prompt');
        }

        initResearcherPromptViewToggle();

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
            if (editingLibrary) {
                savePromptBtn.disabled = false;
                savePromptBtn.hidden = false;
                savePromptBtn.textContent = 'Save to library';
                savePromptBtn.title = 'Add the current textarea as a new named prompt in the library';
                return;
            }
            // The default prompt cannot be overwritten!
            savePromptBtn.disabled = true;
            savePromptBtn.hidden = true;
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

                wrap.appendChild(tab);
                if (p.name !== PROMPT_TAB_DEFAULT_NAME) {
                    var del = document.createElement('button');
                    del.type = 'button';
                    del.className = 'prompt-tab-delete';
                    del.setAttribute('data-id', p.id);
                    del.setAttribute('aria-label', 'Delete prompt ' + p.name);
                    del.setAttribute('title', 'Delete');
                    del.textContent = '\u00d7';
                    wrap.appendChild(del);
                }
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
                        moduleCbs.forEach(function(cb) {
                            cb.checked = cb.value !== 'mem';
                            syncModulePill(cb);
                        });
                        if (lexPill && lexInput && lexLabel) {
                            lexState = 1;
                            lexInput.value = 'lex';
                            lexInput.disabled = false;
                            lexPill.className = 'tag-filter-pill tag-filter-pill-active';
                            lexLabel.textContent = 'Lex';
                        }
                    } else {
                        selectLibraryPrompt(row);
                        if (row.name === 'Swissmem') {
                            moduleCbs.forEach(function(cb) {
                                cb.checked = cb.value === 'mem';
                                syncModulePill(cb);
                            });
                            if (lexPill && lexInput && lexLabel) {
                                lexState = 0;
                                lexInput.disabled = true;
                                lexPill.className = 'tag-filter-pill';
                                lexLabel.textContent = 'Lex';
                            }
                        }
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

        function persistLibraryPrompt(forceCreate) {
            if (!saveLibraryUrl || (!promptTextarea && !helperResultEl)) return;
            var updating = !forceCreate && !!activePromptId;
            var name = '';
            if (!updating) {
                name = window.prompt('Prompt name');
                if (name === null) return;
                name = name.trim();
                if (name === '') return;
            }
            showPromptActionMsg('', false);
            var fd = new FormData();
            if (updating) {
                fd.set('id', activePromptId);
            } else {
                fd.set('name', name);
            }
            fd.set('content', activePromptContent());
            fd.set('_csrf', getCsrf());
            var triggerBtn = forceCreate ? savePromptBtn : saveLibraryBtn;
            if (triggerBtn) {
                triggerBtn.disabled = true;
                triggerBtn.textContent = 'Saving…';
            }
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
                    showPromptActionMsg('Invalid response (HTTP ' + res.status + ').', true);
                    return;
                }
                if (!data.ok || !data.prompts) {
                    showPromptActionMsg(data.error || 'Could not save prompt to library.', true);
                    return;
                }
                savedPrompts = data.prompts;
                renderPromptTabs(data.prompts);
                var savedContent = activePromptContent();
                syncPromptEditorAfterSave(savedContent);
                if (updating) {
                    var row = savedPrompts.find(function(p) { return p.id === activePromptId; });
                    if (row) {
                        row.content = savedContent;
                        selectLibraryPrompt(row);
                    }
                    showPromptActionMsg('Prompt updated.', false);
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
                    showPromptActionMsg('Prompt saved to library.', false);
                }
            })
            .catch(function() {
                showPromptActionMsg('Network error — could not reach the server.', true);
            })
            .finally(function() {
                if (triggerBtn) {
                    triggerBtn.disabled = false;
                }
                syncPromptSaveButtons();
            });
        }

        if (saveLibraryBtn && saveLibraryUrl && (promptTextarea || helperResultEl)) {
            saveLibraryBtn.addEventListener('click', function() {
                persistLibraryPrompt(false);
            });
        }

        if (helperGenerateBtn && promptHelperUrl && helperIntentEl && helperResultEl) {
            helperGenerateBtn.addEventListener('click', function() {
                showHelperMsg('', false);
                var intent = helperIntentEl.value.trim();
                if (intent.length < 10) {
                    showHelperMsg('Describe what you want in at least 10 characters.', true);
                    return;
                }
                var fd = new FormData();
                fd.set('intent', intent);
                fd.set('_csrf', getCsrf());
                helperGenerateBtn.disabled = true;
                var prevLabel = helperGenerateBtn.textContent;
                helperGenerateBtn.textContent = 'Generating…';
                if (savePromptBtn) savePromptBtn.disabled = true;
                if (saveLibraryBtn) saveLibraryBtn.disabled = true;
                fetch(promptHelperUrl, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                })
                .then(function(r) {
                    return r.text().then(function(t) {
                        var data;
                        try {
                            data = JSON.parse(t);
                        } catch (e) {
                            return { ok: false, error: 'Invalid response (HTTP ' + r.status + ').' };
                        }
                        if (!data.ok && !data.error) {
                            data.error = 'Could not generate prompt.';
                        }
                        return data;
                    });
                })
                .then(function(data) {
                    if (!data.ok) {
                        showHelperMsg(data.error || 'Could not generate prompt.', true);
                        return;
                    }
                    helperResultEl.value = data.prompt || '';
                    showHelperMsg('Prompt generated. Review, edit, then save if you want.', false);
                })
                .catch(function() {
                    showHelperMsg('Network error — could not reach the server.', true);
                })
                .finally(function() {
                    helperGenerateBtn.disabled = false;
                    helperGenerateBtn.textContent = prevLabel;
                    if (savePromptBtn) savePromptBtn.disabled = false;
                    if (saveLibraryBtn) saveLibraryBtn.disabled = false;
                    syncPromptSaveButtons();
                });
            });
        }

        if (savePromptBtn && savePromptUrl) {
            savePromptBtn.addEventListener('click', function() {
                if (activePromptId !== null) {
                    persistLibraryPrompt(true);
                    return;
                }
                if (!promptTextarea && !helperResultEl) return;
                showPromptActionMsg('', false);
                var fd = new FormData();
                fd.set('system_prompt', activePromptContent());
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
                        showPromptActionMsg('Invalid response (HTTP ' + res.status + ').', true);
                        return;
                    }
                    if (!data.ok) {
                        showPromptActionMsg(data.error || 'Could not save prompt.', true);
                        return;
                    }
                    var savedContent = activePromptContent();
                    syncPromptEditorAfterSave(savedContent);
                    var firstDefaultSave = !defaultPromptStored;
                    defaultPromptStored = true;
                    savePromptBtn.textContent = instanceUpdatePromptLabel;
                    syncInstanceSaveButton();
                    showPromptActionMsg(
                        firstDefaultSave
                            ? 'Prompt saved for this instance.'
                            : 'Prompt updated for this instance.',
                        false
                    );
                })
                .catch(function() {
                    showPromptActionMsg('Network error — could not reach the server.', true);
                })
                .finally(function() {
                    syncPromptSaveButtons();
                });
            });
        }

        // Sliders initialization and dynamic snippet cap expansion
        var limitSlider = document.getElementById('researcher_limit');
        var limitValSpan = document.getElementById('researcher_limit_val');
        var maxEntriesSlider = document.getElementById('researcher_max_context_entries');
        var maxEntriesValSpan = document.getElementById('researcher_max_val');
        var useSnippetsCb = document.getElementById('researcher_use_recipe_snippets');

        if (limitSlider && limitValSpan) {
            limitSlider.addEventListener('input', function() {
                limitValSpan.textContent = limitSlider.value;
            });
        }

        function updateMaxEntriesSlider() {
            if (!maxEntriesSlider || !maxEntriesValSpan || !useSnippetsCb) return;
            var isChecked = useSnippetsCb.checked;
            var absoluteMax = parseInt(<?= (int)$maxContextMax ?>, 10) || 500;
            var standardMax = 150;
            
            var prevMax = parseInt(maxEntriesSlider.max, 10);
            var currentVal = parseInt(maxEntriesSlider.value, 10);

            if (isChecked) {
                maxEntriesSlider.max = absoluteMax;
                maxEntriesSlider.title = "Magnitu snippets active: context capacity expanded up to " + absoluteMax + " entries!";
            } else {
                maxEntriesSlider.max = standardMax;
                maxEntriesSlider.title = "Standard context: max entries capped at " + standardMax + " for output reliability.";
                if (currentVal > standardMax) {
                    maxEntriesSlider.value = standardMax;
                    maxEntriesValSpan.textContent = standardMax;
                }
            }
            maxEntriesValSpan.textContent = maxEntriesSlider.value;
        }

        if (maxEntriesSlider && maxEntriesValSpan && useSnippetsCb) {
            maxEntriesSlider.addEventListener('input', function() {
                maxEntriesValSpan.textContent = maxEntriesSlider.value;
            });
            useSnippetsCb.addEventListener('change', function() {
                updateMaxEntriesSlider();
                if (useSnippetsCb.checked) {
                    maxEntriesSlider.style.transition = "outline 0.3s ease, transform 0.3s ease";
                    maxEntriesSlider.style.outline = "2px solid #28a745";
                    maxEntriesSlider.style.transform = "scale(1.02)";
                    setTimeout(function() {
                        maxEntriesSlider.style.outline = "none";
                        maxEntriesSlider.style.transform = "scale(1)";
                    }, 800);
                }
            });
            updateMaxEntriesSlider();
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
            if (lexPill && lexState !== 0) checked++;
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
                console.error('Researcher status UI failed:', statusErr);
                if (placeholder) placeholder.remove();
                out.style.whiteSpace = 'pre-wrap';
                out.innerHTML = '';
                var fallback = document.createElement('p');
                fallback.className = 'admin-intro';
                fallback.textContent = 'Generating researcher…';
                out.appendChild(fallback);
                out.setAttribute('aria-busy', 'true');
            }

            postResearcherAction(prepareUrl, fd)
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
                            + ' dropped by relevance filter (unscored or below 50% / Highlights bar). Remaining rows use highest relevance, then newest.';
                        warnEl.textContent = warnEl.textContent
                            ? warnEl.textContent + ' ' + dropNote
                            : dropNote;
                        warnEl.hidden = false;
                    }
                }
                return postResearcherAction(generateUrl, fd);
            })
            .then(function(data) {
                if (!data) return;
                if (!data.ok) {
                    hideCopyBtn();
                    var errMsg = data.error || 'Generation failed.';
                    if (data.meta && data.meta.summary_batch_retry_attempted) {
                        var batchNote = 'Pass 2 was retried in smaller parts';
                        if (data.meta.summary_batches) {
                            batchNote += ' (' + data.meta.summary_batches + ' Gemini calls)';
                        }
                        if (data.meta.batched_summary) {
                            batchNote += ' but output limits were still hit';
                        } else {
                            batchNote += ' without completing the researcher';
                        }
                        errMsg = errMsg + ' ' + batchNote + '.';
                    }
                    if (errEl) {
                        errEl.textContent = errMsg;
                        errEl.hidden = false;
                    }
                    hideProcessingStatus();
                    out.style.whiteSpace = 'pre-wrap';
                    out.innerHTML = '';
                    var errInBox = document.createElement('p');
                    errInBox.className = 'researcher-output-error-inline';
                    errInBox.textContent = errMsg;
                    out.appendChild(errInBox);
                    appendResearcherMetaDebug(out, data.meta);
                    return;
                }
                function renderResearcherSuccess(payload) {
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
                    var researcherText = (payload.text && String(payload.text).trim()) ? String(payload.text) : '';
                    if (researcherText === '') {
                        var emptyMsg = 'Gemini returned an empty researcher. Try again, reduce modules, lookback, or max context entries.';
                        if (errEl) {
                            errEl.textContent = emptyMsg;
                            errEl.hidden = false;
                        }
                        out.textContent = emptyMsg;
                        hideCopyBtn();
                        appendResearcherMetaDebug(out, payload.meta);
                        return;
                    }
                    out.textContent = researcherText;
                    showCopyBtn(researcherText);
                    appendResearcherMetaDebug(out, payload.meta);
                    if (payload.entries_html && sourcesCards) {
                        try {
                            sourcesCards.innerHTML = payload.entries_html;
                        } catch (htmlErr) {
                            console.error('Researcher source cards HTML failed:', htmlErr);
                            sourcesCards.textContent = 'Source cards could not be rendered.';
                        }
                        if (sourcesIntro && payload.meta) {
                            var introParts = [];
                            if (payload.meta.attribution_filtered && payload.meta.attributed_entry_count !== undefined) {
                                introParts.push(
                                    String(payload.meta.attributed_entry_count) +
                                    ' entries cited in the researcher (attribution order)'
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

                try {
                    if (data.meta && data.meta.entry_count !== undefined) {
                        applyStatusEntryCount(data.meta.entry_count);
                    }
                    setActiveStatusStep('cards');
                    renderResearcherSuccess(data);
                } catch (renderErr) {
                    console.error('Researcher render failed:', renderErr);
                    hideCopyBtn();
                    var renderMsg = (data.text && String(data.text).trim())
                        ? 'Researcher was generated but the page could not display it. See browser console.'
                        : 'Researcher response could not be displayed.';
                    if (errEl) {
                        errEl.textContent = renderMsg;
                        errEl.hidden = false;
                    }
                    hideProcessingStatus();
                    out.style.whiteSpace = 'pre-wrap';
                    if (data.text && String(data.text).trim()) {
                        out.textContent = String(data.text);
                        showCopyBtn(String(data.text));
                        appendResearcherMetaDebug(out, data.meta);
                    } else {
                        out.innerHTML = '';
                        var renderInBox = document.createElement('p');
                        renderInBox.className = 'researcher-output-error-inline';
                        renderInBox.textContent = renderMsg;
                        out.appendChild(renderInBox);
                        appendResearcherMetaDebug(out, data.meta);
                    }
                }
            })
            .catch(function(err) {
                hideCopyBtn();
                var msg = 'Request failed.';
                if (err && err.error) {
                    msg = err.error;
                } else if (err && err.message) {
                    msg = err.message;
                } else if (err && err.httpStatus) {
                    msg = 'Request failed (HTTP ' + err.httpStatus + ').';
                }
                console.error('Researcher request failed:', err);
                if (errEl) {
                    errEl.textContent = msg;
                    errEl.hidden = false;
                }
                hideProcessingStatus();
                out.style.whiteSpace = 'pre-wrap';
                out.innerHTML = '';
                if (msg) {
                    var errInBox = document.createElement('p');
                    errInBox.className = 'researcher-output-error-inline';
                    errInBox.textContent = msg;
                    out.appendChild(errInBox);
                    appendResearcherMetaDebug(out, err && err.meta ? err.meta : null);
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
            if (btn) btn.textContent = 'expand \u25BE';
        }
        function expandEntryCard(card, btn) {
            var preview = card.querySelector('.entry-preview');
            var full = card.querySelector('.entry-full-content');
            if (!preview || !full) return;
            preview.style.display = 'none';
            full.style.display = 'block';
            if (btn) btn.textContent = 'collapse \u25B4';
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
        // Advanced settings toggle logic
        var toggleAdvancedBtn = document.getElementById('toggle-advanced-settings-btn');
        var advancedWrapper = document.getElementById('advanced-settings-wrapper');
        if (toggleAdvancedBtn && advancedWrapper) {
            toggleAdvancedBtn.addEventListener('click', function(ev) {
                ev.preventDefault();
                var isHidden = advancedWrapper.style.display === 'none';
                if (isHidden) {
                    advancedWrapper.style.display = 'block';
                    toggleAdvancedBtn.textContent = 'Hide Advanced Settings (Steps 2–4) \u25B4';
                } else {
                    advancedWrapper.style.display = 'none';
                    toggleAdvancedBtn.textContent = 'Show Advanced Settings (Steps 2–4) \u25BE';
                }
            });
        }
    })();
    </script>
</body>
</html>
