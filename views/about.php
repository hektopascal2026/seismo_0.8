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
    <title>About | <?= e(seismoBrandTitle()) ?></title>
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

        <main class="settings-container about-modern-layout">
            <header class="settings-header about-hero">
                <h1>Seismo <?= e($seismoVersion) ?> &mdash; dashboard</h1>
                <p class="subtitle">Legislative and media monitoring &mdash; unified timeline, scoring, and exports</p>
            </header>

            <!-- I. Overview -->
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
                                    <strong>Mail:</strong> IMAP/Gmail ingest with domain-first subscriptions; optional body processors for digest senders; <strong>View in browser →</strong> when a webview link is present.<br>
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

            <!-- VI. Version history -->
            <section class="settings-section about-card dashboard-section">
                <h2>VI. Version history</h2>
                <div class="about-timeline">
                    <div class="about-timeline-entry">
                        <div class="v-header"><strong>v0.1 – v0.3</strong> <span class="v-date">Jan – Feb 2026</span></div>
                        <div class="v-title">The Foundation</div>
                        <ul>
                            <li>Established the "Unified Feed" concept aggregating RSS and IMAP.</li>
                            <li>Initial SPARQL integration for Swiss (Fedlex) and EU (EUR-Lex) legislation.</li>
                            <li>Development of the core database schema for high-volume entry ingestion.</li>
                        </ul>
                    </div>
                    <div class="about-timeline-entry">
                        <div class="v-header"><strong>v0.4</strong> <span class="v-date">Feb – Mar 2026</span></div>
                        <div class="v-title">The Powerhouse Update</div>
                        <ul>
                            <li>Expanded geographic coverage to include Germany (recht.bund.de) and France (Légifrance).</li>
                            <li>Introduced Swiss case law (Jus) and the Parliament OData API.</li>
                            <li>Launch of the first Magnitu machine-learning integration.</li>
                            <li>Transitioned to a tabbed settings interface for complex configurations.</li>
                        </ul>
                    </div>
                    <div class="about-timeline-entry">
                        <div class="v-header"><strong>v0.4.3 – v0.4.4</strong> <span class="v-date">Apr 2026</span></div>
                        <div class="v-title">UX & Refinement</div>
                        <ul>
                            <li>Refactored email handling: senders became first-class subscriptions with domain-first matching.</li>
                            <li>Decentralized source management into dedicated screens (Feeds, Mail, Lex).</li>
                            <li>Improved timeline performance for large datasets (>100k rows).</li>
                        </ul>
                    </div>
                    <div class="about-timeline-entry current-version">
                        <div class="v-header"><strong>v0.7.7 (Current)</strong> <span class="v-date">May 2026</span></div>
                        <div class="v-title">Swiss Fedlex: richer cards &amp; full text</div>
                        <ul>
                            <li><strong>Cards:</strong> Beschlossen / Inkrafttreten dates and <strong>&Auml;nderung</strong> signal from Fedlex SPARQL (<code>dateDocument</code>, <code>dateEntryInForce</code>).</li>
                            <li><strong>Corpus:</strong> OC acts fetch Akoma Ntoso XML from Fedlex filestore (not portal HTML); briefing and Magnitu use <code>lex_items.content</code>.</li>
                            <li><strong>Backfill:</strong> <code>php bin/lex-backfill-content.php --ch</code> (consultations: <code>--ch-promote</code>).</li>
                            <li><strong>Timeline:</strong> date-only lex items sort on publication day with ingestion time.</li>
                        </ul>
                    </div>
                    <div class="about-timeline-entry">
                        <div class="v-header"><strong>v0.7.6</strong> <span class="v-date">May 2026</span></div>
                        <div class="v-title">Briefing prompt helper</div>
                        <ul>
                            <li><strong>View: Prompt | Helper</strong> on <code>?action=briefing_builder</code> &mdash; rough notes in Helper, <strong>Generate prompt</strong> via <code>briefing_prompt_helper</code> (Gemini, style from desk default).</li>
                            <li><strong>Review &amp; save:</strong> edit the draft, then <strong>Save prompt (default)</strong> or <strong>Save to library</strong> (same as the Prompt editor; saving syncs the Prompt textarea).</li>
                            <li><strong>Generate briefing</strong> still reads only the Prompt view <code>system_prompt</code> field.</li>
                        </ul>
                    </div>
                    <div class="about-timeline-entry">
                        <div class="v-header"><strong>v0.7.5</strong> <span class="v-date">May 2026</span></div>
                        <div class="v-title">AI Briefing: Gemini 3.5 two-pass pipeline</div>
                        <ul>
                            <li><strong>Model:</strong> <code>gemini-3.5-flash</code> only; unsupported <code>gemini:model</code> values are coerced to the default.</li>
                            <li><strong>Two-pass:</strong> pass 1 applies the full USER PROMPT to global JSON selection (<code>used_entry_keys</code>, optional <code>selection_reasoning</code>); pass 2 writes plain Markdown on selected rows only.</li>
                            <li><strong>Context:</strong> dynamic per-entry body budget (2k&ndash;12k), stratified module cap, <code>BriefingModuleGuard</code>, Europe/Zurich date anchor, 32k system prompt limit.</li>
                            <li><strong>Reliability:</strong> <code>thinkingLevel</code> LOW; HTTP 429 retry uses batched selection; pass 2 bans conversational filler.</li>
                        </ul>
                    </div>
                    <div class="about-timeline-entry">
                        <div class="v-header"><strong>v0.7.4</strong> <span class="v-date">May 2026</span></div>
                        <div class="v-title">Mail: locale web views &amp; hosted hydration</div>
                        <ul>
                            <li><strong>Locale loop (all senders):</strong> guesses inbox language; prefers labelled DE/EN/FR edition links over generic &ldquo;cannot read this email&rdquo; webview URLs. German inbox → German edition; non-English → German then English; English → English then German.</li>
                            <li><strong>Hosted body:</strong> when a German or English edition link is chosen, ingest and <strong>Reprocess stored mail</strong> fetch that page (scraper-style HTTP + Readability, newsletter HTML fallback) and store plain text in <code>text_body</code> for timeline, scoring, and briefings.</li>
                            <li><strong>Metadata:</strong> <code>web_view_url</code>, <code>web_view_locale</code> (<code>de</code>/<code>en</code>), <code>body_source</code> = <code>web_view</code> on <code>emails.metadata</code>. Fetch failures keep the inbox body.</li>
                        </ul>
                    </div>
                    <div class="about-timeline-entry">
                        <div class="v-header"><strong>v0.7.3</strong> <span class="v-date">May 2026</span></div>
                        <div class="v-title">Briefing prompt library</div>
                        <ul>
                            <li><strong>Named prompts:</strong> tabs above the system prompt on <code>?action=briefing_builder</code>; click a tab to load it, <strong>Save to library</strong> to add the current textarea under a custom name, <strong>&times;</strong> to delete.</li>
                            <li><strong>Per desk:</strong> library in <code>system_config</code> key <code>ai_briefing_prompts</code> (mothership and each path satellite). First visit seeds a <strong>Default</strong> entry from that desk&rsquo;s current prompt.</li>
                            <li><strong>Default prompt:</strong> <strong>Save prompt (default)</strong> still writes <code>briefing:system_prompt</code> for the instance reload default (separate from the library).</li>
                        </ul>
                    </div>
                    <div class="about-timeline-entry">
                        <div class="v-header"><strong>v0.7.2</strong> <span class="v-date">May 2026</span></div>
                        <div class="v-title">Briefing JSON repair &amp; clearer errors</div>
                        <ul>
                            <li><strong>LenientJsonParser:</strong> multi-layer repair for Gemini JSON (fences, balanced braces, trailing commas) ported from tourdesuisse; fixes many &ldquo;Syntax error&rdquo; responses.</li>
                            <li><strong>Degraded mode:</strong> if attribution JSON cannot be parsed, the executive briefing still appears; source cards are omitted with a warning (no &ldquo;show all entries&rdquo; fallback).</li>
                            <li><strong>UI:</strong> Gemini failures show the specific reason in the Summary box, not only &ldquo;check the server log.&rdquo;</li>
                        </ul>
                    </div>
                    <div class="about-timeline-entry">
                        <div class="v-header"><strong>v0.7.1</strong> <span class="v-date">May 2026</span></div>
                        <div class="v-title">AI Briefing: attribution cards</div>
                        <ul>
                            <li><strong>Grounding:</strong> Gemini returns <code>used_entry_keys</code>; the Briefing page shows only the matching timeline cards under <strong>Referenced source entries</strong> (citation order).</li>
                            <li><strong>Context IDs:</strong> each entry in the LLM payload is tagged <code>[ID: entry_type:entry_id]</code> so citations map back to Seismo rows.</li>
                            <li><strong>Fallbacks:</strong> warnings when the model omits IDs or cites unknown keys.</li>
                        </ul>
                    </div>
                    <div class="about-timeline-entry">
                        <div class="v-header"><strong>v0.7.0</strong> <span class="v-date">May 2026</span></div>
                        <div class="v-title">AI Briefing Builder (Gemini)</div>
                        <ul>
                            <li><strong>Page:</strong> <code>?action=briefing_builder</code> — nav link after Highlights (mothership and path satellites; satellites filter/sort by local Magnitu scores).</li>
                            <li><strong>Pipeline:</strong> <code>BriefingEntryGatherer</code> + Magnitu labels, markdown context (up to 2000 chars of body per entry), two-pass <code>GeminiBriefingService</code> (JSON selection, plain Markdown summary).</li>
                            <li><strong>Filters:</strong> six module toggles (Feeds, Media, Scraper, Mail, Lex, Leg), lookback window, per-module cap; default <strong>investigation lead</strong> (+ optional important).</li>
                            <li><strong>Config:</strong> <code>gemini:api_key</code> in Settings → General. See <code>docs/ai-briefing-builder.md</code>.</li>
                        </ul>
                    </div>
                    <div class="about-timeline-entry">
                        <div class="v-header"><strong>v0.6.7</strong> <span class="v-date">May 2026</span></div>
                        <div class="v-title">Filter page revamp</div>
                        <ul>
                            <li><strong>Unified UX:</strong> <code>?action=filter</code> uses the same entry-card layout and square source pills as the main timeline.</li>
                            <li><strong>Workflow:</strong> pick modules and sources, then preview the filtered stream before opening it on the dashboard.</li>
                        </ul>
                    </div>
                    <div class="about-timeline-entry">
                        <div class="v-header"><strong>v0.6.6</strong> <span class="v-date">May 2026</span></div>
                        <div class="v-title">Media module &amp; RSS full-text hydration</div>
                        <ul>
                            <li><strong>Media admin:</strong> <code>?action=media</code> for news sources (<code>feeds.category = media</code>), partitioned from Feeds; timeline pills show your source <strong>Title</strong>, not the routing category.</li>
                            <li><strong>Thin RSS:</strong> per-feed <strong>Extract full text</strong> (schema migration 024) — after RSS parse, up to 10 publisher pages per refresh via <code>RssArticleHydrator</code>.</li>
                            <li><strong>Extraction:</strong> <code>ArticlePageBodyExtractor</code> picks the longest of JSON-LD <code>articleBody</code>, Readability, and meta descriptions; <code>GoogleNewsArticleUrlResolver</code> unwraps Google News RSS links before fetch.</li>
                            <li><strong>Refresh:</strong> <strong>Refresh Media</strong> runs only media-category RSS and scrapers; preview hydrates when the checkbox is on. See <code>docs/media-module.md</code> and <code>docs/rss-hydration.md</code>.</li>
                        </ul>
                    </div>
                    <div class="about-timeline-entry">
                        <div class="v-header"><strong>v0.6.5</strong> <span class="v-date">May 2026</span></div>
                        <div class="v-title">Germany: BGBl PDF corpus extraction</div>
                        <ul>
                            <li><strong>Problem:</strong> recht.bund.de RSS lists title and ELI link only — no law body in the feed.</li>
                            <li><strong>Solution:</strong> <code>LexRechtBundContentFetcher</code> resolves each publication URL to <code>regelungstext.pdf</code>, downloads it, and extracts plain text with <code>pdftotext</code> (poppler-utils).</li>
                            <li><strong>Ingest:</strong> Lex refresh fetches PDF corpus for new DE rows (<code>content_fetch_limit</code>); backfill via <code>php bin/lex-backfill-content.php --de</code>.</li>
                            <li><strong>Server:</strong> <code>apt install poppler-utils</code> on the mothership if <code>pdftotext</code> is missing.</li>
                        </ul>
                    </div>
                    <div class="about-timeline-entry">
                        <div class="v-header"><strong>v0.6.4</strong> <span class="v-date">May 2026</span></div>
                        <div class="v-title">Lex full-text corpus (FR / CH and Magnitu training layer)</div>
                        <ul>
                            <li><strong>Storage:</strong> <code>lex_items.content</code> (LONGTEXT) holds full law body; <code>description</code> stays a short synopsis for recipe scoring. Timeline reads omit heavy columns; Magnitu export ships corpus text.</li>
                            <li><strong>France:</strong> PISTE <code>/consult/jorf</code> on forward ingest; backfill normalizes versioned <code>JORFTEXT…_01-01-2999</code> ids to bare chronical ids.</li>
                            <li><strong>Switzerland (Fedlex):</strong> promote existing consultation synopsis into <code>content</code> where rows predate corpus storage (<code>--ch</code> backfill).</li>
                            <li><strong>Also:</strong> EU EUR-Lex HTML backfill, Jus HTML corpus, <code>bin/lex-backfill-content.php</code> diagnostics. Schema v39.</li>
                        </ul>
                    </div>
                    <div class="about-timeline-entry">
                        <div class="v-header"><strong>v0.6.3</strong> <span class="v-date">May 2026</span></div>
                        <div class="v-title">Mail readability &amp; webview links</div>
                        <ul>
                            <li><strong>Body processors:</strong> Mail → Subscriptions can assign a named processor (e.g. Europarl “EP TODAY”) to derive a headline and normalize digest plain text; <strong>Reprocess stored mail</strong> reapplies rules without refetching Gmail.</li>
                            <li><strong>View in browser →</strong> on email cards for any sender when the stored HTML includes a typical webview / Webansicht link (captured before boilerplate stripping).</li>
                            <li><strong>Schema v33:</strong> <code>email_subscriptions.body_processor</code>, <code>emails.derived_title</code>.</li>
                        </ul>
                    </div>
                    <div class="about-timeline-entry">
                        <div class="v-header"><strong>v0.6.2</strong> <span class="v-date">May 2026</span></div>
                        <div class="v-title">Path satellites on one VPS</div>
                        <ul>
                            <li><strong>One codebase:</strong> desks at <code>/&lt;slug&gt;/</code> (e.g. <code>/security/</code>, <code>/digital/</code>) share the <code>seismo</code> entries database; scores and labels live in <code>seismo_&lt;slug&gt;</code>.</li>
                            <li><strong>Provision:</strong> Settings → Satellites registry plus <code>bin/seismo-satellite-provision.sh</code> on the server — no pruned bundles or external generator.</li>
                            <li><strong>Migrations:</strong> <code>php migrate.php --scores-db=…</code> for desk databases; remote refresh auto-targets the mothership URL on the same host.</li>
                        </ul>
                    </div>
                    <div class="about-timeline-entry">
                        <div class="v-header"><strong>v0.6.1</strong> <span class="v-date">May 2026</span></div>
                        <div class="v-title">Gmail sender review &amp; QoL</div>
                        <ul>
                            <li><strong>New senders queue:</strong> Gmail ingest proposes domain subscriptions with a display name; you review and confirm before they join the active registry (legacy IMAP ingest unchanged).</li>
                            <li><strong>Reliability:</strong> large Gmail HTML bodies no longer break ingest; EUR-Lex refresh uses the correct SPARQL POST endpoint.</li>
                        </ul>
                    </div>
                    <div class="about-timeline-entry">
                        <div class="v-header"><strong>v0.6.0</strong> <span class="v-date">May 2026</span></div>
                        <div class="v-title">VPS-ready product line</div>
                        <ul>
                            <li><strong>Production deploy:</strong> documented stack for Hetzner VPS (Nginx, PHP-FPM, MariaDB) with <code>/var/www/seismo</code> layout and cron ingest.</li>
                            <li><strong>Gmail API:</strong> OAuth connect in Settings → Mail; unified <code>emails</code> table replaces split IMAP/Gmail storage paths.</li>
                            <li><strong>Operations:</strong> numbered migrations, source-config JSON export/import (legacy pruned satellite bundles superseded in 0.6.2).</li>
                        </ul>
                    </div>
                    <div class="about-timeline-entry">
                        <div class="v-header"><strong>v0.5.3</strong> <span class="v-date">Apr 2026</span></div>
                        <div class="v-title">Robust refresh for many sources</div>
                        <ul>
                            <li><strong>Chunked RSS &amp; scraper (default):</strong> each cron tick pulls a bounded batch and saves cursor state in <code>system_config</code>, so hundreds of feeds stay within typical PHP time limits; a full rotation still respects the usual throttle windows between completed cycles.</li>
                            <li><strong>Cron overlap guard:</strong> <code>refresh_cron.php</code> uses a MySQL advisory lock so a second overlapping run (e.g. every-minute cron while the first tick is still busy) exits quietly instead of duplicating upstream fetches.</li>
                            <li><strong>Legacy mode:</strong> Settings → General can restore the previous single-pass RSS + scraper sweep when you explicitly need it.</li>
                        </ul>
                    </div>
                    <div class="about-timeline-entry">
                        <div class="v-header"><strong>v0.5.2</strong> <span class="v-date">Apr 2026</span></div>
                        <div class="v-title">Operations &amp; refresh UX</div>
                        <ul>
                            <li><strong>Per-module refresh:</strong> Feeds, Scraper, Mail, and Lex pages run only their ingest pipeline; Leg keeps its Parlament CH control.</li>
                            <li><strong>Timeline refresh:</strong> Toolbar refresh skips Lex legislation sources by default to avoid long HTTP requests; full refresh stays on Diagnostics and cron.</li>
                        </ul>
                    </div>
                    <div class="about-timeline-entry">
                        <div class="v-header"><strong>v0.5.1</strong> <span class="v-date">Apr 2026</span></div>
                        <div class="v-title">Architectural Consolidation</div>
                        <ul>
                            <li><strong>Service-Oriented Core:</strong> Replaced procedural logic with lightweight controllers and repository patterns.</li>
                            <li><strong>Unified Pipeline:</strong> All fetching now runs under a master cron (<code>refresh_cron.php</code>).</li>
                            <li><strong>Security Hardening:</strong> Implementation of CSRF protection and a dormant session-auth layer.</li>
                            <li><strong>Clean API:</strong> Retired the "AI view" in favor of a stable, bearer-token-protected JSON/Markdown export API.</li>
                            <li><strong>Source previews:</strong> Feeds, Scraper, and Mail pages help validate sources before you commit them.</li>
                        </ul>
                    </div>
                </div>
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
                <p>Downstream tools authenticate with a <strong>Bearer</strong> token sent as <code>Authorization: Bearer …</code> (or the documented query/body fallback). Use these for briefings, n8n, Raycast, or custom scripts:</p>
                <ul>
                    <li><code>?action=briefing_builder</code> &mdash; in-app Gemini executive briefing with filters, per-desk prompt library, and source-card attribution (mothership and path satellites).</li>
                    <li><code>?action=export_briefing</code> &mdash; Markdown digest for a time window; suited to LLM context and daily summaries.</li>
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

            <footer class="about-footer">
                <p>Built with precision by <a href="https://hektopascal.org" target="_blank" rel="noopener">hektopascal.org</a>.</p>
                <p class="meta-text about-meta">Dev Detail: See <code>README-REORG.md</code> for internal architectural migration notes.</p>
            </footer>
        </main>
    </div>

    <!-- Minimal styles to support the new layout without breaking the theme -->
    <style>
        .about-modern-layout {
            max-width: 54rem !important; /* Slightly wider for the cards */
        }
        .about-card {
            background: #fff;
            border: 2px solid #000;
            padding: 1.5rem !important;
            margin-bottom: 2rem;
            box-shadow: 4px 4px 0 #000;
        }
        .about-card h2 {
            margin-top: 0 !important;
            border-bottom: 2px solid #000;
            padding-bottom: 0.5rem;
            font-size: 1.2rem;
        }
        .about-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-top: 1rem;
        }
        .about-grid-item strong {
            display: block;
            margin-bottom: 0.25rem;
            font-size: 0.95rem;
        }
        .about-grid-item p {
            margin: 0 !important;
            font-size: 0.85rem;
            opacity: 0.85;
        }
        .about-scoring-levels {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        .scoring-level {
            padding: 0.75rem;
            border: 1px solid #eee;
            background: #fafafa;
        }
        .level-tag {
            display: inline-block;
            padding: 2px 8px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            border: 1px solid #000;
            margin-bottom: 0.5rem;
        }
        .tag-lead { background: #ffdada; }
        .tag-important { background: #fff4d1; }
        .tag-background { background: #e1f5fe; }
        .tag-noise { background: #f5f5f5; color: #999; }
        .scoring-level p {
            margin: 0 !important;
            font-size: 0.8rem;
        }
        .v-header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-bottom: 0.2rem;
        }
        .v-date {
            font-size: 0.8rem;
            opacity: 0.6;
        }
        .v-title {
            font-weight: 700;
            font-size: 0.95rem;
            margin-bottom: 0.5rem;
            color: var(--seismo-accent, #000);
        }
        .current-version {
            border-left-width: 6px !important;
            background-color: #fffdec !important;
        }
        .stats-total-row td {
            background-color: #ffffc5;
            border-top: 2px solid #000;
        }
        @media (max-width: 600px) {
            .about-grid, .about-scoring-levels {
                grid-template-columns: 1fr;
            }
        }
    </style>
</body>
</html>

