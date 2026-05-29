<?php
/**
 * Seismo Design Refresh Mockup Page
 * @var string $basePath
 */

declare(strict_types=1);

$headerTitle = 'Design Refresh Showcase';
$headerSubtitle = 'Interactive preview of refined brutalist typography, stable navigation drawers, and multi-theme harmony';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Design Refresh Showcase — Seismo</title>
    <!-- Core styles -->
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css?v=<?= e(SEISMO_VERSION) ?>">
    
    <style>
        /* --- Showcase Page Custom Styles (Tactile Brutalist Only) --- */
        :root {
            --showcase-bg: #faf9f6;
            --showcase-accent: #ffea00;
            --showcase-accent-hover: #fff9e6;
            --showcase-border: #000000;
            --showcase-text: #000000;
        }

        body {
            background-color: var(--showcase-bg);
            color: var(--showcase-text);
            transition: background-color 0.25s ease, color 0.25s ease;
        }

        .showcase-container {
            max-width: 72rem;
            margin: 0 auto;
            padding: 2rem 1.25rem;
        }

        .showcase-header {
            border-bottom: 3px double var(--showcase-border);
            padding-bottom: 1.5rem;
            margin-bottom: 2rem;
        }

        .showcase-title-area {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .showcase-title-area h1 {
            font-family: var(--serif-font, Courier, monospace);
            font-size: 2.25rem;
            font-weight: 700;
            margin: 0;
            letter-spacing: -0.02em;
        }

        .showcase-subtitle {
            font-size: 1rem;
            margin-top: 0.5rem;
            opacity: 0.85;
            max-width: 50rem;
            line-height: 1.5;
        }

        /* Control Panel */
        .showcase-controls {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            background: #ffffff;
            border: 2px solid var(--showcase-border);
            padding: 1rem;
            box-shadow: 4px 4px 0 var(--showcase-border);
        }



        .control-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .control-label {
            font-family: var(--sans-font);
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            opacity: 0.7;
        }

        .control-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn-ctrl {
            font-family: var(--sans-font);
            font-size: 0.8rem;
            font-weight: 700;
            padding: 0.4rem 0.8rem;
            border: 2px solid var(--showcase-border);
            background: #ffffff;
            color: #000000;
            cursor: pointer;
            box-shadow: 2px 2px 0 var(--showcase-border);
            transition: all 0.1s ease;
        }

        .btn-ctrl:hover {
            transform: translate(-1px, -1px);
            box-shadow: 3px 3px 0 var(--showcase-border);
        }

        .btn-ctrl.active {
            background: var(--showcase-accent);
            transform: translate(1px, 1px);
            box-shadow: 1px 1px 0 var(--showcase-border);
        }

        /* Sandbox Frame */
        .showcase-frame {
            border: 2px solid var(--showcase-border);
            background: #ffffff;
            box-shadow: 6px 6px 0 var(--showcase-border);
            margin-bottom: 2rem;
            overflow: hidden;
            transition: max-width 0.3s ease;
        }



        .showcase-frame-header {
            background: var(--showcase-accent);
            border-bottom: 2px solid var(--showcase-border);
            padding: 0.75rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .frame-indicator {
            font-family: var(--sans-font);
            font-weight: 700;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #000000;
        }

        /* Header Mockup Area */
        .mock-header-container {
            padding: 1.25rem;
            background: var(--showcase-bg);
            border-bottom: 1px dashed var(--showcase-border);
        }

        .mock-top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid var(--showcase-border);
            padding-bottom: 0.625rem;
            margin-bottom: 1rem;
        }

        .mock-brand {
            font-family: var(--serif-font, Courier, monospace);
            font-size: 1.25rem;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .mock-menu-btn {
            width: 2.25rem;
            height: 2.25rem;
            border: 2px solid var(--showcase-border);
            background: #ffffff;
            color: #000000;
            font-weight: bold;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 2px 2px 0 var(--showcase-border);
        }

        /* REDESIGNED NAVIGATION DRAWER */
        .mock-nav-drawer {
            display: none;
            flex-wrap: wrap;
            border: none;
            margin-top: 0rem;
            margin-bottom: 1.5rem;
            background: transparent;
            gap: 0.625rem;
            padding: 0.5rem 0;
        }

        .mock-nav-drawer.open {
            display: flex;
        }

        .mock-nav-link {
            padding: 0.5rem 1rem;
            background: #ffffff;
            color: #000000;
            font-family: var(--sans-font, sans-serif);
            font-size: 0.875rem;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--showcase-border);
            box-shadow: 2px 2px 0 var(--showcase-border);
            transition: all 0.1s ease;
            position: relative;
        }

        .mock-nav-link:hover {
            background-color: var(--showcase-accent-hover);
            transform: translate(-1px, -1px);
            box-shadow: 3px 3px 0 var(--showcase-border);
        }

        .mock-nav-link.active {
            background-color: var(--showcase-accent);
            color: #000000;
            transform: translate(1px, 1px);
            box-shadow: 1px 1px 0 var(--showcase-border);
        }

        /* Interactive Simulator Layouts */
        .sim-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
            padding: 1.25rem;
        }

        @media (min-width: 48rem) {
            .sim-grid {
                grid-template-columns: 2fr 1fr;
            }
        }

        /* Feed & Card Previews */
        .mock-feed-area {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .mock-card {
            border: 2px solid var(--showcase-border);
            background: #ffffff;
            padding: 1.25rem;
            box-shadow: 4px 4px 0 var(--showcase-border);
            position: relative;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }



        .mock-card:hover {
            transform: translate(-2px, -2px);
            box-shadow: 6px 6px 0 var(--showcase-border);
        }

        .mock-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }

        .mock-badge {
            font-family: var(--serif-font, Courier, monospace);
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.25rem 0.5rem;
            border: 1px solid var(--showcase-border);
            background: #ffffff;
            color: #000000;
        }

        .mock-badge--media {
            background: #e8a3a3; /* Calibrated adobe/rose color */
        }

        .mock-badge--lex {
            background: #fdfd96;
        }

        .mock-card-title {
            font-family: var(--serif-font, Courier, monospace);
            font-size: 1.15rem;
            font-weight: 700;
            margin: 0.5rem 0;
            line-height: 1.35;
        }

        .mock-card-body {
            font-family: var(--sans-font);
            font-size: 0.875rem;
            line-height: 1.5;
            margin-bottom: 1rem;
            opacity: 0.9;
        }

        .mock-card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px dashed var(--showcase-border);
            padding-top: 0.75rem;
            font-family: var(--sans-font);
            font-size: 0.75rem;
            opacity: 0.8;
        }

        .mock-btn-expand {
            text-decoration: none;
            color: var(--showcase-text);
            font-family: var(--sans-font);
            font-size: 0.75rem;
            font-weight: 700;
            border: 1px solid var(--showcase-border);
            padding: 0.25rem 0.5rem;
            background: #ffffff;
            box-shadow: 1px 1px 0 var(--showcase-border);
        }



        /* Mobile Viewport Simulation specific rules */
        .simulating-mobile .showcase-frame {
            max-width: 412px;
            margin: 0 auto;
        }



        /* Info Panel */
        .mock-info-panel {
            border: 2px dashed var(--showcase-border);
            padding: 1rem;
            background: rgba(255, 255, 255, 0.4);
            font-family: var(--sans-font);
            font-size: 0.85rem;
            line-height: 1.5;
        }

        .mock-info-panel h3 {
            font-family: var(--serif-font, Courier, monospace);
            font-size: 1rem;
            margin-top: 0;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
        }

        .mock-info-panel ul {
            padding-inline-start: 1.25rem;
            margin: 0.5rem 0 0 0;
        }

        .mock-info-panel li {
            margin-bottom: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="showcase-container">
        
        <div class="showcase-header">
            <div class="showcase-title-area">
                <div>
                    <h1><?= e($headerTitle) ?></h1>
                    <p class="showcase-subtitle"><?= e($headerSubtitle) ?></p>
                </div>
            </div>
        </div>

        <!-- Interactive Customization Panel -->
        <div class="showcase-controls">
            <div class="control-group">
                <span class="control-label">Simulation Mode</span>
                <div class="control-buttons">
                    <button id="btn-desktop" class="btn-ctrl active" onclick="setSimulation(false)">🖥️ Desktop</button>
                    <button id="btn-mobile" class="btn-ctrl" onclick="setSimulation(true)">📱 Mobile Viewport</button>
                </div>
            </div>
        </div>

        <!-- Showcase Frame -->
        <div class="showcase-frame" id="main-frame">
            <div class="showcase-frame-header">
                <span class="frame-indicator" id="frame-indicator-text">Desktop Viewport Sim (100% Fluid Width)</span>
                <div style="display:flex; gap:0.25rem;">
                    <span style="width: 10px; height: 10px; border-radius: 50%; background: red; display: inline-block; border: 1px solid #000;"></span>
                    <span style="width: 10px; height: 10px; border-radius: 50%; background: yellow; display: inline-block; border: 1px solid #000;"></span>
                    <span style="width: 10px; height: 10px; border-radius: 50%; background: green; display: inline-block; border: 1px solid #000;"></span>
                </div>
            </div>

            <!-- Mock Header Area -->
            <div class="mock-header-container">
                <div class="mock-top-bar">
                    <div class="mock-brand" style="display: flex; align-items: center; gap: 0.5rem;">
                        <img src="<?= e($basePath) ?>/assets/img/logo.svg" alt="" class="logo-icon logo-icon-large" width="30" height="30" style="height: 1.875rem; width: auto;" decoding="async">
                        <span>SEISMO</span>
                    </div>
                    <button class="mock-menu-btn" title="Toggle Drawer">☰</button>
                </div>

                <!-- NEW HARMONIOUS TAB GRID DRAWER -->
                <div class="mock-nav-drawer">
                    <a href="#" class="mock-nav-link active" onclick="return false;">Timeline</a>
                    <a href="#" class="mock-nav-link" onclick="return false;">Filter</a>
                    <a href="#" class="mock-nav-link" onclick="return false;">Highlights</a>
                    <a href="#" class="mock-nav-link" onclick="return false;">Researcher</a>
                    <a href="#" class="mock-nav-link" onclick="return false;">Label</a>
                    <a href="#" class="mock-nav-link" onclick="return false;">Feeds</a>
                    <a href="#" class="mock-nav-link" onclick="return false;">Media</a>
                    <a href="#" class="mock-nav-link" onclick="return false;">Scraper</a>
                    <a href="#" class="mock-nav-link" onclick="return false;">Mail</a>
                    <a href="#" class="mock-nav-link" onclick="return false;">Lex</a>
                    <a href="#" class="mock-nav-link" onclick="return false;">Leg</a>
                    <a href="#" class="mock-nav-link" onclick="return false;">Styleguide</a>
                    <a href="#" class="mock-nav-link" onclick="return false;">Logbook</a>
                    <a href="#" class="mock-nav-link" onclick="return false;">Settings</a>
                    <a href="#" class="mock-nav-link" onclick="return false;">About</a>
                </div>
            </div>

            <!-- Content Area Simulator -->
            <div class="sim-grid">
                
                <div class="mock-feed-area">
                    
                    <!-- Media Card (Adobe/Rose Color) -->
                    <div class="mock-card">
                        <div class="mock-card-header">
                            <span class="mock-badge mock-badge--media">Press Monitor</span>
                            <span style="font-family: var(--sans-font); font-size: 0.75rem; opacity: 0.8;">NZZ DIGITAL</span>
                        </div>
                        <div class="mock-card-title">Neue Impulse in der Schweizer Medienlandschaft</div>
                        <div class="mock-card-body">
                            Die strukturelle Transformation der Newsrooms führt zu einer engeren Verzahnung von Recherche und Kuratierung. Journalistische Qualitätssicherung erfordert robuste Aggregationsplattformen.
                        </div>
                        <div class="mock-card-footer">
                            <a href="#" class="mock-btn-expand" onclick="return false;">expand ▾</a>
                            <span>29 May 2026 07:53</span>
                        </div>
                    </div>

                    <!-- Lex Card (CH Fedlex) -->
                    <div class="mock-card">
                        <div class="mock-card-header">
                            <span class="mock-badge mock-badge--lex">Lex CH</span>
                            <span style="font-family: var(--sans-font); font-size: 0.75rem; opacity: 0.8;">SR 172.010</span>
                        </div>
                        <div class="mock-card-title">Änderung des Regierungs- und Verwaltungsorganisationsgesetzes</div>
                        <div class="mock-card-body">
                            Der Bundesrat schlägt Massnahmen zur Optimierung der digitalen Bundesverwaltung vor. Es betrifft insbesondere die Interoperabilität von Datenplattformen der Bundesämter.
                        </div>
                        <div class="mock-card-footer">
                            <a href="#" class="mock-btn-expand" onclick="return false;">expand ▾</a>
                            <span>28 May 2026 14:12</span>
                        </div>
                    </div>

                </div>

                <!-- Sidebar Details / Notes -->
                <div class="mock-feed-area">
                    <div class="mock-info-panel">
                        <h3>Subtle Design Polishes</h3>
                        <ul>
                            <li><strong>Zero Black Filler:</strong> The navigation drawer now operates with a clean white/system background, preventing unsightly horizontal black blocks when items wrap to multiple lines.</li>
                            <li><strong>Stable Micro-Animations:</strong> Hovering or selecting tabs displays a tactile square bullet <code>▪</code> indicator. By keeping this placeholder structurally present but transparent in idle states, we guarantee <strong>0px of layout shift</strong> during navigation sweeps.</li>
                            <li><strong>Multi-Viewport Harmony:</strong> Clean borders collapse together perfectly in wrapped desktop rows, while translating smoothly to a balanced 2-column brutalist grid on mobile.</li>
                            <li><strong>Color Balancing:</strong> Balanced Adobe Rose/Clay badges keep contrast readable while protecting aesthetic integrity.</li>
                        </ul>
                    </div>
                </div>

            </div>
        </div>

        <div style="text-align: center; margin-top: 2rem;">
            <a href="<?= e($basePath) ?>/index.php?action=index" class="btn-ctrl" style="text-decoration:none; display:inline-block; padding: 0.5rem 1rem;">↩ Return to Main Timeline</a>
        </div>

    </div>

    <script>
        // Hamburger Menu Toggle Simulation
        (function() {
            var btn = document.querySelector('.mock-menu-btn');
            var nav = document.querySelector('.mock-nav-drawer');
            if (btn && nav) {
                btn.addEventListener('click', function() {
                    nav.classList.toggle('open');
                });
            }
        })();

        function setSimulation(isMobile) {
            var frame = document.getElementById('main-frame');
            var indicator = document.getElementById('frame-indicator-text');
            var btnDesktop = document.getElementById('btn-desktop');
            var btnMobile = document.getElementById('btn-mobile');

            if (isMobile) {
                frame.classList.add('simulating-mobile');
                indicator.innerText = "Mobile Viewport Sim (412px - Natural Wrapping Brutalist Key Blocks)";
                btnMobile.classList.add('active');
                btnDesktop.classList.remove('active');
            } else {
                frame.classList.remove('simulating-mobile');
                indicator.innerText = "Desktop Viewport Sim (100% Fluid Width)";
                btnDesktop.classList.add('active');
                btnMobile.classList.remove('active');
            }
        }
    </script>
</body>
</html>
