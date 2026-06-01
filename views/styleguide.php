<?php
/**
 * @var string $csrfField
 * @var string $basePath
 */

declare(strict_types=1);

$accent = seismoBrandAccent();
$headerTitle = 'Styleguide';
$headerSubtitle = 'Interactive design system showcase & simulator';
$activeNav = 'styleguide';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Styleguide — <?= e(seismoBrandTitle()) ?></title>
    
    <!-- Production CSS -->
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css?v=<?= e(SEISMO_VERSION) ?>">
    
    <!-- Curated Google Fonts for Design Showcase -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&family=Space+Mono:wght@400;700&family=Outfit:wght@400;600;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <?php if ($accent): ?>
    <style>:root { --seismo-accent: <?= e($accent) ?>; }</style>
    <?php endif; ?>

    <!-- Isolated Showcase Specific Styles -->
    <style>
        /* ═══ DESIGN SYSTEM VARIABLES ═══ */
        :root {
            /* Typographical Stacks */
            --sans-font: 'Inter', system-ui, -apple-system, sans-serif;
            --title-font: 'Outfit', sans-serif;
            --display-font: 'Space Grotesk', sans-serif;
            --mono-font: 'Space Mono', 'Fira Code', monospace;
            
            /* Curated Harmonies (Brutalist HSL Colors) */
            --color-bg: #ffffff;
            --color-dark: #000000;
            --color-light-grey: #f8f9fa;
            --color-border: #000000;
            
            /* Unified Tactical States */
            --color-accent: #ffea00;         /* Vibrant brand yellow */
            --color-hover-accent: #fff9e6;   /* Creamy yellow */
            
            /* Functional Colors (Mapped exactly to styleguide) */
            --color-success: #00aa00;
            --color-success-hover: #eafaf1;
            --color-success-active: #c3e6cb;
            
            --color-warning: #ff9900;
            --color-warning-hover: #fffdf0;
            --color-warning-active: #ffe8cc;
            
            --color-danger: #FF2C2C;
            --color-danger-hover: #fff5f5;
            --color-danger-active: #f8d7da;
            
            /* Source Pill Color Scheme */
            --pill-rss: #add8e6;             /* RSS feed blue */
            --pill-substack: #c5b4d1;        /* Substack violet */
            --pill-scraper: #add8e6;         /* Scraper blue */
            --pill-media: #ffc4c4;           /* Media red */
            --pill-lex: #f5f562;             /* Lex yellow */
            --pill-lex-ch: #ffffb3;          /* CH Lex lighter yellow */
            --pill-leg: #d4edda;             /* Leg green */
            --pill-mail: #ffdbbb;            /* Mail orange */
            
            /* Spacing Tokens */
            --space-xxs: 0.125rem;  /* 2px */
            --space-xs: 0.25rem;    /* 4px */
            --space-sm: 0.5rem;     /* 8px */
            --space-md: 0.625rem;   /* 10px */
            --space-lg: 0.75rem;    /* 12px */
            --space-xl: 0.875rem;   /* 14px */
            --space-xxl: 1rem;      /* 16px */
            --space-3xl: 1.25rem;   /* 20px */
            --space-4xl: 1.5rem;    /* 24px */
            --space-5xl: 2rem;      /* 32px */
        }
        
        /* ═══ WORKSPACE CONTAINER ═══ */
        .showcase-container {
            font-family: var(--sans-font);
            color: var(--color-dark);
            display: grid;
            grid-template-columns: 18rem 1fr;
            gap: var(--space-4xl);
            margin-top: var(--space-4xl);
            align-items: start;
        }

        .showcase-container * {
            box-sizing: border-box;
        }

        @media (max-width: 58rem) {
            .showcase-container {
                grid-template-columns: 1fr;
                gap: var(--space-xxl);
            }
            aside.showcase-sidebar {
                position: static !important;
                height: auto !important;
            }
        }
        
        /* ═══ NAVIGATION SIDEBAR ═══ */
        aside.showcase-sidebar {
            position: sticky;
            top: 2rem;
            display: flex;
            flex-direction: column;
            gap: var(--space-3xl);
            padding: var(--space-3xl);
            background: #ffffff;
            border: var(--space-xxs) solid var(--color-border);
            box-shadow: var(--space-xxs) var(--space-xxs) 0 var(--color-border);
        }
        
        .sidebar-brand {
            display: flex;
            flex-direction: column;
            gap: var(--space-xs);
        }
        
        .sidebar-brand h1 {
            font-family: var(--title-font);
            font-size: var(--space-4xl);
            font-weight: 800;
            letter-spacing: -0.02em;
            text-transform: uppercase;
        }
        
        .sidebar-brand span {
            font-family: var(--mono-font);
            font-size: 0.7rem;
            font-weight: 700;
            background: var(--color-accent);
            padding: var(--space-xs) var(--space-sm);
            border: var(--space-xxs) solid var(--color-border);
            display: inline-block;
            align-self: flex-start;
        }
        
        nav.sidebar-links {
            display: flex;
            flex-direction: column;
            gap: var(--space-sm);
        }
        
        nav.sidebar-links a {
            font-family: var(--display-font);
            font-weight: 700;
            text-decoration: none;
            color: var(--color-dark);
            padding: var(--space-sm) var(--space-xxl);
            border: var(--space-xxs) solid var(--color-border);
            background-color: #ffffff;
            box-shadow: var(--space-xxs) var(--space-xxs) 0 var(--color-border);
            transition: transform 0.1s ease, box-shadow 0.1s ease, background-color 0.1s ease;
            text-align: center;
        }
        
        nav.sidebar-links a:hover {
            transform: translate(-1px, -1px);
            box-shadow: 0.1875rem 0.1875rem 0 var(--color-border);
            background-color: var(--color-hover-accent);
        }
        
        nav.sidebar-links a:active {
            transform: translate(1px, 1px);
            box-shadow: 0 0 0 var(--color-border);
        }
        
        nav.sidebar-links a.active {
            transform: none;
            box-shadow: 0.0625rem 0.0625rem 0 var(--color-border);
            background-color: var(--color-accent);
        }
        
        .sidebar-footer {
            margin-top: auto;
            font-family: var(--mono-font);
            font-size: 0.65rem;
            color: #666;
            border-top: 1px dashed #ccc;
            padding-top: var(--space-sm);
            line-height: 1.3;
        }
        
        /* ═══ MAIN WORKSPACE ═══ */
        main.showcase-content {
            display: flex;
            flex-direction: column;
            gap: var(--space-5xl);
        }
        
        /* Sections & Headers */
        section.showcase-section {
            background: #ffffff;
            border: var(--space-xxs) solid var(--color-border);
            box-shadow: 0.25rem 0.25rem 0 var(--color-border);
            padding: var(--space-4xl);
            scroll-margin-top: 2rem;
        }
        
        .section-header {
            border-bottom: var(--space-xxs) solid var(--color-border);
            padding-bottom: var(--space-xxl);
            margin-bottom: var(--space-4xl);
        }
        
        .section-header h2 {
            font-family: var(--title-font);
            font-size: var(--space-4xl);
            font-weight: 800;
            text-transform: uppercase;
            border-bottom: none;
            padding-bottom: 0;
            margin-bottom: 0;
        }
        
        .section-header p {
            font-family: var(--sans-font);
            font-size: var(--space-xl);
            color: #555555;
            margin-top: var(--space-sm);
        }
        
        /* ═══ CLICKABLES & TRANSITIONS ═══ */
        .showcase-interactive,
        .showcase-interactive-success,
        .showcase-interactive-warning,
        .showcase-interactive-danger {
            border: 0.125rem solid #000000;
            border-radius: 0 !important; /* Lock to 90 degrees strictly */
            font-weight: 600;
            font-family: inherit;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: transform 0.1s ease, box-shadow 0.1s ease, background-color 0.1s ease, border-color 0.1s ease;
        }
        
        /* Standard clickables */
        .showcase-interactive {
            background-color: #ffffff;
            color: #000000;
            box-shadow: 0.125rem 0.125rem 0 #000000;
        }
        
        .showcase-interactive:hover {
            transform: translate(-1px, -1px);
            box-shadow: 0.1875rem 0.1875rem 0 #000000;
            background-color: #fff9e6;
        }
        
        .showcase-interactive:active {
            transform: translate(1px, 1px);
            box-shadow: 0 0 0 #000000;
        }
        
        .showcase-interactive.active {
            transform: none;
            box-shadow: 0.0625rem 0.0625rem 0 #000000;
            background-color: #ffea00;
        }
        
        /* Functional Override: SUCCESS */
        .showcase-interactive-success {
            background-color: #ffffff;
            color: #000000;
            border-color: var(--color-success);
            box-shadow: 0.125rem 0.125rem 0 var(--color-success);
        }
        
        .showcase-interactive-success:hover {
            transform: translate(-1px, -1px);
            box-shadow: 0.1875rem 0.1875rem 0 var(--color-success);
            background-color: var(--color-success-hover);
        }
        
        .showcase-interactive-success:active {
            transform: translate(1px, 1px);
            box-shadow: 0 0 0 var(--color-success);
        }
        
        .showcase-interactive-success.active {
            transform: none;
            box-shadow: 0.0625rem 0.0625rem 0 var(--color-success);
            background-color: var(--color-success-active);
        }
        
        /* Functional Override: WARNING */
        .showcase-interactive-warning {
            background-color: #ffffff;
            color: #000000;
            border-color: var(--color-warning);
            box-shadow: 0.125rem 0.125rem 0 var(--color-warning);
        }
        
        .showcase-interactive-warning:hover {
            transform: translate(-1px, -1px);
            box-shadow: 0.1875rem 0.1875rem 0 var(--color-warning);
            background-color: var(--color-warning-hover);
        }
        
        .showcase-interactive-warning:active {
            transform: translate(1px, 1px);
            box-shadow: 0 0 0 var(--color-warning);
        }
        
        .showcase-interactive-warning.active {
            transform: none;
            box-shadow: 0.0625rem 0.0625rem 0 var(--color-warning);
            background-color: var(--color-warning-active);
        }
        
        /* Functional Override: DANGER */
        .showcase-interactive-danger {
            background-color: #ffffff;
            color: #000000;
            border-color: var(--color-danger);
            box-shadow: 0.125rem 0.125rem 0 var(--color-danger);
        }
        
        .showcase-interactive-danger:hover {
            transform: translate(-1px, -1px);
            box-shadow: 0.1875rem 0.1875rem 0 var(--color-danger);
            background-color: var(--color-danger-hover);
        }
        
        .showcase-interactive-danger:active {
            transform: translate(1px, 1px);
            box-shadow: 0 0 0 var(--color-danger);
        }
        
        .showcase-interactive-danger.active {
            transform: none;
            box-shadow: 0.0625rem 0.0625rem 0 var(--color-danger);
            background-color: var(--color-danger-active);
        }
        
        /* Specific clickable sizing mappings */
        .btn-styleguide-regular {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }
        
        .btn-styleguide-small {
            padding: 0.25rem 0.625rem;
            font-size: 0.75rem;
            line-height: 1;
        }
        
        .btn-styleguide-star {
            width: 1.625rem;
            height: 1.625rem;
            font-size: 0.75rem;
            display: inline-flex;
        }
        
        /* settings tabs layout */
        .tabs-styleguide-bar {
            display: flex;
            gap: var(--space-sm);
            border-bottom: var(--space-xxs) solid var(--color-border);
            padding-bottom: var(--space-3xl);
            margin-bottom: var(--space-3xl);
        }
        
        /* View toggle buttons */
        .toggle-styleguide-bar {
            display: inline-flex;
            border: var(--space-xxs) solid var(--color-border);
            box-shadow: var(--space-xxs) var(--space-xxs) 0 var(--color-border);
            margin-bottom: var(--space-3xl);
        }
        
        .toggle-styleguide-btn {
            border: none !important;
            padding: var(--space-sm) var(--space-xxl);
            font-family: var(--sans-font);
            font-weight: 700;
            font-size: 0.875rem;
            cursor: pointer;
            background: #ffffff;
            transition: background-color 0.1s;
        }
        
        .toggle-styleguide-btn:first-child {
            border-right: var(--space-xxs) solid var(--color-border) !important;
        }
        
        .toggle-styleguide-btn.active {
            background-color: var(--color-accent);
        }
        
        .toggle-styleguide-btn:hover:not(.active) {
            background-color: var(--color-hover-accent);
        }
        
        /* ═══ GRID AND SPACING VISUALIZER ═══ */
        .visual-spacing-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(8rem, 1fr));
            gap: var(--space-3xl);
            margin-bottom: var(--space-4xl);
        }
        
        .visual-spacing-card {
            background: #ffffff;
            border: var(--space-xxs) solid var(--color-border);
            padding: var(--space-3xl);
            text-align: center;
        }
        
        .visual-spacing-block {
            background: var(--color-accent);
            border: 1px solid var(--color-border);
            margin: 0 auto var(--space-sm);
        }
        
        .visual-spacing-card label {
            font-family: var(--mono-font);
            font-size: 0.75rem;
            font-weight: 700;
            display: block;
        }
        
        .visual-spacing-card span {
            font-size: 0.65rem;
            color: #666;
        }
        
        /* ═══ INTERACTIVE SIMULATION CONTAINER ═══ */
        .simulator-controls {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-sm);
            align-items: center;
            margin-bottom: var(--space-3xl);
            padding: var(--space-3xl);
            background: var(--color-light-grey);
            border: var(--space-xxs) dashed var(--color-border);
        }
        
        .sim-width-readout {
            font-family: var(--mono-font);
            font-weight: 700;
            margin-inline-start: auto;
            background: #fff;
            padding: var(--space-xs) var(--space-sm);
            border: 1px solid #000;
        }
        
        .simulator-viewport-frame {
            border: var(--space-xxs) solid var(--color-border);
            box-shadow: 0.375rem 0.375rem 0 var(--color-border);
            background: #ffffff;
            margin: 0 auto;
            transition: max-width 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            max-width: 100%;
            overflow: hidden;
        }
        
        .simulator-viewport-content {
            padding: var(--space-4xl);
            display: flex;
            flex-direction: column;
            gap: var(--space-3xl);
            min-height: 25rem;
        }
        
        /* ═══ CARDS DESIGN SYSTEM SPECIFICATION ═══ */
        .showcase-card {
            border: var(--space-xxs) solid var(--color-border);
            background: #ffffff;
            padding: var(--space-xxl) var(--space-3xl);
            box-shadow: 0 0 0 #000000;
            transition: box-shadow 0.15s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.15s ease;
            position: relative;
            display: flex;
            flex-direction: column;
            gap: var(--space-sm);
            text-align: start;
        }
        
        .showcase-card:hover {
            box-shadow: 0.125rem 0.125rem 0 var(--color-border);
        }
        
        .card-header-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--space-sm);
            width: 100%;
        }
        
        .card-action-cross,
        .card-action-star {
            font-size: 0.95rem;
            color: #888888;
            cursor: pointer;
            opacity: 0.55;
            font-family: var(--sans-font);
            transition: opacity 0.15s, color 0.15s, transform 0.1s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.375rem;
            height: 1.375rem;
        }
        
        .card-action-cross:hover,
        .card-action-star:hover {
            opacity: 1 !important;
            color: #000000 !important;
            transform: scale(1.15);
        }
        
        .magnitu-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.75rem;
            height: 1.5rem;
            padding: 2px 0.375rem 0;
            font-size: 0.72rem;
            font-weight: 700;
            font-family: var(--mono-font);
            border: var(--space-xxs) solid var(--color-border);
            box-shadow: 0.0625rem 0.0625rem 0 var(--color-border);
            border-radius: 0;
            white-space: nowrap;
            box-sizing: border-box;
        }
        
        .magnitu-badge-investigation {
            background-color: #FF6B6B;
            color: #000000;
        }
        
        .magnitu-badge-important {
            background-color: #FFA94D;
            color: #000000;
        }
        
        .magnitu-badge-background {
            background-color: #74C0FC;
            color: #000000;
        }
        
        .magnitu-badge-noise {
            background-color: #e0e0e0;
            color: #000000;
        }
        
        /* Square Tag Pills */
        .pill-source {
            font-family: var(--sans-font);
            font-size: 0.72rem;
            font-weight: 750;
            padding: 2px 0.625rem 0;
            border: var(--space-xxs) solid var(--color-border);
            text-transform: uppercase;
            letter-spacing: 0.03em;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
            white-space: nowrap;
            height: 1.5rem;
            box-sizing: border-box;
        }
        
        .card-metadata-mono {
            font-family: var(--mono-font);
            font-size: 0.7rem;
            font-weight: 700;
            color: #666666;
            text-transform: uppercase;
        }
        
        .card-title {
            font-family: var(--sans-font);
            font-size: 0.95rem;
            font-weight: 750;
            line-height: 1.35;
            color: var(--color-dark);
            margin: var(--space-xs) 0;
        }
        
        .card-body-text {
            font-family: var(--sans-font);
            font-size: 0.8rem;
            line-height: 1.45;
            color: #222222;
        }
        
        .card-actions-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--space-md);
            margin-top: var(--space-sm);
            padding-top: var(--space-md);
            border-top: var(--space-xxs) dashed #dddddd;
        }
        
        .card-date-stamp {
            font-family: var(--mono-font);
            font-size: 0.7rem;
            color: #666666;
        }
        
        /* ═══ LEX / LEG SPECIFIC BADGES ═══ */
        .lex-badge-group {
            display: flex;
            gap: var(--space-xs);
        }
        
        .lex-badge-country {
            font-family: var(--mono-font);
            font-size: 0.65rem;
            font-weight: 700;
            padding: 2px 0.45rem 0;
            background: var(--color-dark);
            color: #ffffff;
            border: 1px solid var(--color-dark);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
            white-space: nowrap;
            height: 1.5rem;
            box-sizing: border-box;
        }
        
        .lex-badge-act {
            font-family: var(--mono-font);
            font-size: 0.65rem;
            font-weight: 700;
            padding: 2px 0.45rem 0;
            background: #ffffff;
            color: var(--color-dark);
            border: 1px solid var(--color-dark);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
            white-space: nowrap;
            height: 1.5rem;
            box-sizing: border-box;
        }
        
        /* Hydration Scraper Metrics Banner */
        .hydration-metric-banner {
            margin-top: var(--space-sm);
            padding: var(--space-sm) var(--space-md);
            border: 0.125rem dashed var(--color-border);
            background: var(--color-light-grey);
            font-family: var(--mono-font);
            font-size: 0.68rem;
            display: flex;
            justify-content: space-between;
        }
        
        /* Label Pill queue visualization */
        .labeling-queue-playground {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
            gap: var(--space-sm);
            margin-top: var(--space-md);
        }
        
        .label-pill-btn {
            font-family: var(--sans-font);
            font-weight: 700;
            font-size: 0.75rem;
            padding: 0.45rem var(--space-sm);
            text-align: center;
        }
        
        /* ═══ SPEC EXPLORER SHEETS ═══ */
        .code-explorer-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: var(--space-xl);
            border: var(--space-xxs) solid var(--color-border);
        }
        
        .code-explorer-table th, .code-explorer-table td {
            padding: var(--space-md) var(--space-xl);
            border: 1px solid #ccc;
            text-align: left;
        }
        
        .code-explorer-table th {
            background: var(--color-light-grey);
            font-family: var(--title-font);
            font-weight: 700;
            border-bottom: var(--space-xxs) solid var(--color-border);
        }
        
        .code-snippet-box {
            font-family: var(--mono-font);
            font-size: 0.72rem;
            background: #272822;
            color: #f8f8f2;
            padding: var(--space-sm) var(--space-md);
            overflow-x: auto;
            border: 1px solid #000;
            white-space: pre-wrap;
            max-height: 12rem;
            text-align: start;
        }
    </style>
