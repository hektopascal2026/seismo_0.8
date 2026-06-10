<?php
/**
 * @var string $basePath
 * @var string $csrfField
 * @var string|null $accent
 * @var string $headerTitle
 * @var string|null $headerSubtitle
 * @var string $activeNav
 * @var ?array{
 *   feeds: int,
 *   feed_items: int,
 *   emails: int,
 *   lex_items: int,
 *   calendar_events: int,
 *   scraper_configs: int
 * } $aboutStats
 * @var ?array{total: int, magnitu: int, recipe: int} $scoreCounts
 * @var string $seismoVersion
 * @var bool $satellite
 * @var string $view 'overview'|'history'
 */

declare(strict_types=1);

require_once SEISMO_ROOT . '/views/helpers.php';

$fmt = static fn (int $n): string => number_format($n, 0, '.', ',');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About &amp; History | <?= e(seismoBrandTitle()) ?></title>
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css">
    <?php if (!empty($accent)): ?>
    <style>:root { --seismo-accent: <?= e((string)$accent) ?>; }</style>
    <?php endif; ?>
</head>
<body>
    <div class="container">
        <?php require __DIR__ . '/partials/site_header.php'; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="message message-success"><?= e((string)$_SESSION['success']) ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="message message-error"><?= e((string)$_SESSION['error']) ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Subpage Tabs Navigation (same system as Settings, Lex, etc.) -->
        <nav class="settings-tabs" aria-label="About &amp; History sections" style="margin-bottom: 1.5rem;">
            <a href="<?= e($basePath) ?>/index.php?action=about&view=overview" class="<?= $view === 'overview' ? 'active' : '' ?>">Overview</a>
            <a href="<?= e($basePath) ?>/index.php?action=about&view=history" class="<?= $view === 'history' ? 'active' : '' ?>">History &amp; Versioning</a>
        </nav>

        <main class="settings-container about-modern-layout">
            <header class="settings-header about-hero">
                <h1>Seismo <?= e($seismoVersion) ?> &mdash; dashboard</h1>
                <p class="subtitle">Legislative and media monitoring &mdash; unified timeline, scoring, and exports</p>
            </header>

            <?php if ($view === 'overview'): ?>
                <!-- ==================== TAB I: OVERVIEW ==================== -->

                <!-- I. What is Seismo? -->
                <section class="settings-section about-card dashboard-section">
                    <h2>I. What is Seismo?</h2>
                    <p class="about-lede">Seismo is a professional-grade monitoring dashboard designed to aggregate disparate information streams into a single, unified chronological feed.</p>
                    <div class="about-grid">
                        <div class="about-grid-item">
                            <strong>Unified Monitoring</strong>
                            <p>Track policy, regulation, and media across multiple jurisdictions from one interface.</p>
                        </div>
                        <div class="about-grid-item">
                            <strong>Self-Hosted Control</strong>
                            <p>Keep your data and monitoring preferences private with a local-first architecture.</p>
                        </div>
                    </div>

                    <?php if ($satellite): ?>
                    <div class="admin-help" style="margin-top: 1rem;">
                        <strong>Satellite Instance:</strong> This installation reads timeline data from a central <em>mothership</em> database while maintaining local scores and Magnitu preferences.
                    </div>
                    <?php endif; ?>
                </section>

                <!-- II. Architecture & sources -->
                <section class="settings-section about-card dashboard-section">
                    <h2>II. Architecture &amp; data sources</h2>
                    <p>Everything you track merges into a single stream. Tune sources from Feeds, Media, Mail, Scraper, Lex, and Leg.</p>

                    <div class="table-responsive">
                        <table class="styleguide-table">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th>Coverage & Mechanism</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong>Direct Ingest</strong></td>
                                    <td>
                                        <strong>Feeds:</strong> RSS/Atom, Substack, and Parliament press (general desk).<br>
                                        <strong>Media:</strong> News monitoring — Google News or outlet RSS with optional <strong>Extract full text</strong> (publisher fetch + JSON-LD / Readability / meta); listing pages via Scraper with <code>category = media</code>.<br>
                                        <strong>Mail:</strong> IMAP/Gmail ingest with domain-first subscriptions; transactional and list mail.<br>
                                        <strong>Newsletter:</strong> Same Gmail/IMAP ingest, separate admin module for digest senders (<code>module_scope = newsletter</code>); warm-sand timeline pills (<code>#e5c1a7</code>); optional body processors and <strong>View in browser →</strong> when a webview link is present. <strong>AI Split Configurator</strong> (0.8.4) fans multi-story digests into child <code>emails</code> rows; <strong>subject_filter</strong> + <strong>New newsletter types</strong> (0.8.5) split several products from one sender (e.g. Politpuls vs Stimme der Wirtschaft). Admin shows digest parents with nested stories; Highlights, Magnitu, and Researcher see per-story rows only.<br>
                                        <strong>Scraper:</strong> Scheduled fetches of complex web pages.
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Lex Plugins</strong></td>
                                    <td>
                                        <strong>EU:</strong> EUR-Lex via SPARQL; full HTML corpus on ingest/backfill.<br>
                                        <strong>Switzerland:</strong> Fedlex via SPARQL; consultation synopsis stored in <code>content</code> where available.<br>
                                        <strong>Germany:</strong> recht.bund.de (BGBl) via RSS metadata + <strong>Regelungstext PDF</strong> extraction (<code>pdftotext</code>).<br>
                                        <strong>France:</strong> Légifrance via PISTE OAuth2; full JORF body via <code>/consult/jorf</code> on refresh.
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Intelligence</strong></td>
                                    <td>
                                        <strong>Leg:</strong> Swiss Parliament OData API (Motions, Sessions, Hearings).<br>
                                        <strong>Press:</strong> Swiss Parliament press releases via SharePoint.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <h3 class="about-subheading">The Swiss angle: monitoring hidden impact</h3>
                    <p>Foreign legislation and EU acts often move Swiss interests even when the text never names Switzerland. A banking or market rule framed for third countries or the single market can still shape compliance, passporting, or supply chains in CH. Lex and Leg catch the obvious Swiss file; the harder work is spotting <em>indirect</em> pressure early.</p>
                    <p class="meta-text">Example pattern: a neighbouring state’s finance law (e.g. a major 2026 budget or market reform) may matter for cross-border desks before Swiss media tie the thread.</p>
                    <p class="meta-text" style="margin-top: 0.5rem;"><strong>Watch-list tokens</strong> (German / EU context) often surface relevance without &ldquo;Switzerland&rdquo; in the title:</p>
                    <div class="about-detail-grid" role="list">
                        <div class="about-detail-grid__item" role="listitem">Drittstaaten</div>
                        <div class="about-detail-grid__item" role="listitem">Binnenmarkt</div>
                        <div class="about-detail-grid__item" role="listitem">Konformit&auml;tsbewertung</div>
                    </div>
                </section>

                <!-- III. Hybrid intelligence -->
                <section class="settings-section about-card dashboard-section">
                    <h2>III. Hybrid intelligence &amp; scoring</h2>
                    <p>Seismo keeps the timeline ordered and legible in two stages: a fast local baseline, then an optional ML layer that refines it.</p>

                    <div class="about-hybrid-stage">
                        <h3>Stage 1: PHP recipe engine</h3>
                        <p>As soon as an entry lands, a deterministic scorer runs in Seismo (keywords, source weights, class rules). You get immediate ranking and badges without waiting for an external service, so the dashboard stays usable during outages or first-time imports.</p>
                    </div>
                    <div class="about-hybrid-stage">
                        <h3>Stage 2: Magnitu v3</h3>
                        <p>The Python companion app syncs over HTTP: it can replace baseline scores with model predictions (relevance as a 0&ndash;100% style signal in the UI) and training labels. Magnitu reads entries and posts scores; recipe scores remain the fallback until Magnitu has written a row.</p>
                    </div>
                </section>

                <!-- IV. Magnitu labels -->
                <section class="settings-section about-card dashboard-section">
                    <h2>IV. Magnitu: training labels</h2>
                    <p>Manual labels and model output use a shared vocabulary so sorting and filters stay consistent.</p>

                    <div class="about-scoring-levels">
                        <div class="scoring-level">
                            <span class="level-tag tag-lead">Investigation Lead</span>
                            <p>High-priority starting points for investigative work.</p>
                        </div>
                        <div class="scoring-level">
                            <span class="level-tag tag-important">Important</span>
                            <p>Significant developments requiring your attention.</p>
                        </div>
                        <div class="scoring-level">
                            <span class="level-tag tag-background">Background</span>
                            <p>Contextual information archived for reference.</p>
                        </div>
                        <div class="scoring-level">
                            <span class="level-tag tag-noise">Noise</span>
                            <p>Irrelevant entries automatically deprioritized.</p>
                        </div>
                    </div>
                    <p class="meta-text">HTTP contract and client docs: <a href="https://github.com/hektopascal2026/magnitu" target="_blank" rel="noopener">Magnitu repository</a>.</p>
                </section>

                <!-- V. Mothership & satellite -->
                <section class="settings-section about-card dashboard-section">
                    <h2>V. Deployment patterns: mothership &amp; satellite</h2>
                    <p><strong>Mothership</strong> is the instance that runs core fetchers: RSS and Substack feeds, IMAP mail, scrapers, Lex plugins, Leg, and parliament press ingestion. It owns the heavy write load into shared entry tables.</p>
                    <p style="margin-top: 0.75rem;"><strong>Satellite</strong> instances are secondary installs on the same MySQL server that <em>read</em> those entry tables from the mothership database. Each satellite keeps its own scores, labels, and <code>system_config</code> so you can run separate Magnitu profiles (e.g. topic-focused relevance) without duplicating ingestion.</p>
                </section>

                <!-- VI. Seismogramm -->
                <section class="settings-section about-card dashboard-section">
                    <h2>VI. Seismogramm — preset Gemini briefing (v0.9.3)</h2>
                    <p class="about-lede">Desks use <strong>Seismogramm</strong> (<code>?action=seismogramm</code>) to pick a <strong>preset</strong> (Briefing, Blindspot, Research, or Monitor) that encodes selection strategy, context cap, and source mix. v0.9.2 deprecated the legacy <strong>Researcher</strong> checkbox matrix; v0.9.3 adds <strong>Monitor</strong> for watchlist cross-reference (who on your list was mentioned and what they said). The Preset Workbench drafts custom variants on any base mode.</p>

                    <h3 class="about-subheading">Presets</h3>
                    <div class="table-responsive">
                        <table class="styleguide-table">
                            <thead>
                                <tr>
                                    <th>Preset</th>
                                    <th>Purpose</th>
                                    <th>Selection behaviour</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong>Briefing</strong></td>
                                    <td>C-level executive summary from Magnitu highlights</td>
                                    <td>Standard pass on smaller pools; <strong>tournament</strong> (parallel batches + championship) when the pool exceeds ~80 items. Persona/goal can break Magnitu score ties.</td>
                                </tr>
                                <tr>
                                    <td><strong>Blindspot</strong></td>
                                    <td>Lex/Leg signals not yet echoed in media</td>
                                    <td><strong>Relational tournament</strong> on Lex+Leg only; global title fingerprint from Media/Feeds/Scraper; negative-space protocol rejects fuzzy media overlap. Persona required.</td>
                                </tr>
                                <tr>
                                    <td><strong>Research</strong></td>
                                    <td>Forensic topic search across a large haystack</td>
                                    <td>Disregards Magnitu scoring; Magnitu <strong>snippets</strong> on; pool up to 300 per request; <strong>newest-first</strong> cap ordering by default; tournament when pool exceeds batch size (~35).</td>
                                </tr>
                                <tr>
                                    <td><strong>Monitor</strong></td>
                                    <td>Watchlist cross-reference — who was mentioned and what they said</td>
                                    <td><strong>Standard</strong> selection on a regex-pre-filtered pool; mandatory watchlist verification in pass 1; explicit &ldquo;no hits&rdquo; report when nothing matches. Ships with the Swissmem directory as the default list; paste your own one-per-line watchlist anytime.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <h3 class="about-subheading">Decomposed pipeline</h3>
                    <p>Monolithic researcher logic was split into a thin controller, request context, and orchestrator with dedicated engines:</p>
                    <ul>
                        <li><code>SeismogrammRequestContext</code> &mdash; parses preset, modules, lookback, per-request context cap, and advanced flags (pool ordering, experimental context cache).</li>
                        <li><code>SeismogrammOrchestrator</code> &mdash; gathers entries, builds the global title fingerprint (Blindspot), routes to the correct selection engine, then runs pass 2.</li>
                        <li><code>StandardSelectionEngine</code> / <code>TournamentSelectionEngine</code> &mdash; pass 1 JSON key selection; relational mode drives Blindspot negative-space checks.</li>
                        <li><code>SummaryBriefingEngine</code> &mdash; pass 2 Markdown on a <strong>filtered XML subset</strong> (only <code>used_entry_keys</code>), cutting input tokens.</li>
                        <li><code>ResilientGeminiClient</code> &mdash; parallel cURL batches, structured response schemas, retries, and <strong>by_phase</strong> usage telemetry (<code>selection</code>, <code>summary</code>, <code>context_cache</code>).</li>
                    </ul>

                    <h3 class="about-subheading">GUI &amp; resilience</h3>
                    <ul>
                        <li>Preset bar with live mode intro; <strong>About</strong> panel documents each preset and a pipeline diagram.</li>
                        <li><strong>Default / Custom</strong> toggle: advanced settings (context cap slider, pool ordering, experimental context-cache checkbox) stay hidden until expanded.</li>
                        <li>Dynamic fields: persona for Briefing/Blindspot; research topic query for Research; watchlist textarea for Monitor (v0.9.3).</li>
                        <li>Referenced source cards parsed from the summary for validation.</li>
                        <li><strong>P1</strong> tournament batch recovery (one sequential retry per failed batch); <strong>P2</strong> user-initiated 429 retry with halved cap (Briefing/Blindspot/Monitor; Research fails fast); <strong>P3</strong> preset-aware loading steps and <code>SeismogrammPipelineMeta</code> cost/meta line.</li>
                        <li>Legacy <strong>Mem</strong> source checkbox removed from Seismogramm (v0.9.3); use Monitor instead. Dashboard timeline Mem filter unchanged. Blindspot uses a strict Lex/Leg pool (no silent fallback to the full timeline).</li>
                    </ul>
                    <p class="meta-text">Implementation detail: <code>docs/seismogramm.md</code>. In-app help: Seismogramm &rarr; <strong>About</strong>.</p>
                </section>

                <!-- VII. Operations & machine-readable exports -->
                <section class="settings-section about-card dashboard-section">
                    <h2>VII. Operations &amp; machine-readable exports</h2>
                    <div class="about-grid">
                        <div class="about-grid-item">
                            <strong>Refresh &amp; cron</strong>
                            <p>All paths use the same ingest pipeline (<code>RefreshAllService::runAll()</code>). How often each source actually hits its upstream differs — see <strong>§ IX</strong> below (including <strong>chunked</strong> RSS/scraper and the <strong>cron overlap lock</strong>).</p>
                        </div>
                        <div class="about-grid-item">
                            <strong>Retention</strong>
                            <p>Per-family policies prune old rows (defaults often around 180 days for feeds and mail; Lex and Leg may stay unlimited). Dry-run previews run before destructive deletes.</p>
                        </div>
                    </div>

                    <h3 class="about-subheading">Machine-readable exports (LLM &amp; automation)</h3>
                    <p>Downstream tools authenticate with a <strong>Bearer</strong> token sent as <code>Authorization: Bearer …</code> (or the documented query/body fallback). Use these for researchers, n8n, Raycast, or custom scripts:</p>
                    <ul>
                        <li><code>?action=seismogramm</code> &mdash; <strong>recommended</strong> preset briefing builder (Briefing / Blindspot / Research / Monitor); decomposed two-pass pipeline, referenced validation cards, per-phase cost telemetry. Includes the Preset Workbench for custom presets and context options. See <strong>§ VI</strong>.</li>
                        <li><code>?action=export_researcher</code> &mdash; Markdown digest for a time window; suited to LLM context and daily summaries.</li>
                        <li><code>?action=export_entries</code> &mdash; JSON export of entries with score metadata for pipelines that need structured rows.</li>
                    </ul>
                    <p class="meta-text"><strong>Security:</strong> the <code>export:api_key</code> in Settings is <strong>read-only</strong> for these actions. It must not reuse the Magnitu write <code>api_key</code>; the server rejects the write key on export routes by design.</p>
                </section>

                <!-- VIII. Operational health & row counts -->
                <section class="settings-section about-card dashboard-section">
                    <h2>VIII. Operational health &amp; row counts</h2>

                    <h3 class="about-subheading">System requirements</h3>
                    <ul>
                        <li><strong>PHP</strong> 8.2 or newer</li>
                        <li><strong>MariaDB</strong> or MySQL with <code>utf8mb4</code></li>
                        <li><strong>Extensions:</strong> <code>pdo_mysql</code> (required); <code>curl</code> recommended for Lex; <code>imap</code> if you use core mail fetch</li>
                        <li><strong>DE Lex corpus:</strong> <code>pdftotext</code> from <strong>poppler-utils</strong> on the mothership (<code>apt install poppler-utils</code>)</li>
                        <li>Timestamps are handled in <strong>UTC</strong> end-to-end</li>
                    </ul>

                    <h3 class="about-subheading">Reliability &amp; diagnostics</h3>
                    <p>Plugin throttles, last run status, and manual test-fetch live under <a href="<?= e($basePath) ?>/index.php?action=settings&amp;tab=diagnostics">Settings → Diagnostics</a>. Use that screen when a source stops updating or cron misbehaves.</p>

                    <h3 class="about-subheading">Live database snapshot</h3>
                    <?php if ($aboutStats !== null && $scoreCounts !== null): ?>
                    <div class="table-responsive">
                        <table class="styleguide-table">
                            <thead>
                                <tr>
                                    <th>Database Family</th>
                                    <th>Row Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td>Feed Definitions</td><td><?= e($fmt($aboutStats['feeds'])) ?></td></tr>
                                <tr><td>Timeline Items (Feeds)</td><td><?= e($fmt($aboutStats['feed_items'])) ?></td></tr>
                                <tr><td>Emails Ingested</td><td><?= e($fmt($aboutStats['emails'])) ?></td></tr>
                                <tr><td>Lex Items (Legislation)</td><td><?= e($fmt($aboutStats['lex_items'])) ?></td></tr>
                                <tr><td>Parliamentary Events</td><td><?= e($fmt($aboutStats['calendar_events'])) ?></td></tr>
                                <tr><td>Scraper Configurations</td><td><?= e($fmt($aboutStats['scraper_configs'])) ?></td></tr>
                                <tr class="stats-total-row">
                                    <td><strong>Total Scores</strong></td>
                                    <td><?= e($fmt($scoreCounts['total'])) ?> <span class="meta-text">(<?= e($fmt($scoreCounts['magnitu'])) ?> ML, <?= e($fmt($scoreCounts['recipe'])) ?> Recipe)</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <p class="meta-text">Database statistics are unavailable (connection or schema not ready).</p>
                    <?php endif; ?>
                </section>

                <!-- IX. Refresh overview -->
                <section class="settings-section about-card dashboard-section" id="about-refresh">
                    <h2>IX. Refresh: cron, timeline, and Diagnostics</h2>
                    <p class="about-lede">Everything passes through the same service, but <strong>throttles</strong>, <strong>force</strong>, and <strong>Lex</strong> handling differ. Use this table as the mental model.</p>

                    <?php if ($satellite): ?>
                    <p class="message message-info" style="margin-top: 1rem;"><strong>Satellite:</strong> Upstream fetching runs on the <strong>mothership</strong>. Local <code>refresh_cron.php</code> does not ingest feeds, Lex, or Leg from the network. The timeline <strong>Refresh</strong> button asks the mothership to run a refresh (same lighter scope as the mothership toolbar — Lex legislation plugins are not pulled by that path).</p>
                    <?php endif; ?>

                    <div class="table-responsive">
                        <table class="styleguide-table about-refresh-table">
                            <thead>
                                <tr>
                                    <th>How it runs</th>
                                    <th>Respects time gaps?</th>
                                    <th>Lex (Fedlex, EU, DE, FR, Jus, …)</th>
                                    <th>Typical use</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong>CLI <code>refresh_cron.php</code></strong><br><span class="meta-text">(e.g. Plesk cron; often every 5–60&nbsp;min)</span></td>
                                    <td><strong>Yes.</strong> Core fetchers (RSS, Parliament press, scraper, mail) and each plugin run only when its minimum interval has passed since the last successful run. A schedule such as <em>hourly</em> means the script runs every hour — not that every source hits its upstream every hour. <strong>RSS and scraper</strong> default to <strong>chunked</strong> batches per tick (with cursor state in <code>system_config</code>); only a <strong>completed cycle</strong> counts as the successful run for those throttles. The same MySQL advisory lock used here also protects <strong>manual</strong> refresh (timeline, Diagnostics, Feeds, Scraper), so a browser refresh cannot run chunked RSS/scraper in parallel with cron.</td>
                                    <td><strong>When due</strong> — same throttle rules as other plugins (intervals vary; see Diagnostics for windows).</td>
                                    <td>Hands-off background updates; combines with retention at the end of the script on the mothership.</td>
                                </tr>
                                <tr>
                                    <td><strong>Timeline Refresh</strong><br><span class="meta-text">(top bar on Index / Filter)</span></td>
                                    <td><strong>No</strong> — throttles are bypassed for the steps that run.</td>
                                    <td><strong>Skipped</strong> — keeps the browser request fast; legislation APIs can be heavy.</td>
                                    <td>Quick pull for feeds, press, scraper, mail, and Swiss parliament calendar (<code>parl_ch</code>) without waiting for cron.</td>
                                </tr>
                                <tr>
                                    <td><strong>Settings → Diagnostics → Refresh all</strong></td>
                                    <td><strong>No</strong> — full manual run.</td>
                                    <td><strong>Included</strong> — every enabled Lex plugin runs.</td>
                                    <td>When you need legislation or case law updated immediately, or you are checking a source after changing config.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="meta-text" style="margin-top: 1rem;"><strong>Feeds</strong>, <strong>Media</strong>, <strong>Scraper</strong>, and <strong>Mail</strong> each have a top-bar refresh (same corner as the timeline). <strong>Media</strong> refresh is scoped to <code>category = media</code> only. The <strong>Lex</strong> page lists per-plugin actions and &ldquo;Refresh all Lex sources.&rdquo;</p>
                    <p class="meta-text">Throttle numbers and last-run status: <a href="<?= e($basePath) ?>/index.php?action=settings&amp;tab=diagnostics">Settings → Diagnostics</a><?= $satellite ? ' (mothership only)' : '' ?>.</p>
                </section>

            <?php else: ?>
                <!-- ==================== TAB II: HISTORY & VERSIONING ==================== -->

                <section class="settings-section timeline-intro-card">
                    <p class="timeline-intro">
                        Seismo's development is driven by a distinct mission: **monitoring indirect regulatory pressure with complete self-hosted autonomy**. Over two years of active iterations, the codebase has evolved from a simple chronological aggregator into a high-performance, multi-tenant intelligence platform. 
                        Below is the story of how our architecture adapted to meet changing API realities, system safety bounds, and cognitive AI workflows.
                    </p>
                </section>

                <div class="detailed-timeline">
                    <!-- Era I -->
                    <div class="timeline-era-card">
                        <div class="era-meta-row">
                            <span class="era-badge era-badge-foundation">Era I: Micro-App Genesis</span>
                            <span class="era-date">Jan 2026</span>
                        </div>
                        <h3>v0.1 &amp; v0.1.1 &mdash; Standalone Fetchers &amp; Local Caching</h3>
                        
                        <div class="era-narrative">
                            <p><strong>Rationale:</strong> The project began as a set of separate utility scripts on PHP 8.2 to solve two immediate, isolated needs: parsing high-volume email newsletters on shared servers without heavy server-side parser configurations, and caching RSS/Atom feeds locally to keep page loads instant while protecting query intent.</p>
                        </div>

                        <div class="era-changes">
                            <h4>Key Milestones & Inventions</h4>
                            <ul>
                                <li><strong>v0.1 &mdash; `cronjob_mail` Ingest:</strong> Built a barebones, zero-dependency IMAP client (`fetch.php`) optimized for Plesk environments. It leveraged <code>zbateson/mail-mime-parser</code> (pure PHP, avoiding `ext-mailparse` constraints) to recursively traverse message structures and decode multi-part body charsets to UTF-8.</li>
                                <li><strong>v0.1.1 &mdash; SimplePie RSS Reader:</strong> A sibling utility built to aggregate standard feed formats, caching feed metadata and parsed items in simple <code>feeds</code> and <code>feed_items</code> MySQL tables. Feeds were cached for 1 hour by default (<code>CACHE_DURATION</code>).</li>
                                <li><strong>Black &amp; White Style:</strong> The visual language established in 0.1.1 was completely black-and-white, focusing purely on clean typography and reading layouts.</li>
                            </ul>
                        </div>

                        <div class="era-technical-depth">
                            <strong>Architectural Trade-offs:</strong>
                            <p>At this genesis stage, the fetchers were strictly standalone CLI scripts. Mail was pulled from IMAP port 993 via <code>imap_open()</code> with a fallback connection matrix to bypass SSL validation on older shared mail servers. Keeping the mail and RSS codebases completely isolated allowed fast initial validation, but required managing duplicate database helper functions across folders.</p>
                        </div>
                    </div>

                    <!-- Era II -->
                    <div class="timeline-era-card">
                        <div class="era-meta-row">
                            <span class="era-badge era-badge-powerhouse">Era II: Core Unification</span>
                            <span class="era-date">Feb 2026</span>
                        </div>
                        <h3>v0.2 &amp; v0.3 &mdash; Unifying into Seismo &amp; Live SPARQL Ingestion</h3>
                        
                        <div class="era-narrative">
                            <p><strong>Rationale:</strong> Rather than consulting separate views, cross-border desks needed a single unified workspace to monitor foreign legislation alongside active news. In v0.2, the standalone fetchers were merged under the name **Seismo**. In v0.3, the combined chronological stream was launched, and real-time semantic monitoring of EU and Swiss legislation was introduced via official public SPARQL endpoints.</p>
                        </div>

                        <div class="era-changes">
                            <h4>Key Milestones & Inventions</h4>
                            <ul>
                                <li><strong>v0.2 &mdash; Unified Codebase:</strong> Merged `cronjob_mail` and the RSS Reader, introducing a global search and category/tag-based filter engine across feed items.</li>
                                <li><strong>v0.3 &mdash; The Combined Feed:</strong> Replaced isolated tabs with a single chronological timeline of all sources.</li>
                                <li><strong>SPARQL Legislation Ingest (Lex):</strong> Integrated <code>EasyRdf</code> to query complex RDF graphs over HTTP:
                                    <ul>
                                        <li>🇪🇺 **EU CELLAR:** SPARQL query over the European Publications Office using the **Common Data Model (CDM)** ontology, pulling directives and regulations from the last 90 days.</li>
                                        <li>🇨🇭 **Fedlex:** SPARQL query over Swiss federal legislation using the **JOLux** ontology, tracking Bundesgesetze, Verordnungen, and treaties.</li>
                                    </ul>
                                </li>
                            </ul>
                        </div>

                        <div class="era-technical-depth">
                            <strong>Architectural Trade-offs:</strong>
                            <p>Executing live SPARQL queries directly over external web endpoints caused severe bottlenecking in browser requests. To circumvent page timeouts, the team scheduled the SPARQL queries as background cron updates, storing the RDF graph outputs in the unified database timeline and capping looked-back items to 90 days.</p>
                        </div>
                    </div>

                    <!-- Era III -->
                    <div class="timeline-era-card">
                        <div class="era-meta-row">
                            <span class="era-badge era-badge-usability">Era III: Ingest Expansion</span>
                            <span class="era-date">Feb – Mar 2026</span>
                        </div>
                        <h3>v0.4 &mdash; The Powerhouse Jurisdictions &amp; Magnitu REST</h3>
                        
                        <div class="era-narrative">
                            <p><strong>Rationale:</strong> Anticipating legal developments requires wider geographical footprints. The team added German and French legislation, Swiss case law, and parliamentary proceedings. With entry ingestion volumes exceeding manual reading capacity, we launched the **Magnitu Python companion app** for relevance scoring, along with the first multi-profile "Satellite Mode."</p>
                        </div>

                        <div class="era-changes">
                            <h4>Key Milestones & Inventions</h4>
                            <ul>
                                <li><strong>German recht.bund.de RSS:</strong> Aggregated BGBl RSS updates. Integrated a **cURL cookie jar** to handlerecht.bund's load-balancer session cookies, mapping custom namespaces for structured laws metadata.</li>
                                <li><strong>French Légifrance (PISTE):</strong> Implemented the PISTE API using authenticated OAuth2 token exchanges to fetch Journal Officiel acts.</li>
                                <li><strong>Swiss Case Law (Jus):</strong> Added incremental, manifest-based synchronization of BGer, BGE, and BVGer decisions from entscheidsuche.ch.</li>
                                <li><strong>OData &amp; SharePoint:</strong> Integrated Swiss parliamentary proceedings (Leg) via OData and press releases (Parl MM) via SharePoint.</li>
                                <li><strong>Custom Web Scraper:</strong> Standalone `seismo_scraper.php` script featuring CSS selectors-to-XPath conversions for date parsing and DOMDocument-based readability heuristics (extracting core content from `<article>`, `<main>`).</li>
                                <li><strong>Magnitu v3 Integration:</strong> Pushed entry records via API to a Python companion model, receiving relevance scores back through bearer token endpoints (`magnitu_scores`).</li>
                                <li><strong>SEISMO_MOTHERSHIP_DB:</strong> The first multi-profile Satellite architecture. Multiple satellite instances shared the mothership's entries database while maintaining isolated `entry_scores` tables for customized topic models.</li>
                            </ul>
                        </div>

                        <div class="era-technical-depth">
                            <strong>Architectural Trade-offs:</strong>
                            <p>To keep the background scraper cron script from triggering server security blocks, we introduced browser-like headers, desktop Chrome User-Agents, and randomized 1-3 second delays between article pages, trading fetching speed for operational stealth.</p>
                        </div>
                    </div>

                    <!-- Era IV -->
                    <div class="timeline-era-card">
                        <div class="era-meta-row">
                            <span class="era-badge era-badge-consolidation">Era IV: Ingest Safety</span>
                            <span class="era-date">Apr – May 2026</span>
                        </div>
                        <h3>v0.5.1 – v0.5.3 &mdash; Chunked Ingestion &amp; soft-scoring Attractors</h3>
                        
                        <div class="era-narrative">
                            <p><strong>Rationale:</strong> High-volume sources frequently hit PHP max execution budgets, while procedural controllers became unmaintainable. The team restructured Seismo into a robust MVC repository architecture. We solved background job crashes by introducing **chunked ingestion cursors**, added database advisory locks, and recalibrated the recipe scoring formula.</p>
                        </div>

                        <div class="era-changes">
                            <h4>Key Milestones & Inventions</h4>
                            <ul>
                                <li><strong>Architectural Refactoring:</strong> Replaced procedural controllers with class-based routing, repository patterns to isolate raw SQL statements, and secure bearer export endpoints (`export_researcher`, `export_entries`).</li>
                                <li><strong>Advisory Cron Lock:</strong> Leveraged MySQL/MariaDB advisory locks (<code>GET_LOCK</code> / <code>RELEASE_LOCK</code> via <code>CronMutexRepository</code>) to prevent overlapping cron jobs from executing in parallel.</li>
                                <li><strong>Chunked Cursors (v0.5.3):</strong> Chunked RSS and Scraper refreshes. A single tick fetches a subset of feeds, storing progress keys (<code>refresh_chunk:*</code>) in <code>system_config</code>, while a completed rotation triggers the module’s throttle window.</li>
                                <li><strong>Recipe Attractor Calibration:</strong> Mitigated a severe "48–52 uniform softmax attractor" in `RecipeScorer` where conflicting n-gram signals flattened scores:
                                    <ul>
                                        <li>Rolled back matched token window (<code>MAX_NGRAM</code>: 5 → 3) to exclude formatting noise.</li>
                                        <li>Lowered timeline alert thresholds (<code>alert_threshold</code>: 0.75 → 0.60) to surface multi-anchor entries.</li>
                                        <li>Restored seeded weights to critical editorial anchors (e.g. <code>member states only</code>, <code>third country</code>) which were previously clipped by the export caps.</li>
                                    </ul>
                                </li>
                            </ul>
                        </div>

                        <div class="era-technical-depth">
                            <strong>Architectural Trade-offs:</strong>
                            <p>Chunked loops required constant, atomic updates to cursor states in `system_config`. On highly active desks, this increased write IOPS, but successfully eliminated standard shared-hosting Gateway Timeout failures.</p>
                        </div>
                    </div>

                    <!-- Era V -->
                    <div class="timeline-era-card">
                        <div class="era-meta-row">
                            <span class="era-badge era-badge-satellites">Era V: Shared Desking</span>
                            <span class="era-date">May 2026</span>
                        </div>
                        <h3>v0.6.0 – v0.6.7 &mdash; Single-Codebase Satellites &amp; Full-Text Corpora</h3>
                        
                        <div class="era-narrative">
                            <p><strong>Rationale:</strong> The previous satellite mode required duplicate codebase trees on disk. In v0.6.2, we replaced it with codebase-shared Satellites (slug subfolders pointing to the central front controller). Furthermore, downstream ML (Magnitu) and LLM contexts required access to full law bodies and full news articles rather than short descriptions, sparking the creation of our text-extraction and PDF extraction pipelines.</p>
                        </div>

                        <div class="era-changes">
                            <h4>Key Milestones & Inventions</h4>
                            <ul>
                                <li><strong>Single-Codebase Satellites:</strong> Virtual desks served at <code>/&lt;slug&gt;/</code>. The shell script <code>seismo-satellite-provision.sh</code> automates the creation of isolated score tables and index stubs under one folder, allowing a single <code>git pull</code> to upgrade the entire system.</li>
                                <li><strong>LONGTEXT Content Storage:</strong> Created <code>lex_items.content</code> to hold full law texts, separating lightweight timeline query reads from heavy text blocks.</li>
                                <li><strong>PDF extraction (recht.bund):</strong> Added `LexRechtBundContentFetcher` which downloads rule PDFs and extracts plain text using <code>pdftotext</code> (poppler-utils).</li>
                                <li><strong>Full-text Hydration (Media):</strong> News RSS items are hydrated by fetching full-text publisher pages via `RssArticleHydrator` + `ArticlePageBodyExtractor`, resolving Google News redirect URLs in the background.</li>
                                <li><strong>Gmail API:</strong> Replaced IMAP with official Google OAuth2 authentication flow.</li>
                            </ul>
                        </div>

                        <div class="era-technical-depth">
                            <strong>Architectural Trade-offs:</strong>
                            <p>Running satellites on a single VPS codebase required separate cross-DB grants (the app user must have `SELECT` on `seismo.*`, and `ALL` on `seismo_slug.*`). Standard migrations are applied to `seismo`, while `migrate.php --scores-db=seismo_<slug>` updates local tables individually, ensuring schema consistency across multi-tenant scopes.</p>
                        </div>
                    </div>

                    <!-- Era VI -->
                    <div class="timeline-era-card">
                        <div class="era-meta-row">
                            <span class="era-badge era-badge-ai">Era VI: Cognitive AI Genesis</span>
                            <span class="era-date">May 2026</span>
                        </div>
                        <h3>v0.7.0 – v0.7.8 &mdash; The Gemini Ingestion &amp; Prompt Workspace</h3>
                        
                        <div class="era-narrative">
                            <p><strong>Rationale:</strong> With high item volumes, manual summarization became a key bottleneck. The team designed the **AI Researcher**, integrating Gemini LLM capability natively. To manage ingestion quality, we introduced the **Gemini Configurator** setup assistant, generating regular expressions and webview extraction keywords dynamically with a live preview sandbox, resulting in a zero-runtime external AI footprint during normal cron runs.</p>
                        </div>
 
                        <div class="era-changes">
                            <h4>Key Milestones & Inventions</h4>
                            <ul>
                                <li><strong>v0.7.8 &mdash; Gemini Configurator:</strong> Interactive, tabbed configuration tool analyzing sample email texts, extracting regular expressions and webview selectors, and storing settings locally to completely prevent external runtime API calls during background cron.</li>
                                <li><strong>v0.7.5 &mdash; Skinny Two-Pass Gemini 3.5 Pipeline:</strong> A two-step pipeline using `gemini-3.5-flash` where Pass 1 selects key entries (producing pure selection tracking JSON) and Pass 2 drafts the markdown briefing on chosen items, optimizing output reliability.</li>
                                <li><strong>v0.7.4 &mdash; Bilingual Mail Hydration:</strong> Multi-locale newsletter body hydration, parsing the best-matched German or English edition based on user inbox languages.</li>
                                <li><strong>v0.7.3 &mdash; Custom prompt libraries:</strong> Added dynamic tabs to save, manage, and load named prompt templates directly in the Researcher dashboard context.</li>
                                <li><strong>v0.7.2 &mdash; Lenient JSON Repair:</strong> Integrated `LenientJsonParser` to clean up and recover broken JSON fences or missing closing elements from LLM outputs.</li>
                                <li><strong>v0.7.1 &mdash; Attribution Grounding:</strong> Referenced source entries dynamically rendering as actual dashboard cards at the bottom of generated summaries.</li>
                            </ul>
                        </div>
 
                        <div class="era-technical-depth">
                            <strong>Architectural Trade-offs:</strong>
                            <p>Using large language models over extensive legislative lists risked hitting context and token rate limitations. We introduced `ResearcherModuleGuard` to dynamically compute and scale context boundaries, restricting entry bodies to between 2k and 12k characters depending on the pool scale.</p>
                        </div>
                    </div>

                    <!-- Era VII: Brutalist Styling & Newsletter Desk Split -->
                    <div class="timeline-era-card">
                        <div class="era-meta-row">
                            <span class="era-badge era-badge-design">Era VII: Brutalist Styling &amp; Newsletter split</span>
                            <span class="era-date">May – June 2026</span>
                        </div>
                        <h3>v0.8.0 &ndash; v0.8.7 &mdash; New Styling, Newsletter Module, Story-Level Digests &amp; Backups</h3>
                        
                        <div class="era-narrative">
                            <p><strong>Rationale:</strong> The 0.8 series focused on visual refinement and advanced email integration. The dashboard transitioned to a premium high-contrast Brutalist theme with Outfit typography to improve scanning efficiency. Concurrently, high-volume email newsletters were split out from standard mail into a specialized Newsletter module, complete with AI-powered multi-story digest splitting and backups.</p>
                        </div>
 
                        <div class="era-changes">
                            <h4>Key Milestones & Inventions</h4>
                            <ul>
                                <li><strong>v0.8.0 &mdash; New Styling &amp; Typography:</strong> Global rollout of the geometric Outfit font stack paired with high-contrast, black-bordered Brutalist containers, sharp 90-degree interactive controls, and 3D hover actions.</li>
                                <li><strong>v0.8.3 &mdash; Newsletter Module:</strong> Split IMAP/Gmail digests from normal alerts into <code>?action=newsletter</code> using <code>email_subscriptions.module_scope</code> (migration 027) with custom peach timeline pills.</li>
                                <li><strong>v0.8.4 &mdash; AI Digest Story Splitter:</strong> Fan-out of multi-story digests into distinct child <code>emails</code> rows (migration 028), using Gemini to analyze layout structure and preview story cards.</li>
                                <li><strong>v0.8.5 &mdash; Subject-based multi-newsletter routing:</strong> Relaxed subscription sender constraints to support multiple distinct digest configurations from a single address via subject line patterns.</li>
                                <li><strong>v0.8.6 &mdash; Auto-Linkified timeline cards:</strong> Client-side formatting upgrades segmenting double-newlines into paragraphs and linkifying URL strings without altering underlying database records.</li>
                                <li><strong>v0.8.7 &mdash; Backup settings panel:</strong> New Backup tab centralizing source JSON config exports/imports and memory-safe database SQL streaming.</li>
                            </ul>
                        </div>
 
                        <div class="era-technical-depth">
                            <strong>Architectural Trade-offs:</strong>
                            <p>Story-level splitting required hiding parent scores and rows on Highlights and export lists while keeping them grouped in the Mail/Newsletter admin screens. Styling custom layouts manually bypassed third-party CSS dependencies but required mapping CSS custom property variables cleanly to ensure satellites load without asset conflicts.</p>
                        </div>
                    </div>

                    <!-- Era VIII: Ingestion Modernization & Relational Migration -->
                    <div class="timeline-era-card">
                        <div class="era-meta-row">
                            <span class="era-badge era-badge-powerhouse">Era VIII: Ingestion Modernization</span>
                            <span class="era-date">June 2026</span>
                        </div>
                        <h3>v0.9.0 &mdash; Modern Ingestion Libraries, Relational Schemas, &amp; Throttle Bypass</h3>

                        <div class="era-narrative">
                            <p><strong>Rationale:</strong> Maintaining custom HTML sanitizers, parser helpers, and JSON split configurations created operational complexity. In v0.9.0, Seismo's parsing was refactored onto standard Symfony and Masterminds components, config maps were fully normalized into relational schemas, and manual refresh controls were equipped with throttle bypass options.</p>
                        </div>

                        <div class="era-changes">
                            <h4>Key Milestones & Inventions</h4>
                            <ul>
                                <li><strong>v0.9.0 &mdash; Standard Library Transition:</strong> Integrated <code>zbateson/mail-mime-parser</code>, <code>masterminds/html5</code>, and <code>symfony/html-sanitizer</code> to replace legacy manual parsers.</li>
                                <li><strong>v0.9.0 &mdash; Normalized Relational Schema:</strong> Migrated newsletter templates and split rules into fully normalized SQL database relationships.</li>
                                <li><strong>v0.9.0 &mdash; Manual Throttle Bypass:</strong> Enabled explicit "Refresh" button requests to execute immediately, ignoring background cron guard window limits.</li>
                            </ul>
                        </div>

                        <div class="era-technical-depth">
                            <strong>Architectural Trade-offs:</strong>
                            <p>Migrating JSON split rules into strict foreign key relations required detailed database migration passes, but eliminated parser configuration mismatch errors.</p>
                        </div>
                    </div>

                    <!-- Era IX: Seismogramm Rebuild & Researcher Deprecation -->
                    <div class="timeline-era-card current-version-card">
                        <div class="era-meta-row">
                            <span class="era-badge era-badge-ai">Era IX: Seismogramm &mdash; Gemini Pipeline Refactor</span>
                            <span class="era-date">June 2026 (Current)</span>
                        </div>
                        <h3>v0.9.3 &mdash; Monitor Preset &amp; Watchlist Cross-Reference</h3>

                        <div class="era-narrative">
                            <p><strong>Rationale:</strong> Swissmem-style entity monitoring lived behind a dedicated <strong>Mem</strong> source toggle and a legacy Researcher prompt. v0.9.3 generalises that workflow as the fourth Seismogramm preset: operators supply a watchlist, the pool is pre-filtered with <code>WatchlistMatcher</code>, Gemini verifies mentions in two passes, and the report states clearly when nobody on the list appears — without inventing hits.</p>
                        </div>

                        <div class="era-changes">
                            <h4>Key Milestones & Inventions</h4>
                            <ul>
                                <li><strong>v0.9.3 &mdash; Monitor preset:</strong> Built-in fourth default alongside Briefing, Blindspot, and Research; Swissmem directory preloaded; custom monitor desks via Workbench (Monitor base mode).</li>
                                <li><strong>v0.9.3 &mdash; WatchlistMatcher:</strong> Generalised person/entity list parser and regex gate (ported from Swissmem); acronym and false-positive rules retained.</li>
                                <li><strong>v0.9.3 &mdash; Mem checkbox removed:</strong> Seismogramm and legacy Researcher no longer expose a Mem module pill; Monitor replaces that path.</li>
                                <li><strong>v0.9.2 &mdash; Legacy Researcher Deprecation:</strong> Removed legacy researcher routes, views, controllers, and navigation menu links.</li>
                                <li><strong>v0.9.2 &mdash; Preset Workbench Knobs:</strong> Brought full configuration sliders, Magnitu snippets toggles, and Context Cache controls directly to the Seismogramm builder page.</li>
                                <li><strong>v0.9.2 &mdash; Mode-Styled Prompt Playground:</strong> Prompt generator creates adapted instructions styled on top of selected base modes (Briefing / Research / Blindspot; Monitor added in v0.9.3).</li>
                                <li><strong>v0.9.2 &mdash; Custom Preset Management:</strong> Standard configurations are protected, while custom user presets can be named, saved, and individually deleted using interactive tag controls.</li>
                                <li><strong>v0.9.1 &mdash; Preset profiles:</strong> <code>SeismogrammPresetProfile</code> manages gathering rules and tournament thresholds (~80 Briefing, ~35 Research batches) without exposing engine settings to users.</li>
                                <li><strong>v0.9.1 &mdash; Decomposed Pipeline Engines:</strong> Segregated logic into <code>SeismogrammOrchestrator</code>, <code>StandardSelectionEngine</code>, <code>TournamentSelectionEngine</code>, <code>SummaryBriefingEngine</code>, and <code>ResilientGeminiClient</code>.</li>
                            </ul>
                        </div>

                        <div class="era-technical-depth">
                            <strong>Architectural Trade-offs:</strong>
                            <p>Removing the legacy code blocks simplified the codebase but required migrating any active custom default researcher prompts to the Seismogramm system-config model structures. Fully separating preset deletions prevents unintended loss of standard system configurations.</p>
                        </div>
                    </div>
                </div>
                </div>

                <!-- Versioning Proposal Section -->
                <section class="settings-section about-card proposal-section">
                    <h2>VII. Proposal: Transitioning to Semantic Versioning (SemVer)</h2>
                    
                    <p class="about-lede">
                        Seismo's release history has historically been feature-driven. While this served us well during initial development, as a production-grade monitoring dashboard with an active desking (satellite) registry and third-party API exports, it is time to transition to a strict **Semantic Versioning 2.0.0 (SemVer)** standard.
                    </p>

                    <div class="proposal-details">
                        <div class="proposal-block">
                            <h3>1. The Problem with Feature-Based Versioning</h3>
                            <p>
                                Bumping minor versions (e.g. `0.6.0` to `0.7.0`) purely because a new module is introduced masks breaking API changes or database migrations. Developers and system administrators deploying Seismo to Hetzner VPS cannot easily distinguish between a harmless visual update and a critical database schema change that requires running remote schema migrations on satellite databases.
                            </p>
                        </div>

                        <div class="proposal-block">
                            <h3>2. The SemVer 2.0.0 Blueprint for Seismo</h3>
                            <p>We propose adopting a standard <code>MAJOR.MINOR.PATCH</code> format with strict boundaries mapped to Seismo's architecture:</p>
                            
                            <table class="styleguide-table">
                                <thead>
                                    <tr>
                                        <th>Version Bump</th>
                                        <th>Triggers in Seismo</th>
                                        <th>Impact &amp; Deployment Action Required</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong>MAJOR (Breaking)</strong></td>
                                        <td>
                                            - Database migrations that modify table column names or drop existing columns.<br>
                                            - Changes to the HTTP API contract (e.g., modifying JSON payload schemas for <code>magnitu_*</code> or <code>export_*</code> endpoints).<br>
                                            - Bumping the minimum PHP requirement (e.g. PHP 8.2 to PHP 8.5) or adding mandatory system binaries (like poppler-utils/pdftotext).
                                        </td>
                                        <td>
                                            <strong>High.</strong> Administrator intervention is required. Satellite databases must be migrated immediately. Downtime may occur.
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>MINOR (Features)</strong></td>
                                        <td>
                                            - Adding backward-compatible new fetchers (e.g. EUR-Lex or a new parliament SharePoint OData scraper).<br>
                                            - Adding a new mail subscription body processor or scrapers.<br>
                                            - Adding a new settings panel tab, prompt library helper, or timeline filter.
                                        </td>
                                        <td>
                                            <strong>Medium.</strong> Safe to deploy in place. Database changes are additive-only and backward-compatible.
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>PATCH (Bug Fixes)</strong></td>
                                        <td>
                                            - Bug fixes in the ingestion scripts (e.g., repairing parsing errors in LenientJsonParser).<br>
                                            - Visual fixes and CSS styles adjustment.<br>
                                            - Security patch updates or rapid click CSRF lockouts adjustments.
                                        </td>
                                        <td>
                                            <strong>Low.</strong> Zero-risk rolling update.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="proposal-block">
                            <h3>3. Retrospective Analysis: Mapping Past Releases to SemVer</h3>
                            <p>If we apply strict SemVer retroactively, several historical minor releases would have been major version bumps:</p>
                            <ul>
                                <li><strong>v0.5.1 (Consolidation):</strong> This should have been a **MAJOR** bump (e.g., `1.0.0`) because it refactored procedural code into MVC classes, breaking custom scraper cron tasks and completely retiring the old visual "AI view" API route.</li>
                                <li><strong>v0.6.0 (VPS Baseline):</strong> This should have been a **MAJOR** bump because it replaced split IMAP/Gmail storage with a unified `emails` database schema, requiring immediate schema migrations.</li>
                                <li><strong>v0.6.2 (Satellites):</strong> A **MAJOR** bump due to the introduction of local database configurations and cross-DB SQL grants.</li>
                                <li><strong>v0.6.4 (Full-Text Storage):</strong> A **MINOR** bump as the LONGTEXT `lex_items.content` was additive and backward-compatible.</li>
                                <li><strong>v0.7.0 (Researcher):</strong> A **MINOR** feature release. However, the subsequent JSON repair and retry fixes (`v0.7.2`, `v0.7.3`) would be correct **PATCH** increments under SemVer.</li>
                            </ul>
                        </div>

                        <div class="proposal-block">
                            <h3>4. Roadmap to v1.0.0 Stable</h3>
                            <p>
                                Currently, Seismo is on version <strong>0.9.3</strong>, signifying it is in active pre-1.0.0 bootstrapping. The Seismogramm Gemini pipeline (four presets including Monitor, decomposed orchestrator, resilience telemetry) is the latest milestone alongside v0.9.0 ingestion modernization; we recommend freezing the 0.x line once Seismogramm and relational mail routing complete a 30-day production trial alongside path satellites.
                            </p>
                            <p>
                                The transition to <strong>v1.0.0</strong> will signal a frozen, production-grade core API. From that point forward, all changes will strictly follow the SemVer blueprint, safeguarding integrations and ensuring reliable multi-desk satellite deployments.
                            </p>
                        </div>
                    </div>
                </section>

            <?php endif; ?>

            <footer class="about-footer">
                <p>Built with precision by <a href="https://hektopascal.org" target="_blank" rel="noopener">hektopascal.org</a>.</p>
                <p class="meta-text about-meta">Dev Detail: See <code>README-REORG.md</code> for internal architectural migration notes.</p>
            </footer>
        </main>
    </div>

</body>
</html>
