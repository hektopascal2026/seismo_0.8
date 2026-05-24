# Seismo 0.6.6

**Seismo** is a self-hosted monitoring dashboard: RSS and Substack feeds, Gmail/IMAP mail, web scrapers, legal gazettes (Lex), and Swiss parliamentary business (Leg) in one searchable timeline — with recipe scoring and optional **Magnitu v3** ML scores over HTTP.

Built on **PHP 8.2+**, **MariaDB/MySQL**, and vanilla PHP (no Redis or worker daemons). One web app plus **`refresh_cron.php`** for production ingest on the mothership.

**Seismo 0.5** is frozen in the sibling repo [`seismo_0.5`](https://github.com/hektopascal2026/seismo_0.5) (tag `v0.5.3`). This tree is the active product line.

### Release notes

| Version | Notes |
|---------|--------|
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
4. Register cron: **`*/2 * * * * php /var/www/seismo/refresh_cron.php`** (mutex skips overlaps; use `*/5` on coarse shared hosts)

Requires **`docs/db-schema.sql`** on the server — the mothership migrator reads it at runtime.

### Path satellite (optional desk)

See **[Path satellites](#path-satellites)** below for the full walkthrough. Short version: register in Settings → Satellites, then `sudo bin/seismo-satellite-provision.sh <slug>` on the VPS.

---

## Main URLs

| Action | Purpose |
|--------|---------|
| `?action=index` | Dashboard timeline |
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
- **Refresh** on the timeline — should call mothership ingest (if remote refresh was enabled when adding)

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
| Cron / ingest | `refresh_cron.php` on mothership only | **Nothing** |

Most migrations are **mothership-only** (feeds, mail, plugins, scrapers). The scores migrator records skipped versions on `seismo_<slug>` without running them. Run `--scores-db` only when a release changes **local** tables (see `docs/db-schema-local.sql` — rare after initial provision).

**Satellites never need:** a second deploy, pruned bundles, extra Nginx vhosts, or their own cron.

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
| `migrationScope()` error on `--scores-db` | Pull latest code; re-run `php migrate.php --scores-db=seismo_<slug>` |
| DB permission errors | App user: `SELECT` on `seismo.*`, `ALL` on `seismo_<slug>.*` |

---

## Magnitu v3

Companion app (separate checkout). Seismo exposes `magnitu_entries`, `magnitu_scores`, `magnitu_labels`, `magnitu_recipe`, `magnitu_status`. Do not change JSON shapes or `entry_type` values without updating the Magnitu client.

Each satellite desk has its own `api_key` and training labels in `seismo_<slug>`.

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