</head>
<body class="styleguide-body">
    <div class="container">
        <?php require __DIR__ . '/partials/site_header.php'; ?>

        <!-- ENRICHED DESIGN SYSTEM WORKSPACE -->
        <div class="showcase-container">
            
            <!-- STICKY SIDEBAR -->
            <aside class="showcase-sidebar">
                <div class="sidebar-brand">
                    <h1>Seismo</h1>
                    <span>Design System Blueprint</span>
                </div>
                
                <nav class="sidebar-links">
                    <a href="#grids" class="active" onclick="activateLink(this)">Grids & Spacing</a>
                    <a href="#clickables" onclick="activateLink(this)">Clickable Components</a>
                    <a href="#source-colors" onclick="activateLink(this)">Source Color Schemes</a>
                    <a href="#cards" onclick="activateLink(this)">Card Typology Showcase</a>
                    <a href="#simulator" onclick="activateLink(this)">Responsive Simulator</a>
                    <a href="#specs" onclick="activateLink(this)">Stylesheet Blueprint</a>
                </nav>
                
                <div class="sidebar-footer">
                    Version <?= e(SEISMO_VERSION) ?><br>
                    Tactile Brutalist Engine<br>
                    Integrated Showcase
                </div>
            </aside>
            
            <!-- MAIN SURFACE -->
            <main class="showcase-content">
                
                <!-- SECTION 1: GRIDS & SPACING -->
                <section id="grids" class="showcase-section">
                    <div class="section-header">
                        <h2>Grid & Spacing System</h2>
                        <p>The layout is guided strictly by fixed, incremental HSL spacing tokens. Every margin, padding, card gutter, and absolute coordinate aligns to these ratios. Rounded elements are locked strictly to 0 border-radius.</p>
                    </div>
                    
                    <div class="visual-spacing-grid">
                        <div class="visual-spacing-card">
                            <div class="visual-spacing-block" style="width: 2px; height: 16px;"></div>
                            <label>2px</label>
                            <span>--space-xxs<br>Border / Shadow</span>
                        </div>
                        <div class="visual-spacing-card">
                            <div class="visual-spacing-block" style="width: 4px; height: 16px;"></div>
                            <label>4px</label>
                            <span>--space-xs<br>Micro paddings</span>
                        </div>
                        <div class="visual-spacing-card">
                            <div class="visual-spacing-block" style="width: 8px; height: 16px;"></div>
                            <label>8px</label>
                            <span>--space-sm<br>Grid internal</span>
                        </div>
                        <div class="visual-spacing-card">
                            <div class="visual-spacing-block" style="width: 10px; height: 16px;"></div>
                            <label>10px</label>
                            <span>--space-md<br>Card gutters</span>
                        </div>
                        <div class="visual-spacing-card">
                            <div class="visual-spacing-block" style="width: 12px; height: 16px;"></div>
                            <label>12px</label>
                            <span>--space-lg<br>Timeline padding</span>
                        </div>
                        <div class="visual-spacing-card">
                            <div class="visual-spacing-block" style="width: 16px; height: 16px;"></div>
                            <label>16px</label>
                            <span>--space-xxl<br>Default margin</span>
                        </div>
                        <div class="visual-spacing-card">
                            <div class="visual-spacing-block" style="width: 24px; height: 16px;"></div>
                            <label>24px</label>
                            <span>--space-4xl<br>Outer frame padding</span>
                        </div>
                    </div>
                </section>
                
                <!-- SECTION 2: CLICKABLES -->
                <section id="clickables" class="showcase-section">
                    <div class="section-header">
                        <h2>Tactile Interactive Clickables</h2>
                        <p>Every clickable element on Seismo operates under a unified typewriter mechanical-key physical model. Standard buttons use black HSL, while functional alert buttons retain their color schemes but share the identical hover/active physics.</p>
                    </div>
                    
                    <h3 style="margin-bottom: var(--space-sm); font-family: var(--title-font); font-size: 1.1rem; text-transform: uppercase;">Standard Action Keys</h3>
                    <div style="display: flex; flex-wrap: wrap; gap: var(--space-sm); margin-bottom: var(--space-4xl);">
                        <button class="showcase-interactive btn-styleguide-regular">Primary Action</button>
                        <button class="showcase-interactive btn-styleguide-regular" style="font-weight: 500;">Secondary Action</button>
                        <button class="showcase-interactive btn-styleguide-small">Compact Expand Trigger ▼</button>
                        <button class="showcase-interactive btn-styleguide-star">★</button>
                    </div>
                    
                    <h3 style="margin-bottom: var(--space-sm); font-family: var(--title-font); font-size: 1.1rem; text-transform: uppercase;">Functional Alert Overrides</h3>
                    <div style="display: flex; flex-wrap: wrap; gap: var(--space-sm); margin-bottom: var(--space-4xl);">
                        <button class="showcase-interactive-success btn-styleguide-regular">Success (Save / Add)</button>
                        <button class="showcase-interactive-warning btn-styleguide-regular">Warning (Disable / Pause)</button>
                        <button class="showcase-interactive-danger btn-styleguide-regular">Danger (Delete / Dismiss)</button>
                    </div>
                    
                    <h3 style="margin-bottom: var(--space-sm); font-family: var(--title-font); font-size: 1.1rem; text-transform: uppercase;">Settings Subpage Navigation Tabs</h3>
                    <div class="tabs-styleguide-bar">
                        <a href="#clickables" class="showcase-interactive btn-styleguide-regular active">General Settings</a>
                        <a href="#clickables" class="showcase-interactive btn-styleguide-regular">Mail Parsers</a>
                        <a href="#clickables" class="showcase-interactive btn-styleguide-regular">AI Briefing (Magnitu)</a>
                        <a href="#clickables" class="showcase-interactive-danger btn-styleguide-regular">Satellites (Alert)</a>
                    </div>
                    
                    <h3 style="margin-bottom: var(--space-sm); font-family: var(--title-font); font-size: 1.1rem; text-transform: uppercase;">Timeline View Toggle Bar</h3>
                    <div class="toggle-styleguide-bar">
                        <button class="toggle-styleguide-btn active">Items</button>
                        <button class="toggle-styleguide-btn">Sources Feed Tab</button>
                    </div>
                    
                    <h3 style="margin-bottom: var(--space-sm); font-family: var(--title-font); font-size: 1.1rem; text-transform: uppercase;">Training Labeling Pill Toggles</h3>
                    <p style="color: #666; font-size: 0.75rem; margin-bottom: var(--space-sm);">These pills align directly within the unified 3D key model, providing clear tactile confirmation on queue label submissions.</p>
                    <div class="labeling-queue-playground">
                        <button class="showcase-interactive-danger label-pill-btn">Investigation Lead</button>
                        <button class="showcase-interactive-warning label-pill-btn">Important</button>
                        <button class="showcase-interactive btn-styleguide-regular label-pill-btn" style="border-color: #74C0FC; box-shadow: 2px 2px 0 #74C0FC;">Background</button>
                        <button class="showcase-interactive btn-styleguide-regular label-pill-btn" style="border-color: #ddd; box-shadow: 2px 2px 0 #ddd;">Noise (Default)</button>
                    </div>
                </section>
                
                <!-- SECTION 3: SOURCE COLOR SCHEMES -->
                <section id="source-colors" class="showcase-section">
                    <div class="section-header">
                        <h2>Source Color Schemes</h2>
                        <p>Brutalist tag pills and interactive filters utilize dedicated color mapping. These colors differentiate content families at a glance across card lists, detail sheets, and settings dashboards.</p>
                    </div>
                    
                    <div class="visual-spacing-grid" style="grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));">
                        <div class="visual-spacing-card" style="border-top: 0.375rem solid var(--pill-rss);">
                            <span class="pill-source" style="background-color: var(--pill-rss); margin-bottom: var(--space-sm);">RSS FEEDS</span>
                            <label>#ADD8E6</label>
                            <span>--seismo-pill-feed-rss<br>Standard web streams</span>
                        </div>
                        <div class="visual-spacing-card" style="border-top: 0.375rem solid var(--pill-substack);">
                            <span class="pill-source" style="background-color: var(--pill-substack); margin-bottom: var(--space-sm);">SUBSTACK</span>
                            <label>#C5B4D1</label>
                            <span>--seismo-pill-feed-substack<br>Newsletter streams</span>
                        </div>
                        <div class="visual-spacing-card" style="border-top: 0.375rem solid var(--pill-media);">
                            <span class="pill-source" style="background-color: var(--pill-media); margin-bottom: var(--space-sm);">MEDIA</span>
                            <label>#FFC4C4</label>
                            <span>--seismo-pill-feed-media<br>Press / Editorial news</span>
                        </div>
                        <div class="visual-spacing-card" style="border-top: 0.375rem solid var(--pill-scraper);">
                            <span class="pill-source" style="background-color: var(--pill-scraper); margin-bottom: var(--space-sm);">SCRAPER</span>
                            <label>#ADD8E6</label>
                            <span>--seismo-pill-scraper<br>Hydrated full-text views</span>
                        </div>
                        <div class="visual-spacing-card" style="border-top: 0.375rem solid var(--pill-lex);">
                            <span class="pill-source" style="background-color: var(--pill-lex); margin-bottom: var(--space-sm);">LEX</span>
                            <label>#F5F562</label>
                            <span>--seismo-pill-lex<br>General Legislation</span>
                        </div>
                        <div class="visual-spacing-card" style="border-top: 0.375rem solid var(--pill-lex-ch);">
                            <span class="pill-source" style="background-color: var(--pill-lex-ch); margin-bottom: var(--space-sm);">CH LEX</span>
                            <label>#FFFFB3</label>
                            <span>--pill-lex-ch<br>Swiss Fedlex regulation</span>
                        </div>
                        <div class="visual-spacing-card" style="border-top: 0.375rem solid var(--pill-leg);">
                            <span class="pill-source" style="background-color: var(--pill-leg); margin-bottom: var(--space-sm);">PARL LEG</span>
                            <label>#D4EDDA</label>
                            <span>--seismo-pill-leg<br>Swiss deliberative events</span>
                        </div>
                        <div class="visual-spacing-card" style="border-top: 0.375rem solid var(--pill-mail);">
                            <span class="pill-source" style="background-color: var(--pill-mail); margin-bottom: var(--space-sm);">EMAIL INGEST</span>
                            <label>#FFDBBB</label>
                            <span>--seismo-pill-mail<br>Email newsletter parsing</span>
                        </div>
                        <div class="visual-spacing-card" style="border-top: 0.375rem solid #e0e0e0;">
                            <span class="pill-source" style="background-color: #e0e0e0; margin-bottom: var(--space-sm);">SWISSMEM</span>
                            <label>#E0E0E0</label>
                            <span>Grey toggle<br>Swissmem monitor source</span>
                        </div>
                    </div>
                </section>
                
                <!-- SECTION 4: CARDS TYPOLOGY SHOWCASE -->
                <section id="cards" class="showcase-section">
                    <div class="section-header">
                        <h2>Card Typology Showcase</h2>
                        <p>Timeline feeds render highly structured, modular cards depending on the content taxonomy. Metadata alignment, branding headers, tag pill colors, and date stamps are fully specified below.</p>
                    </div>
                    
                    <!-- RSS CARD -->
                    <div style="margin-bottom: var(--space-4xl);">
                        <h3 style="font-family: var(--mono-font); font-size: 0.8rem; font-weight: 700; color: #555; margin-bottom: var(--space-sm); text-align: start;">1. RSS Feed / Substack Card</h3>
                        <div class="showcase-card">
                            <div class="card-header-bar">
                                <span class="pill-source" style="background-color: var(--pill-rss);">NZZ DIGITAL</span>
                                <div style="display: inline-flex; align-items: center; gap: var(--space-sm); margin-inline-start: auto;">
                                    <span class="card-metadata-mono" style="margin-right: var(--space-xs);">Feed Ingest</span>
                                    <span class="card-action-cross" title="Hide entry">×</span>
                                    <span class="card-action-star" title="Add to favourites">☆</span>
                                    <span class="magnitu-badge magnitu-badge-important">54</span>
                                </div>
                            </div>
                            <h4 class="card-title">Streit über Budgetkürzungen im Bundeshaus eskaliert</h4>
                            <p class="card-body-text">Die Kommissionen debattieren über einschneidende Kreditsperren in der landwirtschaftlichen Exportförderung. Wirtschaftsvertreter warnen vehement vor fatalen Folgen für Schweizer Betriebe.</p>
                            <div class="card-actions-bar">
                                <button class="showcase-interactive btn-styleguide-small">expand ▼</button>
                                <span class="card-date-stamp">29.05.2026 08:35</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- SCRAPER CARD WITH HYDRATION BANNER -->
                    <div style="margin-bottom: var(--space-4xl);">
                        <h3 style="font-family: var(--mono-font); font-size: 0.8rem; font-weight: 700; color: #555; margin-bottom: var(--space-sm); text-align: start;">2. Scraper Hydrated Card</h3>
                        <div class="showcase-card">
                            <div class="card-header-bar">
                                <span class="pill-source" style="background-color: var(--pill-scraper);">🌐 BLICK ONLINE</span>
                                <div style="display: inline-flex; align-items: center; gap: var(--space-sm); margin-inline-start: auto;">
                                    <span class="card-metadata-mono" style="margin-right: var(--space-xs);">Full-Text Hydrate</span>
                                    <span class="card-action-cross" title="Hide entry">×</span>
                                    <span class="card-action-star" title="Add to favourites">☆</span>
                                    <span class="magnitu-badge magnitu-badge-investigation">92</span>
                                </div>
                            </div>
                            <h4 class="card-title">Überraschende Wende im Steuerstreit der Kantone</h4>
                            <p class="card-body-text">Blick exklusiv: Mehrere Westschweizer Kantone planen ein Bündnis zur Senkung der Einkommenssteuer für mittlere Einkommen, um der Abwanderung entgegenzuwirken…</p>
                            
                            <div class="hydration-metric-banner">
                                <span>Pipeline Status: Hydrated</span>
                                <span>Article Length: 4,821 chars</span>
                                <span>Parser Method: Readability (Longest Body)</span>
                            </div>
                            
                            <div class="card-actions-bar">
                                <button class="showcase-interactive btn-styleguide-small">expand ▼</button>
                                <span class="card-date-stamp">29.05.2026 07:12</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- LEX CARD -->
                    <div style="margin-bottom: var(--space-4xl);">
                        <h3 style="font-family: var(--mono-font); font-size: 0.8rem; font-weight: 700; color: #555; margin-bottom: var(--space-sm); text-align: start;">3. Swiss Legislation (CH Lex / Fedlex) Card</h3>
                        <div class="showcase-card">
                            <div class="card-header-bar">
                                <span class="pill-source" style="background-color: var(--pill-lex-ch); border-color: var(--color-border);"><?= e(seismo_lex_filter_pill_label('de')) ?></span>
                                <div style="display: inline-flex; align-items: center; gap: var(--space-sm); margin-inline-start: auto;">
                                    <div class="lex-badge-group" style="margin-right: var(--space-xs);">
                                        <span class="lex-badge-country">CH</span>
                                        <span class="lex-badge-act">SR 910.1</span>
                                    </div>
                                    <span class="card-action-cross" title="Hide entry">×</span>
                                    <span class="card-action-star" title="Add to favourites">☆</span>
                                    <span class="magnitu-badge magnitu-badge-background">62</span>
                                </div>
                            </div>
                            <h4 class="card-title">Verordnung über die biologische Landwirtschaft und die Kennzeichnung biologischer Erzeugnisse</h4>
                            <p class="card-body-text">Totalrevision zur Angleichung der Grenzwerte an die europäischen Einfuhrbestimmungen. Neue Kontrollverfahren ab dem nächsten Kalenderjahr vorgesehen.</p>
                            <div class="card-actions-bar">
                                <button class="showcase-interactive btn-styleguide-small">expand ▼</button>
                                <span class="card-date-stamp">28.05.2026 14:02</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- PARLAMENT LEG CARD -->
                    <div style="margin-bottom: var(--space-4xl);">
                        <h3 style="font-family: var(--mono-font); font-size: 0.8rem; font-weight: 700; color: #555; margin-bottom: var(--space-sm); text-align: start;">4. Parliamentary Deliberation (CH Leg) Card</h3>
                        <div class="showcase-card">
                            <div class="card-header-bar">
                                <span class="pill-source" style="background-color: var(--pill-leg);"><?= e(seismo_leg_filter_pill_label()) ?></span>
                                <div style="display: inline-flex; align-items: center; gap: var(--space-sm); margin-inline-start: auto;">
                                    <span class="card-metadata-mono" style="margin-right: var(--space-xs);">Geschäft 24.089</span>
                                    <span class="card-action-cross" title="Hide entry">×</span>
                                    <span class="card-action-star" title="Add to favourites">☆</span>
                                    <span class="magnitu-badge magnitu-badge-noise">15</span>
                                </div>
                            </div>
                            <h4 class="card-title">Finanzierung und Ausbau des Nationalstrassennetzes (Ausbauschritt 2026)</h4>
                            <p class="card-body-text">Eintreten im Ständerat einstimmig beschlossen. Beratung über Kredithöhe abgeschlossen. Keine materiellen Änderungen am bundesrätlichen Entwurf.</p>
                            <div class="card-actions-bar">
                                <button class="showcase-interactive btn-styleguide-small">expand ▼</button>
                                <span class="card-date-stamp">27.05.2026 11:45</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- MAIL CARD -->
                    <div>
                        <h3 style="font-family: var(--mono-font); font-size: 0.8rem; font-weight: 700; color: #555; margin-bottom: var(--space-sm); text-align: start;">5. Email Ingest Card</h3>
                        <div class="showcase-card">
                            <div class="card-header-bar">
                                <span class="pill-source" style="background-color: var(--pill-mail);">✉️ EMAIL PARSER</span>
                                <div style="display: inline-flex; align-items: center; gap: var(--space-sm); margin-inline-start: auto;">
                                    <span class="card-metadata-mono" style="margin-right: var(--space-xs);">Sender: briefing@swissmem.ch</span>
                                    <span class="card-action-cross" title="Hide entry">×</span>
                                    <span class="card-action-star" title="Add to favourites">☆</span>
                                    <span class="magnitu-badge magnitu-badge-important">78</span>
                                </div>
                            </div>
                            <h4 class="card-title">Swissmem Wirtschafts-Wochenbriefing Kw 22</h4>
                            <p class="card-body-text">Die Auftragseingänge im ersten Halbjahr deuten auf eine allmähliche Stabilisierung in den Kernmärkten der Schweizer Maschinen-, Elektro- und Metall-Industrie hin…</p>
                            <div class="card-actions-bar">
                                <button class="showcase-interactive btn-styleguide-small">expand ▼</button>
                                <span class="card-date-stamp">26.05.2026 18:22</span>
                            </div>
                        </div>
                    </div>
                </section>
                
                <!-- SECTION 4: RESPONSIVE SIMULATOR -->
                <section id="simulator" class="showcase-section">
                    <div class="section-header">
                        <h2>Live Responsive Simulator</h2>
                        <p>Observe how the layout grid dynamically scales, flows, and stacks under different screen widths. Click the buttons below to trigger real-time physical dimensions transformations.</p>
                    </div>
                    
                    <div class="simulator-controls">
                        <button class="showcase-interactive btn-styleguide-regular active" onclick="setSimWidth('100%', this)">🖥️ Full Desktop (100%)</button>
                        <button class="showcase-interactive btn-styleguide-regular" onclick="setSimWidth('54rem', this)">💻 Small Desktop (54rem)</button>
                        <button class="showcase-interactive btn-styleguide-regular" onclick="setSimWidth('40rem', this)">📱 Tablet Breakpoint (40rem)</button>
                        <button class="showcase-interactive btn-styleguide-regular" onclick="setSimWidth('24rem', this)">📱 Mobile Viewport (24rem)</button>
                        
                        <span id="widthReadout" class="sim-width-readout">Width: 100%</span>
                    </div>
                    
                    <div id="simViewportFrame" class="simulator-viewport-frame">
                        <div class="simulator-viewport-content">
                            
                            <!-- Header Mockup inside viewport -->
                            <div style="border-bottom: 2px solid #000; padding-bottom: var(--space-sm); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--space-sm); width: 100%;">
                                <span style="font-family: var(--title-font); font-size: 1.1rem; font-weight: 800; white-space: nowrap;">Timeline Preview Feed</span>
                                <div style="display: flex; gap: var(--space-xs); margin-left: auto;">
                                    <button class="showcase-interactive btn-styleguide-small">Filter Options</button>
                                    <button class="showcase-interactive btn-styleguide-small">Refresh ↻</button>
                                </div>
                            </div>
                            
                            <!-- Cards inside resizable viewport -->
                            <div class="showcase-card">
                                <div class="card-header-bar">
                                    <span class="pill-source" style="background-color: var(--pill-rss);">NZZ DIGITAL</span>
                                    <div style="display: inline-flex; align-items: center; gap: var(--space-sm); margin-inline-start: auto;">
                                        <span class="card-metadata-mono" style="margin-right: var(--space-xs);">Global Feed</span>
                                        <span class="card-action-cross" title="Hide entry">×</span>
                                        <span class="card-action-star" title="Add to favourites">☆</span>
                                        <span class="magnitu-badge magnitu-badge-important">82</span>
                                    </div>
                                </div>
                                <h4 class="card-title">Interaktive Dimensionstests sind vollkommen abgeschlossen</h4>
                                <p class="card-body-text">Die flexiblen Spacing-Tokens brechen bei kleineren Displays elegant in einspaltige Raster um. Dies schützt den Text vor unerwünschten Überläufen.</p>
                                <div class="card-actions-bar">
                                    <button class="showcase-interactive btn-styleguide-small">expand ▼</button>
                                    <span class="card-date-stamp">29.05.2026</span>
                                </div>
                            </div>
                            
                            <div class="showcase-card">
                                <div class="card-header-bar">
                                    <span class="pill-source" style="background-color: var(--pill-lex-ch);">FEDLEX CH</span>
                                    <div style="display: inline-flex; align-items: center; gap: var(--space-sm); margin-inline-start: auto;">
                                        <div class="lex-badge-group" style="margin-right: var(--space-xs);">
                                            <span class="lex-badge-country">CH</span>
                                            <span class="lex-badge-act">SR 173.110</span>
                                        </div>
                                        <span class="card-action-cross" title="Hide entry">×</span>
                                        <span class="card-action-star" title="Add to favourites">☆</span>
                                        <span class="magnitu-badge magnitu-badge-background">48</span>
                                    </div>
                                </div>
                                <h4 class="card-title">Bundesbeschluss über die Genehmigung des Protokolls zur Änderung des Doppelbesteuerungsabkommens</h4>
                                <div class="card-actions-bar">
                                    <button class="showcase-interactive btn-styleguide-small">expand ▼</button>
                                    <span class="card-date-stamp">28.05.2026</span>
                                </div>
                            </div>
                            
                        </div>
                    </div>
                </section>
                
                <!-- SECTION 5: STYLESHEET SPECIFICATIONS -->
                <section id="specs" class="showcase-section">
                    <div class="section-header">
                        <h2>Reconstructed Stylesheet Specs</h2>
                        <p>Review the highly simplified CSS directives that run our entire unified component dictionary. This shows how we eliminate massive redundancy by declaring unified interactive rules.</p>
                    </div>
                    
                    <h3 style="margin-bottom: var(--space-sm); font-family: var(--title-font); font-size: 1.1rem; text-transform: uppercase;">Unified Clickable Specification (Reconstructed Clean CSS)</h3>
                    <div class="code-snippet-box">/* ==========================================
   UNIFIED BRUTALIST CLICKABLES & TRANSITIONS
   Consolidates Nav-links, Buttons, Tabs, & Labeling
   ========================================== */

