<?php
/**
 * @var string $basePath
 */

declare(strict_types=1);

$accent = '#111111';
$headerTitle = 'Design Refresh Sandbox';
$headerSubtitle = 'Side-by-side comparison of old style vs proposed minimal brutalist refresh';
$activeNav = 'styleguide';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Design Refresh Sandbox — Seismo</title>
    <!-- We load the current stylesheet for the "Original" column -->
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css?v=<?= e(SEISMO_VERSION) ?>">
    
    <style>
        /* --- Core Layout for Side-by-Side --- */
        .sandbox-header {
            padding: 2rem 0;
            border-bottom: 3px double #000000;
            margin-bottom: 2.5rem;
        }
        .sandbox-header h1 {
            font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
            font-size: 2.5rem;
            font-weight: 900;
            letter-spacing: -0.04em;
            margin-bottom: 0.5rem;
        }
        .sandbox-header p {
            font-size: 1.1rem;
            color: #333;
            max-width: 50rem;
            line-height: 1.6;
        }
        .comparison-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2.5rem;
            align-items: start;
        }
        .column-original {
            padding: 1.75rem;
            border: 2px dashed #999;
            background: #fafafa;
            position: relative;
        }
        .column-original::before {
            content: "CURRENT v0.7.8 STYLE";
            position: absolute;
            top: -10px;
            left: 15px;
            background: #fff;
            padding: 0 8px;
            font-family: monospace;
            font-weight: bold;
            font-size: 0.75rem;
            border: 1px solid #999;
            color: #666;
        }
        .column-proposed {
            padding: 1.75rem;
            border: 3px solid #000;
            background: #ffffff;
            position: relative;
            box-shadow: 8px 8px 0 #000000;
        }
        .column-proposed::before {
            content: "PROPOSED BRUTALIST REFRESH";
            position: absolute;
            top: -11px;
            left: 15px;
            background: #ffea00;
            padding: 2px 8px;
            font-family: monospace;
            font-weight: 900;
            font-size: 0.75rem;
            border: 2px solid #000;
            color: #000;
            box-shadow: 2px 2px 0 #000;
        }

        /* --- PROPOSED STYLESHEET REBOOT --- */
        .column-proposed {
            --mono-font: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            --sans-font: "Trebuchet MS", sans-serif;
            --slab-font: ui-serif, Georgia, Cambria, "Times New Roman", Times, serif;
        }

        .column-proposed .sandbox-h2 {
            font-family: var(--sans-font);
            font-size: 1.35rem;
            font-weight: 900;
            letter-spacing: -0.02em;
            margin-bottom: 1.25rem;
            border-bottom: 3px solid #000;
            padding-bottom: 0.25rem;
            display: inline-block;
            text-transform: uppercase;
        }

        .comparison-section {
            margin-bottom: 2.5rem;
            border-bottom: 1px dotted #ccc;
            padding-bottom: 2rem;
        }
        .comparison-section:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        .section-meta {
            font-family: monospace;
            font-size: 0.75rem;
            color: #666;
            margin-bottom: 1rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: bold;
        }

        /* 1. Header & Branding Options */
        .column-original .orig-header-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            border-bottom: 2px solid #000;
            font-family: sans-serif;
        }
        .column-proposed .ref-header-brand {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1rem;
            border: 3px solid #000;
            background: #fff;
            box-shadow: 2px 2px 0 #000;
            margin-bottom: 1rem;
        }
        .column-proposed .ref-brand-title {
            font-family: var(--sans-font);
            font-weight: 900;
            font-size: 1.25rem;
            letter-spacing: -0.04em;
            text-transform: uppercase;
        }
        .column-proposed .ref-brand-version {
            font-family: var(--mono-font);
            font-size: 0.8125rem;
            color: #666;
            font-weight: bold;
            letter-spacing: normal;
        }

        /* 2. Tactical Cards & Font-Stack comparisons */
        .column-proposed .ref-card {
            border: 2px solid #000000;
            background: #ffffff;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 0 0 #000000; /* No shadow by default */
            transition: box-shadow 0.15s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.15s ease;
        }
        .column-proposed .ref-card:hover {
            border-color: #000000;
            box-shadow: 2px 2px 0px #000000; /* Subtle hover state */
        }
        .column-proposed .ref-card-header {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
            align-items: center;
        }
        .column-proposed .ref-tag {
            font-family: var(--mono-font);
            font-size: 0.6875rem;
            font-weight: bold;
            padding: 0.2rem 0.5rem;
            border: 2px solid #000;
            color: #000;
            background-color: #fff;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .column-proposed .ref-tag--feed-rss { background-color: #add8e6; }
        .column-proposed .ref-tag--feed-substack { background-color: #c5b4d1; }
        .column-proposed .ref-tag--lex { background-color: #f5f562; }
        .column-proposed .ref-tag--leg { background-color: #d4edda; }

        .column-proposed .ref-card-title {
            font-family: var(--mono-font);
            font-size: 0.95rem;
            font-weight: 700;
            line-height: 1.4;
            color: #000000;
            margin: 0.25rem 0 0.5rem 0;
            letter-spacing: -0.04em;
            word-spacing: -0.12em;
        }
        .column-proposed .ref-card-body {
            font-family: var(--sans-font);
            font-size: 0.85rem;
            line-height: 1.5;
            color: #222;
            margin-bottom: 0.75rem;
        }
        .column-proposed .ref-card-footer {
            border-top: 1px dotted #000000;
            padding-top: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-family: var(--mono-font);
            font-size: 0.75rem;
            color: #555555;
        }
        .column-proposed .ref-btn-action {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            font-family: var(--mono-font);
            font-size: 0.75rem;
            font-weight: bold;
            text-decoration: none;
            color: #000;
            padding: 0.25rem 0.5rem;
            border: 1px solid #000;
            background: #fff;
            transition: transform 0.05s ease;
        }
        .column-proposed .ref-btn-action:hover {
            transform: translate(-1px, -1px);
            box-shadow: 2px 2px 0 #000;
        }

        /* 3. Navigation Drawer Options */
        .column-proposed .ref-nav-drawer {
            display: flex;
            flex-wrap: wrap;
            border: none;
            margin-bottom: 1.5rem;
            background: transparent;
            gap: 0.625rem;
            padding: 0.5rem 0;
        }
        .column-proposed .ref-nav-link {
            padding: 0.5rem 1rem;
            background: #ffffff;
            color: #000000;
            font-family: var(--sans-font);
            font-size: 0.875rem;
            font-weight: 700;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #000;
            box-shadow: 2px 2px 0 #000;
            transition: all 0.1s ease;
        }
        .column-proposed .ref-nav-link:hover {
            background-color: #fff9e6;
            transform: translate(-1px, -1px);
            box-shadow: 3px 3px 0 #000;
        }
        .column-proposed .ref-nav-link.active {
            background-color: #ffea00;
            color: #000000;
            transform: translate(1px, 1px);
            box-shadow: 1px 1px 0 #000;
        }

        /* 4. Filter Pills & Checkboxes */
        .column-proposed .ref-filter-container {
            border: 2px solid #000;
            padding: 1rem;
            background: #fff;
            box-shadow: 4px 4px 0 #000;
        }
        .column-proposed .ref-filter-group {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }
        .column-proposed .ref-filter-pill {
            font-family: var(--mono-font);
            font-size: 0.75rem;
            font-weight: bold;
            padding: 0.375rem 0.75rem;
            border: 2px solid #000;
            background: #fff;
            color: #000;
            cursor: pointer;
            user-select: none;
            display: inline-flex;
            align-items: center;
            box-shadow: 2px 2px 0 #000;
            transition: transform 0.05s ease, box-shadow 0.05s ease;
        }
        .column-proposed .ref-filter-pill:hover {
            transform: translate(-1px, -1px);
            box-shadow: 3px 3px 0 #000;
        }
        .column-proposed .ref-filter-pill.active {
            background-color: #ffea00;
            box-shadow: 1px 1px 0 #000;
            transform: translate(1px, 1px);
        }
        .column-proposed .ref-filter-pill.active.feed { background-color: #add8e6; }
        .column-proposed .ref-filter-pill.active.lex { background-color: #f5f562; }
        .column-proposed .ref-filter-pill.active.leg { background-color: #d4edda; }
        .column-proposed .ref-filter-pill.deactivated {
            background-color: #fff;
            border-color: #000000;
            opacity: 0.4;
            box-shadow: none;
            transform: none;
        }

        /* 5. Inputs & Buttons */
        .column-proposed .ref-input-group {
            display: flex;
            gap: 0.5rem;
            width: 100%;
        }
        .column-proposed .ref-text-input {
            flex: 1;
            padding: 0.75rem 1rem;
            border: 2px solid #000;
            font-family: var(--mono-font);
            font-size: 0.875rem;
            background: #fff;
            box-shadow: 3px 3px 0 #000;
            outline: none;
        }
        .column-proposed .ref-text-input:focus {
            background: #fff9e6;
            box-shadow: 4px 4px 0 #000;
        }
        .column-proposed .ref-btn {
            padding: 0.75rem 1.25rem;
            border: 2px solid #000;
            font-family: var(--sans-font);
            font-weight: 900;
            font-size: 0.875rem;
            background: #ffea00;
            cursor: pointer;
            box-shadow: 3px 3px 0 #000;
            transition: transform 0.05s ease, box-shadow 0.05s ease;
        }
        .column-proposed .ref-btn:hover {
            transform: translate(-1px, -1px);
            box-shadow: 4px 4px 0 #000;
        }
        .column-proposed .ref-btn:active {
            transform: translate(2px, 2px);
            box-shadow: 1px 1px 0 #000;
        }

        /* Mobile Simulation Wrapper */
        .mobile-sim-toggle {
            display: flex;
            justify-content: center;
            gap: 0.75rem;
            margin-bottom: 2rem;
        }
        .mobile-sim-btn {
            font-family: monospace;
            padding: 0.75rem 1.5rem;
            border: 3px solid #000;
            background: #ffea00;
            font-weight: 900;
            cursor: pointer;
            box-shadow: 4px 4px 0 #000;
            font-size: 1rem;
        }
        .mobile-sim-btn:active {
            transform: translate(2px, 2px);
            box-shadow: 2px 2px 0 #000;
        }
        
        .simulating-mobile .comparison-grid {
            grid-template-columns: 1fr;
            max-width: 410px;
            margin: 0 auto;
        }
        .simulating-mobile .column-original {
            background: #eee;
        }
        .simulating-mobile .column-original .nav-drawer-sim {
            display: flex;
            flex-direction: column;
            border: 2px solid #000;
        }
        .simulating-mobile .column-original .nav-drawer-sim a {
            padding: 0.5rem;
            border-bottom: 1px solid #e0e0e0;
            text-decoration: none;
            color: #000;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        
        <div class="sandbox-header">
            <h1><?= e($headerTitle) ?></h1>
            <p>We are proposing an upgrade to Seismo’s print-inspired, heavy Neo-Brutalist roots. Below you can compare **every visual aspect** (brand frames, typography, feed cards, filters, and forms) side-by-side. Toggle the simulation viewport to test responsiveness.</p>
        </div>

        <div class="mobile-sim-toggle">
            <button class="mobile-sim-btn" onclick="document.body.classList.toggle('simulating-mobile')">⚡ TOGGLE SCREEN VIEWPORT (MOBILE / DESKTOP)</button>
        </div>

        <div class="comparison-grid">
            
            <!-- COLUMN 1: ORIGINAL STYLE -->
            <div class="column-original">
                <h2 style="font-size:1.125rem; font-weight:700; margin-bottom:1.5rem; border-bottom: 1px solid #000; padding-bottom:4px; text-transform:uppercase;">Original Style Elements</h2>
                
                <!-- Section 1: Branding -->
                <div class="comparison-section">
                    <div class="section-meta">1. BRANDING & HEADER</div>
                    <div class="orig-header-brand" style="display: flex; align-items: center; gap: 0.5rem;">
                        <img src="<?= e($basePath) ?>/assets/img/logo.svg" alt="" class="logo-icon logo-icon-large" width="30" height="30" style="height: 1.875rem; width: auto;" decoding="async">
                        <span style="font-weight:bold; font-size:1.125rem;">Seismo v0.7.8</span>
                        <span style="font-size:0.75rem; color:#666; margin-inline-start:auto;">Refresh</span>
                    </div>
                </div>

                <!-- Section 2: Navigation Drawer -->
                <div class="comparison-section">
                    <div class="section-meta">2. NAVIGATION DRAWER LINKS (WITH BLACK FILLER)</div>
                    <div class="nav-drawer open" style="display: flex;">
                        <a href="#" class="nav-link active" onclick="return false;">Timeline</a>
                        <a href="#" class="nav-link" onclick="return false;">Filter</a>
                        <a href="#" class="nav-link" onclick="return false;">Highlights</a>
                        <a href="#" class="nav-link" onclick="return false;">Researcher</a>
                        <a href="#" class="nav-link" onclick="return false;">Label</a>
                        <a href="#" class="nav-link" onclick="return false;">Feeds</a>
                        <a href="#" class="nav-link" onclick="return false;">Media</a>
                        <a href="#" class="nav-link" onclick="return false;">Scraper</a>
                        <a href="#" class="nav-link" onclick="return false;">Mail</a>
                        <a href="#" class="nav-link" onclick="return false;">Lex</a>
                        <a href="#" class="nav-link" onclick="return false;">Leg</a>
                        <a href="#" class="nav-link" onclick="return false;">Styleguide</a>
                        <a href="#" class="nav-link" onclick="return false;">Logbook</a>
                        <a href="#" class="nav-link" onclick="return false;">Settings</a>
                        <a href="#" class="nav-link" onclick="return false;">About</a>
                    </div>
                </div>

                <!-- Section 3: Cards -->
                <div class="comparison-section">
                    <div class="section-meta">3. METADATA CARDS & FONTS</div>
                    
                    <!-- Feed card -->
                    <div class="entry-card" style="margin-bottom:1.25rem;">
                        <div class="entry-header" style="margin-bottom:0.5rem;">
                            <span class="entry-tag entry-tag--feed-rss">RSS FEED</span>
                        </div>
                        <div class="entry-content">
                            <p style="font-weight:700; font-size:1rem; line-height:1.4; margin-bottom:0.25rem;">ECOWAS News as at 10 Nov 2025</p>
                            <p style="font-size:0.875rem; color:#000; line-height:1.5; margin-bottom:0.5rem;">10 Nov 2025 Germany commits 49 million euros (82 billion naira) to support ECOWAS in strengthening peace...</p>
                            <div style="font-size:0.75rem; color:#666; display:flex; justify-content:space-between; align-items:center;">
                                <span>expand ▼</span>
                                <span>29.05.2026 05:42</span>
                            </div>
                        </div>
                    </div>

                    <!-- Lex card -->
                    <div class="entry-card">
                        <div class="entry-header" style="margin-bottom:0.5rem;">
                            <span class="entry-tag entry-tag--lex-source" style="background:#f5f562;">FR | LOI</span>
                        </div>
                        <div class="entry-content">
                            <p style="font-weight:700; font-size:1rem; line-height:1.4; margin-bottom:0.25rem;">LOI organique n° 2026-410 du 28 mai 2026</p>
                            <p style="font-size:0.875rem; color:#000; line-height:1.5; margin-bottom:0.5rem;">Article 1: La loi n° 99-209 du 19 mars 1999 organique relative à la Nouvelle-Calédonie est ainsi modifiée...</p>
                            <div style="font-size:0.75rem; color:#666; display:flex; justify-content:space-between; align-items:center;">
                                <span>Légifrance ➔</span>
                                <span>29.05.2026</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 4: Filters -->
                <div class="comparison-section">
                    <div class="section-meta">4. FEED FILTER PILLS</div>
                    <div class="tag-pills-section" style="display:flex; flex-wrap:wrap; gap:0.5rem;">
                        <span class="filter-pill filter-pill--feed filter-pill--active">RSS Active</span>
                        <span class="filter-pill" style="border-color:#ccc; color:#888;">RSS Deactivated</span>
                        <span class="filter-pill filter-pill--lex filter-pill--active">Lex Active</span>
                        <span class="filter-pill filter-pill--leg filter-pill--active">Leg Active</span>
                    </div>
                </div>

                <!-- Section 5: Forms & Buttons -->
                <div class="comparison-section">
                    <div class="section-meta">5. FORMS & INPUTS</div>
                    <div class="search-form">
                        <input type="text" class="search-input" value="Search entries..." readonly style="width:100%;">
                        <button class="top-bar-btn top-bar-btn--text" style="width:100%;">Search</button>
                    </div>
                </div>
            </div>

            <!-- COLUMN 2: PROPOSED BRUTALIST REFRESH -->
            <div class="column-proposed">
                <div class="sandbox-h2">Proposed Brutalist Refresh</div>

                <!-- Section 1: Branding -->
                <div class="comparison-section">
                    <div class="section-meta">1. BRANDING & HEADER (TACTILE FRAME)</div>
                    <div class="ref-header-brand" style="display: flex; align-items: center; gap: 0.5rem; justify-content: space-between;">
                        <div class="ref-brand-title" style="display: flex; align-items: center; gap: 0.5rem;">
                            <img src="<?= e($basePath) ?>/assets/img/logo.svg" alt="" class="logo-icon" width="24" height="24" style="height: 1.5rem; width: auto;" decoding="async">
                            <span>SEISMO</span>
                        </div>
                        <div class="ref-brand-version">v0.7.8</div>
                    </div>
                </div>

                <!-- Section 2: Navigation Drawer -->
                <div class="comparison-section">
                    <div class="section-meta">2. CABINET TAB DRAWER (HARMONIOUS GRID & OVERFLOW)</div>
                    <div class="ref-nav-drawer">
                        <a href="#" class="ref-nav-link active" onclick="return false;">Timeline</a>
                        <a href="#" class="ref-nav-link" onclick="return false;">Filter</a>
                        <a href="#" class="ref-nav-link" onclick="return false;">Highlights</a>
                        <a href="#" class="ref-nav-link" onclick="return false;">Researcher</a>
                        <a href="#" class="ref-nav-link" onclick="return false;">Label</a>
                        <a href="#" class="ref-nav-link" onclick="return false;">Feeds</a>
                        <a href="#" class="ref-nav-link" onclick="return false;">Media</a>
                        <a href="#" class="ref-nav-link" onclick="return false;">Scraper</a>
                        <a href="#" class="ref-nav-link" onclick="return false;">Mail</a>
                        <a href="#" class="nav-link" style="display:none;">Lex</a><!-- Skip custom helper if not needed but let's list the proposed ones -->
                        <a href="#" class="ref-nav-link" onclick="return false;">Lex</a>
                        <a href="#" class="ref-nav-link" onclick="return false;">Leg</a>
                        <a href="#" class="ref-nav-link" onclick="return false;">Styleguide</a>
                        <a href="#" class="ref-nav-link" onclick="return false;">Logbook</a>
                        <a href="#" class="ref-nav-link" onclick="return false;">Settings</a>
                        <a href="#" class="ref-nav-link" onclick="return false;">About</a>
                    </div>
                </div>

                <!-- Section 3: Cards -->
                <div class="comparison-section">
                    <div class="section-meta">3. METADATA CARDS (COMBO C TYPOGRAPHY & TAG GRIDS)</div>
                    
                    <!-- Feed Card -->
                    <div class="ref-card">
                        <div class="ref-card-header">
                            <span class="ref-tag ref-tag--feed-rss">RSS feed</span>
                            <span style="font-family: var(--sans-font); font-size: 0.75rem; color: #666; margin-inline-start: auto; opacity: 0.8;">SWISS OUTLET</span>
                        </div>
                        <div class="ref-card-title" style="font-family: var(--mono-font); font-size: 0.95rem; font-weight: 700; color: #000; letter-spacing: -0.04em; word-spacing: -0.12em; line-height: 1.4; margin: 0.25rem 0 0.5rem 0;">ECOWAS News as at 10 Nov 2025</div>
                        <div class="ref-card-body">
                            10 Nov 2025 Germany commits 49 million euros (82 billion naira) to support ECOWAS in strengthening peace, economic development...
                        </div>
                        <div class="ref-card-footer">
                            <a href="#" class="ref-btn-action" onclick="return false;">EXPAND ▾</a>
                            <span>2026-05-29 05:42</span>
                        </div>
                    </div>

                    <!-- Lex Card -->
                    <div class="ref-card">
                        <div class="ref-card-header">
                            <span class="ref-tag ref-tag--lex">FR | LOI</span>
                            <span style="font-family: var(--sans-font); font-size: 0.75rem; color: #666; margin-inline-start: auto; opacity: 0.8;">LÉGIFRANCE</span>
                        </div>
                        <div class="ref-card-title" style="font-family: var(--mono-font); font-size: 0.95rem; font-weight: 700; color: #000; letter-spacing: -0.04em; word-spacing: -0.12em; line-height: 1.4; margin: 0.25rem 0 0.5rem 0;">LOI organique n° 2026-410 du 28 mai 2026</div>
                        <div class="ref-card-body">
                            Article 1: La loi n° 99-209 du 19 mars 1999 organique relative à la Nouvelle-Calédonie est ainsi modifiée...
                        </div>
                        <div class="ref-card-footer">
                            <a href="#" class="ref-btn-action" onclick="return false;">LÉGIFRANCE ➔</a>
                            <span>2026-05-28</span>
                        </div>
                    </div>
                </div>

                <!-- Section 4: Filters -->
                <div class="comparison-section">
                    <div class="section-meta">4. FEED FILTER PILLS (STARK BLACK BORDERS)</div>
                    <div class="ref-filter-container">
                        <div class="ref-filter-group">
                            <span class="ref-filter-pill active feed">RSS ACTIVE</span>
                            <span class="ref-filter-pill deactivated">RSS INACTIVE</span>
                            <span class="ref-filter-pill active lex">LEX ACTIVE</span>
                            <span class="ref-filter-pill active leg">LEG ACTIVE</span>
                        </div>
                    </div>
                </div>

                <!-- Section 5: Forms & Buttons -->
                <div class="comparison-section">
                    <div class="section-meta">5. TACTILE INPUTS & BUTTONS (PUSH ACTION)</div>
                    <div class="ref-input-group">
                        <input type="text" class="ref-text-input" value="Search entries..." readonly>
                        <button class="ref-btn">SEARCH</button>
                    </div>
                </div>

                <!-- Section 6: Typography Pairing Proposals -->
                <div class="comparison-section" style="border-bottom:none; padding-bottom:0;">
                    <div class="section-meta">6. ELEGANT TYPOGRAPHY COMBINATIONS</div>
                    
                <!-- Section 6: Typography Pairing Options & Final Proposal -->
                <div class="comparison-section" style="border-bottom:none; padding-bottom:0;">
                    <div class="section-meta" style="color: #d00; font-size: 0.8rem; border-bottom: 2px solid #d00; padding-bottom: 4px; margin-bottom: 1.25rem;">6. DECISION BOARD: PROPOSED SCHEME & OPTION SPECIMENS</div>
                    
                    <!-- THE LIVE SPECIMEN: Combo C Refined Full Card with Pill -->
                    <div style="margin-bottom: 2.5rem;">
                        <span style="font-family: var(--mono-font); font-size: 0.75rem; font-weight: bold; color: #333; display: block; margin-bottom: 0.75rem; text-transform: uppercase;">A. CARD SPECIMENS (Combo C Proposal):</span>
                        
                        <div style="display: flex; flex-direction: column; gap: 1.25rem;">
                            <!-- Card 1: Single Pill (Feed Outlet) -->
                            <div class="ref-card" style="box-shadow: 2px 2px 0 #000; border-color: #000; background: #fff; margin-bottom: 0;">
                                <div class="ref-card-header">
                                    <span class="ref-tag ref-tag--feed-rss">RSS feed</span>
                                    <span style="font-family: var(--sans-font); font-size: 0.75rem; color: #666; margin-inline-start: auto; opacity: 0.8;">SWISS OUTLET</span>
                                </div>
                                <div class="ref-card-title">ECOWAS News as at 10 Nov 2025</div>
                                <div class="ref-card-body" style="margin-bottom: 0.75rem;">
                                    10 Nov 2025 Germany commits 49 million euros (82 billion naira) to support ECOWAS in strengthening peace, economic development...
                                </div>
                                <div class="ref-card-footer">
                                    <a href="#" class="ref-btn-action" onclick="return false;">EXPAND ▾</a>
                                    <span>29.05.2026 05:42</span>
                                </div>
                            </div>

                            <!-- Card 2: Double Pill (Lex Yellow + Act Type Gray) -->
                            <div class="ref-card" style="box-shadow: 2px 2px 0 #000; border-color: #000; background: #fff; margin-bottom: 0;">
                                <div class="ref-card-header">
                                    <div style="display: flex; gap: 0.375rem; align-items: center;">
                                        <span class="ref-tag ref-tag--lex">CH | FEDLEX</span>
                                        <span class="ref-tag" style="background-color: #e5e5e5; border-color: #000; color: #333;">ÄNDERUNG</span>
                                    </div>
                                    <span style="font-family: var(--mono-font); font-size: 0.7rem; color: #666; margin-inline-start: auto; font-weight: bold;">OFFICIAL INDEX</span>
                                </div>
                                <div class="ref-card-title">Verordnung über die Unfallversicherung (UVV)</div>
                                <div class="ref-card-body" style="margin-bottom: 0.75rem;">
                                    Der Schweizerische Bundesrat verordnet: Die Verordnung vom 20. September 1982 über die Unfallversicherung wird wie folgt geändert...
                                </div>
                                <div class="ref-card-footer">
                                    <a href="#" class="ref-btn-action" onclick="return false;">FEDLEX ➔</a>
                                    <span>28.05.2026</span>
                                </div>
                            </div>
                        </div>

                        <span style="font-family: var(--mono-font); font-size: 0.7rem; color: #666; display: block; margin-top: 0.75rem; line-height: 1.45;">
                            ✔ Typewriter Monospace Title (`ui-monospace`, `Consolas`) with tight tracking.<br>
                            ✔ Double-tag layout supports primary source pill + sub-classification tags (like act type `ÄNDERUNG` in low-contrast gray).<br>
                            ✔ Modern Sans Body (`San Francisco`, `Segoe UI`) for comfortable reading flow.
                        </span>
                    </div>

                    <!-- THE BRAND COLORS: Modernised Neo-Brutalist HSL Schemes -->
                    <div style="margin-bottom: 2.5rem; border-top: 1px dotted #ccc; padding-top: 1.5rem;">
                        <span style="font-family: var(--mono-font); font-size: 0.75rem; font-weight: bold; color: #333; display: block; margin-bottom: 0.75rem; text-transform: uppercase;">B. PROPOSED COHESIVE COLOR SCHEME (HSL):</span>
                        <p style="font-family: var(--sans-font); font-size: 0.85rem; color: #444; margin-bottom: 1rem; line-height: 1.45;">We shift the existing colors to calibrated, cohesive HSL tokens. This preserves the iconic brand identifiers while feeling unified and extremely lightweight.</p>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; font-family: var(--mono-font); font-size: 0.75rem;">
                            <!-- RSS Feed Color -->
                            <div style="display: flex; align-items: center; gap: 0.5rem; border: 2px solid #000; padding: 0.375rem; background: #fff;">
                                <div style="width: 1.5rem; height: 1.5rem; border: 1.5px solid #000; background: #add8e6;"></div>
                                <div>
                                    <span style="font-weight: bold; display: block;">RSS FEED</span>
                                    <span style="color: #666; font-size: 0.65rem;">#add8e6 (Ice Blue)</span>
                                </div>
                            </div>
                            
                            <!-- Substack Color -->
                            <div style="display: flex; align-items: center; gap: 0.5rem; border: 2px solid #000; padding: 0.375rem; background: #fff;">
                                <div style="width: 1.5rem; height: 1.5rem; border: 1.5px solid #000; background: #c5b4d1;"></div>
                                <div>
                                    <span style="font-weight: bold; display: block;">SUBSTACK</span>
                                    <span style="color: #666; font-size: 0.65rem;">#c5b4d1 (Lilac)</span>
                                </div>
                            </div>

                            <!-- Lex Color -->
                            <div style="display: flex; align-items: center; gap: 0.5rem; border: 2px solid #000; padding: 0.375rem; background: #fff;">
                                <div style="width: 1.5rem; height: 1.5rem; border: 1.5px solid #000; background: #f5f562;"></div>
                                <div>
                                    <span style="font-weight: bold; display: block;">LEX (LAWS)</span>
                                    <span style="color: #666; font-size: 0.65rem;">#f5f562 (Dull Neon Yellow)</span>
                                </div>
                            </div>

                            <!-- Leg Color -->
                            <div style="display: flex; align-items: center; gap: 0.5rem; border: 2px solid #000; padding: 0.375rem; background: #fff;">
                                <div style="width: 1.5rem; height: 1.5rem; border: 1.5px solid #000; background: #d4edda;"></div>
                                <div>
                                    <span style="font-weight: bold; display: block;">LEG (PARLIAMENT)</span>
                                    <span style="color: #666; font-size: 0.65rem;">#d4edda (Sage Green)</span>
                                </div>
                            </div>

                            <!-- Media Color -->
                            <div style="display: flex; align-items: center; gap: 0.5rem; border: 2px solid #000; padding: 0.375rem; background: #fff; grid-column: span 2;">
                                <div style="width: 1.5rem; height: 1.5rem; border: 1.5px solid #000; background: #e8a3a3;"></div>
                                <div>
                                    <span style="font-weight: bold; display: block;">MEDIA FEED</span>
                                    <span style="color: #666; font-size: 0.65rem;">#e8a3a3 (Muted Adobe Red / Clay — distinct from Mail Orange)</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ALTERNATIVE OPTIONS -->
                    <div style="border-top: 1px dotted #ccc; padding-top: 1.5rem; display: flex; flex-direction: column; gap: 1rem;">
                        <span style="font-family: var(--mono-font); font-size: 0.75rem; font-weight: bold; color: #666;">C. ALTERNATIVE TYPOGRAPHY OPTIONS:</span>

                        <!-- Font Combo A: The Editorial Serif -->
                        <div style="border: 2px solid #ccc; padding: 1rem; background: #fff;">
                            <span style="font-family: var(--mono-font); font-size: 0.65rem; background: #666; color: #fff; padding: 1px 4px; font-weight: bold; display: inline-block; margin-bottom: 0.5rem;">OPTION A: EDITORIAL SERIF HEADERS</span>
                            <h4 style="font-family: var(--slab-font); font-size: 1.2rem; font-weight: 700; color: #000; line-height: 1.3; margin: 0 0 0.5rem 0;">LOI organique n° 2026-410 du 28 mai 2026</h4>
                            <p style="font-family: var(--sans-font); font-size: 0.85rem; color: #555; line-height: 1.5; margin: 0;">Slab serif titles. Feels like an international print legal journal. Elegant, but less stark than monospace.</p>
                        </div>

                        <!-- Font Combo B: Elegant Neo-Grotesque Sans -->
                        <div style="border: 2px solid #ccc; padding: 1rem; background: #fff;">
                            <span style="font-family: var(--mono-font); font-size: 0.65rem; background: #666; color: #fff; padding: 1px 4px; font-weight: bold; display: inline-block; margin-bottom: 0.5rem;">OPTION B: POLISHED LIGHTWEIGHT SANS</span>
                            <h4 style="font-family: var(--sans-font); font-size: 1.05rem; font-weight: 700; color: #000; line-height: 1.35; margin: 0 0 0.5rem 0; letter-spacing: -0.01em;">LOI organique n° 2026-410 du 28 mai 2026</h4>
                            <p style="font-family: var(--sans-font); font-size: 0.85rem; color: #555; line-height: 1.5; margin: 0;">Maintains the exact default sans font stack but tones down bold weight to a clean 700 weight for a quiet, architectural layout.</p>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>
</body>
</html>
