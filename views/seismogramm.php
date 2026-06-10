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
    .seismogramm-mode-intro {
        margin-bottom: 1rem;
        padding: 0.85rem 1rem;
        border: 0.125rem solid #000;
        background: #fafafa;
        max-width: 40rem;
    }
    .seismogramm-mode-intro h3 {
        margin: 0 0 0.35rem;
        font-size: 1rem;
        font-weight: 700;
    }
    .seismogramm-mode-intro p {
        margin: 0 0 0.5rem;
        font-size: 0.875rem;
        line-height: 1.45;
    }
    .seismogramm-mode-intro p:last-child {
        margin-bottom: 0;
    }
    .seismogramm-about-panel {
        max-width: 44rem;
        font-size: 0.875rem;
        line-height: 1.5;
    }
    .seismogramm-about-panel h3 {
        margin: 1.25rem 0 0.5rem;
        font-size: 1rem;
    }
    .seismogramm-about-panel h3:first-child {
        margin-top: 0;
    }
    .seismogramm-about-panel pre {
        font-size: 0.75rem;
        overflow-x: auto;
        padding: 0.75rem;
        border: 0.125rem solid #000;
        background: #f4f4f4;
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

        <!-- View Toggle Bar -->
        <div class="view-toggle view-toggle-bar" id="seismogramm-prompt-view-toggle" style="margin-bottom: 1.5rem; user-select: none;">
            <span class="view-toggle-label" style="font-weight: 600; margin-right: 0.5rem;">View:</span>
            <button type="button" class="btn btn-primary" id="seismogramm-view-prompt" data-view="prompt" style="font-family: var(--font-header, inherit); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; padding: 0.4rem 0.8rem; border: 0.125rem solid #000; box-shadow: 0.125rem 0.125rem 0 #000; cursor: pointer;">Prompt</button>
            <button type="button" class="btn btn-secondary" id="seismogramm-view-helper" data-view="helper" style="font-family: var(--font-header, inherit); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; padding: 0.4rem 0.8rem; border: 0.125rem solid #000; box-shadow: 0.125rem 0.125rem 0 #000; cursor: pointer; margin-left: 0.5rem;">Helper</button>
            <button type="button" class="btn btn-secondary" id="seismogramm-view-about" data-view="about" style="font-family: var(--font-header, inherit); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; padding: 0.4rem 0.8rem; border: 0.125rem solid #000; box-shadow: 0.125rem 0.125rem 0 #000; cursor: pointer; margin-left: 0.5rem;">About</button>
        </div>

        <!-- Helper Card -->
        <div id="seismogramm-helper-card" class="latest-entries-section" style="display: none; margin-bottom: 1.5rem;">
            <div class="admin-form-card">
                <div class="admin-form-field" style="margin-bottom: 1.5rem;">
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
            </div>
        </div>

        <!-- About panel -->
        <div id="seismogramm-about-card" class="latest-entries-section seismogramm-about-panel" style="display: none; margin-bottom: 1.5rem;">
            <div class="admin-form-card">
                <h2 class="section-title" style="margin-top: 0;">About Seismogramm</h2>
                <p>Seismogramm runs a two-pass Gemini pipeline: <strong>Pass 1</strong> selects entry keys; <strong>Pass 2</strong> writes the briefing prose for those keys only.</p>

                <h3>Briefing</h3>
                <p><strong>What:</strong> Executive summary for C-level readers.</p>
                <p><strong>How:</strong> Magnitu highlights tier by default; single-pass selection on smaller pools, tournament batches when the pool exceeds <?= (int)\Seismo\Service\Seismogramm\SeismogrammPresetProfile::TOURNAMENT_POOL_THRESHOLD ?> items. Persona/goal can override raw score when fit is clearly stronger.</p>
                <p><strong>Good for:</strong> Weekly desk briefings where relevance matters but strategic fit to your stated persona wins ties.</p>

                <h3>Research</h3>
                <p><strong>What:</strong> Forensic topic search — needle in a large haystack.</p>
                <p><strong>How:</strong> Bypasses Magnitu scoring, uses Magnitu snippets, scans up to <?= (int)\Seismo\Service\Seismogramm\SeismogrammPresetProfile::RESEARCH_DEFAULT_MAX_CONTEXT ?> items. Tournament selection (parallel batches + championship) when the pool exceeds the batch size (~35); smaller pools use a single selection pass.</p>
                <p><strong>Good for:</strong> “Everything on topic X in the last week” across feeds, mail, lex, and media.</p>

                <h3>Blindspot</h3>
                <p><strong>What:</strong> Regulatory / parliamentary signals not yet echoed in media.</p>
                <p><strong>How:</strong> Relational tournament on Lex+Leg primary sources; compares against a global title fingerprint (Media, Feeds, Scraper). Negative-space protocol rejects fuzzy media overlap. Persona/goal filters random regulatory noise.</p>
                <p><strong>Good for:</strong> Horizon scanning when you need primary-source signals the news cycle has not picked up.</p>

                <h3>Pipeline diagram</h3>
                <pre>mermaid
flowchart LR
    subgraph briefing [Briefing]
        B1[Pool ≤ 80] --> B2[Standard pass]
        B3[Pool &gt; 80] --> B4[Tournament + championship]
    end
    subgraph research [Research]
        R1[Large snippet pool] --> R2[Pool &gt; ~35 batches]
        R2 --> R3[Championship pass]
    end
    subgraph blindspot [Blindspot]
        BL1[Lex/Leg batches] --> BL2[Parallel prelims]
        BL3[Echo fingerprint] --> BL2
        BL2 --> BL5[Championship + persona gate]
    end
</pre>
                <p class="admin-intro" style="margin-top: 0.75rem;">Legacy <code>?action=researcher</code> remains available for manual Standard / Tournament / Relational overrides.</p>
            </div>
        </div>

        <div id="seismogramm-mode-intro" class="seismogramm-mode-intro" aria-live="polite">
            <h3 id="seismogramm-mode-intro-title">Briefing</h3>
            <p id="seismogramm-mode-intro-what"></p>
            <p id="seismogramm-mode-intro-how"></p>
            <p id="seismogramm-mode-intro-good"></p>
        </div>

        <div class="latest-entries-section" id="seismogramm-form-section">
            <form id="seismogramm-builder-form" class="admin-form-card">
                <input type="hidden" name="preset" id="seismogramm_preset" value="Briefing">
                <input type="hidden" name="custom_advanced" id="seismogramm_custom_advanced" value="0">
                <input type="hidden" name="limit" value="<?= (int)$defaultLimit ?>">
                
                <!-- Briefing Persona Field -->
                <div class="admin-form-field" id="seismogramm-persona-field" style="margin-bottom: 1.5rem;">
                    <label for="seismogramm_persona" style="font-weight: 700; margin-bottom: 0.25rem; display:block;">Persona &amp; Goal</label>
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
                    <div class="admin-form-field" style="margin-bottom: 1.5rem;">
                        <label for="seismogramm_system_prompt" style="display:block; margin-bottom:0.5rem; font-weight:600;">System Prompt Instructions</label>
                        <textarea id="seismogramm_system_prompt" name="system_prompt" rows="18" class="search-input" style="width:100%; max-width:40rem; font-family: monospace;"></textarea>
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
                        <fieldset style="border:0; padding:0; margin:0 0 0.75rem;">
                            <legend style="font-weight:600; margin-bottom:0.35rem;">Cap ordering (within each source module)</legend>
                            <label style="display:block; margin-bottom:0.35rem; font-weight:normal;">
                                <input type="radio" name="pool_priority" value="highest" id="seismogramm_pool_priority_highest" checked>
                                Prioritize highest Magnitu relevance
                            </label>
                            <label style="display:block; margin-bottom:0.35rem; font-weight:normal;">
                                <input type="radio" name="pool_priority" value="newest" id="seismogramm_pool_priority_newest">
                                Prioritize newest (published date)
                            </label>
                        </fieldset>
                        <label style="display:block; margin-bottom:0.5rem; font-weight:normal;">
                            <input type="checkbox" id="seismogramm_use_recipe_snippets" name="use_recipe_snippets" value="1">
                            Use Magnitu Snippets (200-word passages)
                        </label>
                        <label for="seismogramm_max_context" style="display:block; margin-top:0.5rem; font-size:0.875rem;">Maximum items sent to Gemini: <span id="seismogramm_max_context_val" style="font-weight:700;"><?= $maxContextEntries ?></span></label>
                        <input type="range" id="seismogramm_max_context" name="max_context_entries" min="20" max="500" value="<?= $maxContextEntries ?>" class="search-input" style="width:100%; max-width:20rem;">
                        <label style="display:block; margin-top:0.75rem; font-weight:normal;">
                            <input type="checkbox" id="seismogramm_use_context_cache" name="use_context_cache" value="1">
                            Experimental: context-cache global title index (large pools only; requires fingerprint &gt;50k chars)
                        </label>
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
            <div id="seismogramm-rate-limit-retry" class="message message-warning" style="display: none; margin-bottom: 1rem;" role="region" aria-live="polite">
                <p id="seismogramm-rate-limit-retry-msg" style="margin: 0 0 0.75rem;"></p>
                <button type="button" class="btn btn-secondary" id="seismogramm-rate-limit-retry-btn">Retry with smaller pool</button>
            </div>
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
        var rateLimitRetryEl = document.getElementById('seismogramm-rate-limit-retry');
        var rateLimitRetryMsg = document.getElementById('seismogramm-rate-limit-retry-msg');
        var rateLimitRetryBtn = document.getElementById('seismogramm-rate-limit-retry-btn');
        var sourcesSection = document.getElementById('seismogramm-sources-section');
        var sourcesCards = document.getElementById('seismogramm-sources-cards');
        var copyBtn = document.getElementById('seismogramm-copy-btn');
        var placeholder = document.getElementById('seismogramm-placeholder');
         var costEstimateEl = document.getElementById('seismogramm-cost-estimate');
        var lastBriefingText = '';

        var viewPromptBtn = document.getElementById('seismogramm-view-prompt');
        var viewHelperBtn = document.getElementById('seismogramm-view-helper');
        var viewAboutBtn = document.getElementById('seismogramm-view-about');
        var helperCard = document.getElementById('seismogramm-helper-card');
        var aboutCard = document.getElementById('seismogramm-about-card');
        var formSection = document.getElementById('seismogramm-form-section');
        var modeIntro = document.getElementById('seismogramm-mode-intro');
        var modeIntroTitle = document.getElementById('seismogramm-mode-intro-title');
        var modeIntroWhat = document.getElementById('seismogramm-mode-intro-what');
        var modeIntroHow = document.getElementById('seismogramm-mode-intro-how');
        var modeIntroGood = document.getElementById('seismogramm-mode-intro-good');
        var presetInput = document.getElementById('seismogramm_preset');
        var customAdvancedInput = document.getElementById('seismogramm_custom_advanced');
        var snippetsCb = document.getElementById('seismogramm_use_recipe_snippets');
        var poolPriorityHighest = document.getElementById('seismogramm_pool_priority_highest');
        var poolPriorityNewest = document.getElementById('seismogramm_pool_priority_newest');
        var helperIntentTa = document.getElementById('seismogramm_helper_intent');
        var helperGenerateBtn = document.getElementById('seismogramm-helper-generate-btn');
        var helperMsg = document.getElementById('seismogramm-helper-msg');

        var RESEARCH_MAX_CONTEXT = <?= (int)\Seismo\Service\Seismogramm\SeismogrammPresetProfile::RESEARCH_DEFAULT_MAX_CONTEXT ?>;
        var TOURNAMENT_THRESHOLD = <?= (int)\Seismo\Service\Seismogramm\SeismogrammPresetProfile::TOURNAMENT_POOL_THRESHOLD ?>;

        var modeCopy = {
            Briefing: {
                title: 'Briefing',
                what: 'What: A concise executive briefing on the most important developments for your desk.',
                how: 'How: Highlights-tier Magnitu scoring by default. Standard selection on smaller pools; switches to tournament batches above ' + TOURNAMENT_THRESHOLD + ' items. Your persona/goal can outrank a higher Magnitu score when fit is clearly better.',
                good: 'Good for: C-level weekly summaries where strategic fit to your stated goal matters as much as raw alert score.'
            },
            Research: {
                title: 'Research',
                what: 'What: Forensic topic search across a large text corpus.',
                how: 'How: Ignores Magnitu tiers, uses Magnitu snippets, scans up to ' + RESEARCH_MAX_CONTEXT + ' items with newest-first cap ordering. Tournament selection when the pool exceeds ~35 items; smaller pools use one selection pass.',
                good: 'Good for: Needle-in-haystack queries — “everything on UBS / energy / VAT in the last week”.'
            },
            Blindspot: {
                title: 'Blindspot',
                what: 'What: Regulatory and parliamentary signals not yet reflected in media.',
                how: 'How: Relational tournament on Lex+Leg with a global media/feeds/scraper title fingerprint. Entries that overlap media topics are rejected. Your persona/goal filters irrelevant regulatory noise.',
                good: 'Good for: Horizon scanning when primary sources move before the news cycle catches up.'
            }
        };

        function updateModeIntro(presetName) {
            var copy = modeCopy[presetName] || modeCopy.Briefing;
            modeIntroTitle.textContent = copy.title;
            modeIntroWhat.textContent = copy.what;
            modeIntroHow.textContent = copy.how;
            modeIntroGood.textContent = copy.good;
        }

        var promptView = 'prompt';
        function setViewButtonState(activeBtn) {
            [viewPromptBtn, viewHelperBtn, viewAboutBtn].forEach(function(btn) {
                if (!btn) return;
                if (btn === activeBtn) {
                    btn.classList.remove('btn-secondary');
                    btn.classList.add('btn-primary');
                } else {
                    btn.classList.remove('btn-primary');
                    btn.classList.add('btn-secondary');
                }
            });
        }

        function setPromptView(view) {
            promptView = view;
            if (view === 'helper') {
                setViewButtonState(viewHelperBtn);
                if (formSection) formSection.style.display = 'none';
                if (modeIntro) modeIntro.style.display = 'none';
                helperCard.style.display = 'block';
                if (aboutCard) aboutCard.style.display = 'none';
            } else if (view === 'about') {
                setViewButtonState(viewAboutBtn);
                if (formSection) formSection.style.display = 'none';
                if (modeIntro) modeIntro.style.display = 'none';
                helperCard.style.display = 'none';
                if (aboutCard) aboutCard.style.display = 'block';
            } else {
                setViewButtonState(viewPromptBtn);
                if (formSection) formSection.style.display = 'block';
                if (modeIntro) modeIntro.style.display = 'block';
                helperCard.style.display = 'none';
                if (aboutCard) aboutCard.style.display = 'none';
            }
        }
        
        if (viewPromptBtn) {
            viewPromptBtn.addEventListener('click', function() { setPromptView('prompt'); });
        }
        if (viewHelperBtn) {
            viewHelperBtn.addEventListener('click', function() { setPromptView('helper'); });
        }
        if (viewAboutBtn) {
            viewAboutBtn.addEventListener('click', function() { setPromptView('about'); });
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
                    customToggle.checked = true;
                    customPanel.style.display = 'block';
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

        function applyPreset(presetName) {
            activePreset = presetName;
            if (presetInput) presetInput.value = presetName;
            updateModeIntro(presetName);

            if (presetName === 'Research') {
                queryField.style.display = 'block';
                personaField.style.display = 'none';
                selectionModeInput.value = 'tournament';
                if (maxContextSlider) {
                    maxContextSlider.value = String(RESEARCH_MAX_CONTEXT);
                    if (maxContextVal) maxContextVal.textContent = String(RESEARCH_MAX_CONTEXT);
                }
            } else if (presetName === 'Blindspot') {
                queryField.style.display = 'none';
                personaField.style.display = 'block';
                selectionModeInput.value = 'relational';
            } else {
                queryField.style.display = 'none';
                personaField.style.display = 'block';
                selectionModeInput.value = 'standard';
            }

            var promptData = presets.find(function(p) { return p.name === presetName; });
            if (promptData) {
                systemPromptTa.value = promptData.content;
            }

            var sourceCbs = document.querySelectorAll('.seismogramm-module-cb');
            sourceCbs.forEach(function(cb) {
                if (presetName === 'Blindspot') {
                    cb.checked = ['lex', 'leg', 'media', 'feeds', 'scraper'].indexOf(cb.value) !== -1;
                } else {
                    cb.checked = true;
                }
                var pill = cb.closest('.tag-filter-pill');
                if (pill) {
                    pill.classList.toggle('tag-filter-pill-active', cb.checked);
                }
            });

            if (!customToggle.checked && snippetsCb) {
                snippetsCb.checked = (presetName === 'Research');
            }
            if (!customToggle.checked) {
                if (presetName === 'Research' && poolPriorityNewest) {
                    poolPriorityNewest.checked = true;
                } else if (poolPriorityHighest) {
                    poolPriorityHighest.checked = true;
                }
            }
        }

        // Preset click handler
        presetBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                presetBtns.forEach(function(b) { b.classList.remove('is-active'); });
                btn.classList.add('is-active');
                applyPreset(btn.getAttribute('data-preset'));
            });
        });

        applyPreset('Briefing');

        // Custom sandbox toggle
        customToggle.addEventListener('change', function() {
            customPanel.style.display = customToggle.checked ? 'block' : 'none';
            if (customAdvancedInput) {
                customAdvancedInput.value = customToggle.checked ? '1' : '0';
            }
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

        function parseJsonResponse(r) {
            return r.json().then(function(data) {
                return { httpOk: r.ok, status: r.status, data: data };
            });
        }

        function hideRateLimitRetry() {
            if (rateLimitRetryEl) {
                rateLimitRetryEl.style.display = 'none';
            }
            if (rateLimitRetryMsg) {
                rateLimitRetryMsg.textContent = '';
            }
        }

        function showRateLimitRetry(payload) {
            hideRateLimitRetry();
            if (!payload || !payload.rate_limit_retry_available || !rateLimitRetryEl || !rateLimitRetryBtn) {
                return;
            }
            if (rateLimitRetryMsg) {
                rateLimitRetryMsg.textContent = payload.error || 'Gemini rate limit exceeded.';
            }
            var cap = payload.rate_limit_retry_cap;
            rateLimitRetryBtn.textContent = cap
                ? 'Retry with smaller pool (cap ' + cap + ' items)'
                : 'Retry with smaller pool';
            rateLimitRetryEl.style.display = 'block';
        }

        function renderGenerateSuccess(data) {
            hideRateLimitRetry();
            lastBriefingText = data.text;
            out.style.whiteSpace = 'pre-wrap';
            out.textContent = data.text;
            copyBtn.style.display = 'inline-block';

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
                var cacheNote = (data.meta && data.meta.context_cache_used) ? ' · context cache' : '';
                var fpNote = (data.meta && data.meta.global_fingerprint) ? ' · global fingerprint' : '';
                detail.textContent = pipelineLabel.toUpperCase() + ' pipeline · Gemini 3.5 Flash · ' +
                    formatInt(est.prompt_tokens) + ' input + ' + formatInt(est.output_tokens) + ' output tokens · ' +
                    String(est.api_calls || 0) + ' API call' + (est.api_calls === 1 ? '' : 's') + cacheNote + fpNote;

                costEstimateEl.appendChild(amount);
                costEstimateEl.appendChild(detail);

                if (data.meta && data.meta.meta_summary_line) {
                    var metaLine = document.createElement('p');
                    metaLine.style.margin = '0.35rem 0 0';
                    metaLine.style.fontSize = '0.75rem';
                    metaLine.style.opacity = '0.9';
                    metaLine.textContent = String(data.meta.meta_summary_line);
                    costEstimateEl.appendChild(metaLine);
                }

                costEstimateEl.style.display = 'block';
            }

            if (data.entries_html && data.entries_html.trim() !== '') {
                sourcesCards.innerHTML = data.entries_html;
                sourcesSection.style.display = 'block';
            }
        }

        function runGenerate(withRateLimitRetry) {
            var formData = new FormData(form);
            if (presetInput) {
                formData.set('preset', presetInput.value);
            }
            if (customAdvancedInput) {
                formData.set('custom_advanced', customAdvancedInput.value);
            }
            formData.set('rate_limit_user_retry', withRateLimitRetry ? '1' : '0');
            var csrfInput = document.querySelector('input[name="_csrf"]');
            if (csrfInput) {
                formData.set('_csrf', csrfInput.value);
            }

            errEl.style.display = 'none';
            hideRateLimitRetry();
            if (warningEl) {
                warningEl.style.display = 'none';
                warningEl.textContent = '';
            }
            if (costEstimateEl) {
                costEstimateEl.style.display = 'none';
                costEstimateEl.innerHTML = '';
            }
            sourcesSection.style.display = 'none';
            out.innerHTML = '<p class="admin-intro">' + (withRateLimitRetry
                ? 'Retrying with a smaller entry pool...'
                : 'Generating briefing... Please wait.') + '</p>';
            generateBtn.disabled = true;
            if (rateLimitRetryBtn) {
                rateLimitRetryBtn.disabled = true;
            }

            fetch('<?= $prepareUrl ?>', {
                method: 'POST',
                body: formData
            })
            .then(parseJsonResponse)
            .then(function(res) {
                if (!res.data.ok) {
                    var prepErr = new Error(res.data.error || 'Failed to prepare context.');
                    prepErr.seismogrammResponse = res.data;
                    throw prepErr;
                }

                if (res.data.meta && res.data.meta.context_warning && warningEl) {
                    warningEl.textContent = res.data.meta.context_warning;
                    warningEl.style.display = 'block';
                }

                return fetch('<?= $generateUrl ?>', {
                    method: 'POST',
                    body: formData
                }).then(parseJsonResponse);
            })
            .then(function(res) {
                generateBtn.disabled = false;
                if (rateLimitRetryBtn) {
                    rateLimitRetryBtn.disabled = false;
                }
                if (!res.data.ok) {
                    var genErr = new Error(res.data.error || 'Failed to generate briefing.');
                    genErr.seismogrammResponse = res.data;
                    throw genErr;
                }
                renderGenerateSuccess(res.data);
            })
            .catch(function(err) {
                generateBtn.disabled = false;
                if (rateLimitRetryBtn) {
                    rateLimitRetryBtn.disabled = false;
                }
                out.innerHTML = '';
                if (placeholder) {
                    out.appendChild(placeholder);
                }
                errEl.textContent = err.message;
                errEl.style.display = 'block';
                showRateLimitRetry(err.seismogrammResponse);
            });
        }

        if (rateLimitRetryBtn) {
            rateLimitRetryBtn.addEventListener('click', function() {
                runGenerate(true);
            });
        }

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            runGenerate(false);
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