.btn, .top-bar-btn, .settings-tabs a, 
.view-toggle-bar a, .nav-link, .label-btn {
    border: 0.125rem solid #000000;
    border-radius: 0 !important; /* Locks sharp 90-degree tactile corners */
    font-weight: 600;
    transition: transform 0.1s ease, box-shadow 0.1s ease, background-color 0.1s ease;
    box-shadow: 0.0625rem 0.0625rem 0 #000000; /* Flat refined 1px shadow */
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

/* 3D Tactile Hover Lift */
.btn:hover, .top-bar-btn:hover, .settings-tabs a:hover, 
.view-toggle-bar a:hover, .nav-link:hover, .label-btn:hover {
    transform: translate(-1px, -1px);
    box-shadow: 0.125rem 0.125rem 0 #000000; /* Expanded 2px shadow */
}

/* 3D Pressed Active State (Transient) */
.btn:active, .top-bar-btn:active, 
.settings-tabs a:active, .nav-link:active, .label-btn:active {
    transform: translate(1px, 1px);
    box-shadow: 0 0 0 #000000; /* Collapsed 0px shadow */
}

/* 3D Selected State (Persistent) */
.btn.active, .top-bar-btn.active, 
.settings-tabs a.active, .nav-link.active, .label-btn.active {
    transform: none; /* Rests on baseline */
    box-shadow: 0.0625rem 0.0625rem 0 #000000; /* Maintains 1px drop shadow */
    background-color: #ffea00; /* Brand yellow accent */
}</div>
                    
                    <table class="code-explorer-table">
                        <thead>
                            <tr>
                                <th>Component Group</th>
                                <th>Active Production Selector</th>
                                <th>Reconstructed Optimization Goal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Navigation Drawer Block Links</strong></td>
                                <td><code>.nav-link</code></td>
                                <td>Integrated into the unified tactile transition framework, utilizing the primary cream-to-yellow active translation offsets.</td>
                            </tr>
                            <tr>
                                <td><strong>Timeline Action Controls</strong></td>
                                <td><code>.btn.entry-expand-btn</code>, <code>.timeline-media-toggle-btn</code>, etc.</td>
                                <td>Inherits identical typewriter physical states, replacing scattered hardcoded transitions.</td>
                            </tr>
                            <tr>
                                <td><strong>Functional Alert System</strong></td>
                                <td><code>.btn-success</code>, <code>.btn-warning</code>, <code>.btn-danger</code></td>
                                <td>Maintains distinct HSL border colors while binding hover overlays into clean, custom-colored translations.</td>
                            </tr>
                            <tr>
                                <td><strong>Settings View Tabs</strong></td>
                                <td><code>.settings-tabs a</code></td>
                                <td>Transitioned from flat inline overrides to stand-alone typewriter keys that press down on subpage loads.</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
                
            </main>
            
        </div>
    </div>

    <!-- Active Link & Viewport Resizing Script -->
    <script>
        function activateLink(element) {
            // Remove active class from all links
            document.querySelectorAll('nav.sidebar-links a').forEach(function(link) {
                link.classList.remove('active');
            });
            // Add active class to clicked link
            element.classList.add('active');
        }
        
        function setSimWidth(width, btn) {
            // Update active state on simulator buttons
            var controls = btn.closest('.simulator-controls');
            controls.querySelectorAll('button').forEach(function(b) {
                b.classList.remove('active');
            });
            btn.classList.add('active');
            
            // Set frame max-width
            var frame = document.getElementById('simViewportFrame');
            frame.style.maxWidth = width;
            
            // Readout width
            var readout = document.getElementById('widthReadout');
            readout.textContent = 'Width: ' + (width === '100%' ? '100% (Fluid)' : width);
        }
        
        // Handle direct anchor links smooth scroll without breaking layout sidebar active highlights
        window.addEventListener('hashchange', function() {
            var hash = window.location.hash;
            if (hash) {
                var activeLink = document.querySelector('nav.sidebar-links a[href="' + hash + '"]');
                if (activeLink) {
                    activateLink(activeLink);
                }
            }
        });
    </script>
</body>
</html>
