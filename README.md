# Seismo 0.8.2

**Seismo** is a self-hosted monitoring dashboard: RSS and Substack feeds, Gmail/IMAP mail, web scrapers, legal gazettes (Lex), and Swiss parliamentary business (Leg) in one searchable timeline — with recipe scoring and optional **Magnitu v3** ML scores over HTTP.

Built on **PHP 8.2+**, **MariaDB/MySQL**, and vanilla PHP (no Redis or worker daemons). One web app plus **`refresh_cron.php`** for production ingest on the mothership.

**Seismo 0.5** is frozen in the sibling repo [`seismo_0.5`](https://github.com/hektopascal2026/seismo_0.5) (tag `v0.5.3`). This tree is the active product line.

### Release notes

| Version | Notes |
|---------|--------|
| **0.8.2** | **AI Researcher — tournament selection & cost estimate** — optional **Tournament selection** (parallel batch prelims + championship pass for large pools). After generation, a **rough USD estimate** under the summary uses Gemini `usageMetadata` and **Gemini 3.5 Flash Standard** list prices, with a link to [Google AI Studio spend](https://aistudio.google.com/spend?project=gen-lang-client-0854484393). |
| **0.8.1** | **Compact settings & prompt safeguards** — redesigned AI Researcher layout: collapsed advanced options inside a sleek "More settings" panel, added range sliders with dynamic context-capacity scaling, hardened tab controls to protect the Default system prompt, and raised `TIMELINE_BODY_CHARS` to 10000 to prevent Substack card truncations. |
| **0.8.0** | **Premium typography & visual polish** — loaded the beautiful geometric **Outfit** font stack globally across all dashboard pages, updated sidebar navigation tabs, and styled zero border-radius tactile inputs for a professional Brutalist identity. |
| **0.7.9** | **Brutalist theme refactor** — complete styling refactoring of CSS templates to establish high-contrast borders, solid 2px drop shadows, responsive 3D hover states, and introduced automatic cache-busting version identifiers for CSS assets. |
| **0.7.8** | **Gemini Configurator** — AI-powered setup assistant that analyzes sample emails, generates regular expressions and webview keywords, and saves a static configuration locally for zero-runtime footprint (no external AI calls during ingestion). Dynamic before/after tabbed live preview workspace. |
| **0.7.7** | **Swiss Fedlex** — SPARQL decision + entry-into-force dates on cards; Akoma Ntoso XML corpus for OC acts (`LexFedlexContentFetcher`); **`php bin/lex-backfill-content.php --ch`**. Timeline: date-only lex items sort on publication day with ingestion time (no backfill “breaking news”). Consultation ingest no longer uses `dcterms:modified` fallback. |
| **0.7.6** | **Researcher prompt helper** — **View: Prompt \| Helper** on `?action=researcher`: rough intent → Gemini drafts a full researcher prompt in the style of the desk default (`researcher_prompt_helper`); review, edit, save to library or instance default (syncs the Prompt editor). Generate researcher still uses the Prompt view only. |
| **0.7.5** | **AI Researcher on Gemini 3.5** — default **`gemini-3.5-flash`** only; skinny **two-pass** pipeline (USER PROMPT in selection + summary, optional `selection_reasoning`, plain Markdown pass 2). **Dynamic entry bodies** (2k–12k chars/pool), **module guard**, 32k system prompts, **thinkingLevel** LOW. HTTP **429** batched retry fixed. Docs: `docs/ai-researcher-builder.md`. |
| **0.7.4** | **Mail locale web views** — bilingual newsletters: inbox language picks the best labelled edition (German inbox → DE; non-English → DE then EN; English → EN then DE) over generic “view in browser” links. **Hosted DE/EN hydration** at ingest/reprocess: fetches the web edition (Readability + newsletter fallback), replaces `text_body`, metadata `web_view_url`, `web_view_locale`, `body_source`. |
| **0.7.3** | **Researcher prompt library** — named prompts on `?action=researcher` (tabs, save to library, delete); stored per desk in `ai_researcher_prompts` (mothership + satellites). **Save prompt (default)** still updates `researcher:system_prompt`. First visit seeds a **Default** tab from the current prompt. |
| **0.7.2** | **Researcher JSON repair** — `LenientJsonParser` (tourdesuisse-style pipeline) recovers malformed Gemini JSON; researcher still shown when attribution JSON fails (no source cards). Clearer API errors in the UI. |
| **0.7.1** | **AI Researcher attribution** — after generation, **Referenced source entries** shows only dashboard cards for `used_entry_keys` returned by Gemini (citation order); fallback + warnings when IDs are missing or unknown. |
| **0.7.0** | **AI Researcher** (mothership) — `?action=researcher`: filter investigation-lead entries (optional important), six module toggles, Gemini executive researcher (JSON + Markdown), up to **1000 characters** of body text per entry in context. Settings → General: `gemini:api_key`. Docs: `docs/ai-researcher-builder.md`. |
| **0.6.7** | **Filter page revamp** — `?action=filter` UI aligned with the timeline: shared entry-card styling, square source pills, and clearer module/source filtering before previewing the filtered stream. |
| **0.6.6** | **Media module** — admin at `?action=media` for news monitoring (`feeds.category = media`), separate from Feeds. **RSS full-text hydration:** per-feed **Extract full text** (migration 024), **`RssArticleHydrator`** + **`ArticlePageBodyExtractor`** (JSON-LD, Readability, meta), **`GoogleNewsArticleUrlResolver`** for Google News wrappers. **Refresh Media** runs only media-category RSS + scrapers. Docs: `docs/media-module.md`, `docs/rss-hydration.md`. |
| **0.6.5** | **DE BGBl PDF corpus** — recht.bund.de RSS is metadata-only; **`LexRechtBundContentFetcher`** downloads `regelungstext.pdf` and extracts plain text via **`pdftotext`** (poppler-utils). Forward ingest on Lex refresh + **`php bin/lex-backfill-content.php --de`**. Requires `apt install poppler-utils` on the mothership. |
| **0.6.4** | **Lex full-text corpus** — `lex_items.content` (LONGTEXT) for Magnitu training; timeline reads stay lightweight. **FR:** Légifrance PISTE **`/consult/jorf`** on ingest/backfill (versioned `JORFTEXT…` id normalization). **CH:** Fedlex synopsis → `content` promote path. **EU:** EUR-Lex HTML backfill; **Jus:** entscheidsuche HTML corpus. Recipe scoring uses synopsis only (`description`). **`bin/lex-backfill-content.php`** (`--fr`, `--eu`, `--ch`, `--de` promote legacy). Schema v39. |
| **0.6.3** | **Mail readability** — optional per-subscription body processors (e.g. Europarl “EP TODAY” digests: derived headline, cleaner plain text); **View in browser →** on cards when the message HTML includes a webview link (all senders); **Reprocess stored mail** on Mail → Subscriptions. Extract webview URLs before boilerplate stripping. Schema v33 (`body_processor`, `derived_title`). |
| **0.6.2** | **Path satellites** — one VPS codebase; desks at `/<slug>/` (e.g. `/security/`, `/digital/`) share the `seismo` entries DB and keep scores/labels in `seismo_<slug>`. Settings → Satellites registry + `bin/seismo-satellite-provision.sh`; removed pruned satellite bundles and `seismo-generator`. Scores migrations via `php migrate.php --scores-db=…`. |
| **0.6.1** | **Gmail + QoL** — unknown Gmail sender domains queue in **Mail → Subscriptions → New senders** with a proposed display name and **Review** flow before they become active subscriptions; fixes for large Gmail HTML bodies and EUR-Lex refresh. |
| **0.6.0** | **VPS-ready baseline** — production layout on Hetzner (Nginx, PHP-FPM, MariaDB via socket), unified `emails` table, Gmail API ingest with OAuth, numbered migrations, source-config JSON export/import. |

---

## Quick start

1. Copy **`config.local.php.example`** → **`config.local.php`** and set database credentials (`DB_NAME` = `seismo` on the VPS).
2. Run migrations: **`php migrate.php`**.
3. Open **`?action=health`**, then **`?action=index`**.
4. Register cron on the mothership:
   - **`*/2 * * * * php /var/www/seismo/refresh_cron.php`** — ingest (mutex skips overlaps; use `*/5` on coarse shared hosts)
   - **`*/5 * * * * php /var/www/seismo/satellite_rescore_cron.php`** — recipe scores for every desk in Settings → Satellites (no per-slug cron lines)

Requires **`docs/db-schema.sql`** on the server — the mothership migrator reads it at runtime.

### Path satellite (optional desk)

See **[Path satellites](#path-satellites)** below for the full walkthrough. Short version: register in Settings → Satellites, then `sudo bin/seismo-satellite-provision.sh <slug>` on the VPS.

---

## Main URLs

| Action | Purpose |
|--------|---------|
| `?action=index` | Dashboard timeline |
| `?action=filter` | Filter page — module/source pills, preview filtered entries |
| `?action=researcher` | AI Researcher — Gemini executive researcher, per-desk prompt library + default prompt (mothership + satellites; local Magnitu scores on satellites) |
| `?action=feeds` / `media` / `scraper` / `mail` / `lex` / `leg` | Module admin (mothership only). **Media** = news monitoring (thin RSS + hydration); **Feeds** = general RSS/Substack/Parl. press |
| `?action=settings` | Magnitu keys, mail OAuth, retention, satellites, diagnostics |
| `?action=settings&tab=satellite` | Register path satellites before provisioning |
| `?action=settings&tab=general` | UI prefs, **source config export** (JSON bundle) |
| `magnitu_*` | Magnitu v3 API (Bearer `api_key` in desk `system_config`) |
| `export_*` | Read-only export API (mothership; separate `export:api_key`) |

Internal links use **`getBasePath()`** — mothership at `/`, desks at `/<slug>/`.

---

## Repository layout

```
index.php, bootstrap.php, refresh_cron.php, migrate.php
bin/              seismo-satellite-provision.sh
security/, digital/   path stubs (index.php → root front controller)
src/              Controllers, repositories, plugins, migrations
views/            PHP templates
assets/           CSS, images
docs/             db-schema.sql (mothership), db-schema-local.sql (satellite scores DB)
vendor/           Composer dependencies (committed for deploy without Composer on host)
tests/            PHPUnit
```

---

## Configuration

| Location | Role |
|----------|------|
| **`config.local.php`** | DB credentials (`seismo`), auth hash — never commit |
| **`system_config`** (per desk) | Magnitu keys, recipe JSON, plugin blocks on mothership; mostly Magnitu + UI on satellites |
| **`feeds` / `scraper_configs` / `email_subscriptions`** | Mothership only — exportable via `?action=export_source_configs` |

Optional session auth: **`SEISMO_ADMIN_PASSWORD_HASH`** in `config.local.php` (shared across mothership and path desks if you use one codebase). Magnitu/export APIs use Bearer tokens per desk.

| Constant | Role |
|----------|------|
| `SEISMO_ENTRIES_DB` | Shared entries database name (default `seismo`) |
| `remote_refresh_key` (`system_config`) | Shared secret for satellite **Refresh** → mothership ingest (auto-created in Settings → Satellites) |
| `SEISMO_SATELLITE_SLUG` | Set by `/<slug>/index.php` stub — do not set on mothership |

---

## Path satellites

Desks are **views** over shared ingest, not separate apps. One codebase at `/var/www/seismo` serves the mothership (`/`) and every path (`/<slug>/`). Each desk only adds a URL prefix, a **scores** database (`seismo_<slug>`), and a narrower route table.

| Layer | Mothership (`/`) | Satellite (`/<slug>/`) |
|-------|-------------------|-------------------------|
| Code | `/var/www/seismo` | **Same tree** (stub `/<slug>/index.php` only) |
| Entries | `seismo.*` | Cross-DB **read** from `seismo` |
| Scores / labels / favourites | `seismo` | `seismo_<slug>` (local) |
| Routes | Feeds, mail, Lex, Leg, retention, … | Timeline, highlights, label, settings (general + Magnitu), Magnitu API |
| Cron | `refresh_cron.php` every 2 min | None — **Refresh** triggers mothership ingest |

**Removed in 0.6.2:** `satellite-prune.json`, `seismo-generator`, per-desk pruned codebases, `SEISMO_SATELLITE_MODE` / `SEISMO_MOTHERSHIP_DB`.

### Creating a desk

**Prerequisites:** mothership healthy (`php migrate.php` on `seismo`, cron running, entries ingested), latest code deployed, `config.local.php` with `DB_NAME` / `SEISMO_ENTRIES_DB` = `seismo`.

**1. Register in the UI (mothership)**

Open **Settings → Satellites** (`?action=settings&tab=satellite`). Under **Add a satellite**:

| Field | Example |
|-------|---------|
| Slug | `security` (URL `/security/`, DB `seismo_security`) |
| Display name | `Security` → stored as “Seismo Security” |
| Magnitu profile | same as slug (or leave blank) |
| Brand accent | optional, e.g. `#4a90e2` |
| **Remote refresh** | checked by default — stores shared secret in mothership `system_config` so the desk **Refresh** button can trigger ingest |

Click **Add satellite**. Status stays **`pending`** until provisioning.

**2. Provision on the VPS (SSH)**

```bash
cd /var/www/seismo
sudo bin/seismo-satellite-provision.sh security
```

The script (requires the registry row from step 1):

- Creates `seismo_<slug>` and grants the app DB user `SELECT` on `seismo.*`, `ALL` on `seismo_<slug>.*`
- Runs `php migrate.php --scores-db=seismo_<slug>` (local tables only; mothership migrations are skipped)
- Seeds Magnitu `api_key` from the registry into the desk `system_config`
- Writes `/<slug>/index.php` and `/<slug>/assets` → `../assets` if missing
- Sets registry status to **`active`**

On MariaDB via socket, if needed:

```bash
export SEISMO_MYSQL_SOCKET=/run/mysqld/mysqld.sock
export SEISMO_MYSQL_ADMIN_USER=root
```

**3. Smoke test**

- `https://your-host/<slug>/` — timeline (may be empty until mothership has entries)
- `https://your-host/<slug>/?action=health` — satellite mode, entries DB `seismo`, scores DB `seismo_<slug>`
- **Refresh** on the timeline — triggers mothership ingest (if remote refresh was enabled when adding), then local recipe rescore

**4. Magnitu (per desk)**

Point Magnitu at `https://your-host/<slug>/` with that desk’s Bearer `api_key` (from the registry; **Rotate key** in Settings if you regenerate it, then update Magnitu or re-run provision).

Repeat for each desk (`digital`, `sicherheit`, …). Slugs in git (`security/`, `digital/`) are optional examples — provision creates any valid slug folder.

### Deploying code changes

**Usually you change nothing on satellites** — one `git pull` updates mothership and all `/<slug>/` paths together.

```bash
cd /var/www/seismo
git pull
# optional when OPcache does not auto-revalidate:
sudo systemctl reload php8.5-fpm
```

| What changed | Mothership | Each satellite desk |
|--------------|------------|---------------------|
| PHP, views, CSS, assets, route logic | `git pull` (+ optional FPM reload) | **Nothing** (same tree) |
| **Mothership** DB schema (`feeds`, `emails`, `lex_items`, …) | `php migrate.php` | **Nothing** |
| **Scores** DB schema (`entry_scores`, `magnitu_labels`, desk `system_config`) | N/A on mothership-only desks | `php migrate.php --scores-db=seismo_<slug>` **for each desk** |
| New desk | Settings → add + `bin/seismo-satellite-provision.sh <slug>` | That desk only |
| `config.local.php` | Edit **once** | **Nothing** (shared file) |
| Cron / ingest | `refresh_cron.php` (ingest) + `satellite_rescore_cron.php` (all desks) | Timeline **Refresh** (remote ingest + rescore); scoring also runs on cron |

Most migrations are **mothership-only** (feeds, mail, plugins, scrapers). The scores migrator records skipped versions on `seismo_<slug>` without running them. Run `--scores-db` only when a release changes **local** tables (see `docs/db-schema-local.sql` — rare after initial provision).

**Satellites never need:** a second deploy, pruned bundles, extra Nginx vhosts, or per-slug cron entries (one mothership `satellite_rescore_cron.php` covers all registered desks).

```text
Deploy code once      →  all paths use it
Migrate seismo        →  shared entries + mothership config
Migrate seismo_<slug> →  only when scoring/label tables change
```

### Troubleshooting

| Symptom | Fix |
|---------|-----|
| Provision: “not in registry” | Add the row in Settings → Satellites first |
| 404 on `/<slug>/` | Run `bin/seismo-satellite-provision.sh <slug>`; check `/<slug>/index.php` exists |
| Broken CSS | `/<slug>/assets` should symlink to `../assets` |
| Refresh not configured | Add a desk with **Remote refresh** checked, or enable on the next add |
| Recipe scores stale on desk | Ensure `recipe_json` on desk DB; `satellite_rescore_cron.php` or click Refresh after ingest |
| `migrationScope()` error on `--scores-db` | Pull latest code; re-run `php migrate.php --scores-db=seismo_<slug>` |
| DB permission errors | App user: `SELECT` on `seismo.*`, `ALL` on `seismo_<slug>.*` |

---

## Magnitu v3

Companion app (separate checkout). Seismo exposes `magnitu_entries`, `magnitu_scores`, `magnitu_labels`, `magnitu_recipe`, `magnitu_status`. Do not change JSON shapes or `entry_type` values without updating the Magnitu client.

Each satellite desk has its own `api_key` and training labels in `seismo_<slug>`.

---

## European Commission Press Corner Ingestion (Option A)

Seismo implements a premium, push-based ingestion strategy (Option A) for European Commission Press Corner releases. Instead of continuously polling noisy, raw RSS feeds or scraping dynamic frontend content, Seismo leverages targeted email alerts to trigger exact, full-text API-driven hydration.

### Why Option A is Superior

1. **Precision & Filtering**: Users subscribe to specific topics, keywords, and languages in the EC Press Corner subscription portal. This ensures Seismo only ingests highly relevant press releases, avoiding the noise of raw, unfiltered feed streams.
2. **Push-Based Ingestion**: The arrival of an email alert instantly triggers the process, ensuring near-instant delivery of breaking updates on the timeline.
3. **No Polling Overhead**: Eliminates the resource overhead and potential rate-limiting issues of frequent polling/crawling.

### Hydration Architecture & Flow

When an EC email alert arrives, Seismo processes it through two main components:

1. **Extraction (`EmailWebViewUrlExtractor`)**
   - Scans the email for European Commission detail links matching `ec.europa.eu/commission/presscorner/detail/{lang}/{ref}`.
   - Cleans link format anomalies (e.g., stripping wrapping parentheses dynamically via robust trim handling).
2. **Reference Normalization & Hydration (`EmailWebViewBodyHydrator`)**
   - The EC detail pages are built as Angular Single Page Applications (SPAs). Standard raw GET requests to the detail URL only return an empty JS shell.
   - The hydrator automatically parses the document reference (e.g., normalizing `ip_26_1166` to `IP/26/1166`).
   - It performs a direct, structured REST API call to retrieve the clean HTML content:
     `https://ec.europa.eu/commission/presscorner/api/documents?reference={REFERENCE}&language={LANG}`
   - The extracted text content replaces the thin email preview, hydrating the database entry with the official, full-text press release.
3. **OpenSSL 3 Unexpected EOF Fallback**
   - Certain EC API hosts close SSL sessions abruptly without standard `close_notify` alerts. Strict OpenSSL 3 configurations in modern cURL implementations will throw a connection error.
   - Seismo includes a transparent fallback in `BaseClient` that switches to native PHP streams (`file_get_contents` with `ignore_errors` and relaxed SSL settings) if cURL encounters this EOF connection issue, guaranteeing uninterrupted ingestion.

---

## Deploy notes

- **PHP memory:** `bootstrap.php` raises `memory_limit` to **512M** when php.ini/FPM is lower (scraper, lex backfill). Optional pool override: `php_admin_value[memory_limit] = 512M`. Timeline page size stays capped by `EntryRepository::MAX_LIMIT` (200).
- **Nginx:** pass `Authorization` to PHP-FPM so Magnitu/export Bearer APIs work; set `fastcgi_param HTTPS` / `X-Forwarded-Proto` when TLS terminates in front of PHP. **One server block** for the app root — path desks need no extra vhost.
- **Cron:** mothership only — `php /var/www/seismo/refresh_cron.php` (CLI only). Overlapping runs skipped via MySQL advisory lock.
- **Migrations:** `php migrate.php` on the entries DB; per-desk `php migrate.php --scores-db=seismo_<slug>` when local scoring tables change (provision runs this for new desks). See [Deploying code changes](#deploying-code-changes) under Path satellites.

---

## Development

```bash
composer install          # includes PHPUnit
./vendor/bin/phpunit
```

---

## License / attribution

See in-app **About** (`?action=about`) for product context. Built by [hektopascal.org](https://hektopascal.org).
