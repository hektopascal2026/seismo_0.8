# Seismo 0.6.3

**Seismo** is a self-hosted monitoring dashboard: RSS and Substack feeds, Gmail/IMAP mail, web scrapers, legal gazettes (Lex), and Swiss parliamentary business (Leg) in one searchable timeline — with recipe scoring and optional **Magnitu v3** ML scores over HTTP.

Built on **PHP 8.2+**, **MariaDB/MySQL**, and vanilla PHP (no Redis or worker daemons). One web app plus **`refresh_cron.php`** for production ingest on the mothership.

**Seismo 0.5** is frozen in the sibling repo [`seismo_0.5`](https://github.com/hektopascal2026/seismo_0.5) (tag `v0.5.3`). This tree is the active product line.

### Release notes

| Version | Notes |
|---------|--------|
| **0.6.3** | **Mail readability** — optional per-subscription body processors (e.g. Europarl “EP TODAY” digests: derived headline, cleaner plain text); **View in browser →** on cards when the message HTML includes a webview link (all senders); **Reprocess stored mail** on Mail → Subscriptions. Extract webview URLs before boilerplate stripping. Schema v33 (`body_processor`, `derived_title`). |
| **0.6.2** | **Path satellites** — one VPS codebase; desks at `/<slug>/` (e.g. `/security/`, `/digital/`) share the `seismo` entries DB and keep scores/labels in `seismo_<slug>`. Settings → Satellites registry + `bin/seismo-satellite-provision.sh`; removed pruned satellite bundles and `seismo-generator`. Scores migrations via `php migrate.php --scores-db=…`. |
| **0.6.1** | **Gmail + QoL** — unknown Gmail sender domains queue in **Mail → Subscriptions → New senders** with a proposed display name and **Review** flow before they become active subscriptions; fixes for large Gmail HTML bodies and EUR-Lex refresh. |
| **0.6.0** | **VPS-ready baseline** — production layout on Hetzner (Nginx, PHP-FPM, MariaDB via socket), unified `emails` table, Gmail API ingest with OAuth, numbered migrations, source-config JSON export/import. |

---

## Quick start

1. Copy **`config.local.php.example`** → **`config.local.php`** and set database credentials (`DB_NAME` = `seismo` on the VPS).
2. Run migrations: **`php migrate.php`** (or **`?action=migrate`** with `SEISMO_MIGRATE_KEY` when you have no shell).
3. Open **`?action=health`**, then **`?action=index`**.
4. Register cron: **`*/5 * * * * php /var/www/seismo/refresh_cron.php`**

Requires **`docs/db-schema.sql`** on the server — the mothership migrator reads it at runtime.

### Path satellite (optional desk)

1. Mothership running with entries in database **`seismo`**.
2. **Settings → Satellites** — add slug (e.g. `security`, `digital`).
3. On the VPS: **`sudo bin/seismo-satellite-provision.sh <slug>`** (creates `seismo_<slug>`, stub `/<slug>/`, assets symlink).
4. Open **`https://your-host/<slug>/`** — timeline, highlights, label training, Magnitu API; no feeds/mail/Lex admin.

---

## Main URLs

| Action | Purpose |
|--------|---------|
| `?action=index` | Dashboard timeline |
| `?action=feeds` / `scraper` / `mail` / `lex` / `leg` | Module admin (mothership only) |
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
newsbridge/       Optional RSS bridge sidecar
tests/            PHPUnit
```

---

## Configuration

| Location | Role |
|----------|------|
| **`config.local.php`** | DB credentials (`seismo`), auth hash, migrate key — never commit |
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

Desks are **views** over shared ingest, not separate apps:

| Layer | Mothership | Satellite (`/security/`, …) |
|-------|------------|-------------------------------|
| Code | `/var/www/seismo` | Same tree |
| Entries | `seismo.*` | Cross-DB read from `seismo` |
| Scores / labels / favourites | `seismo` | `seismo_<slug>` |
| Routes | Full admin + ingest | Timeline, highlights, label, settings (general + Magnitu), Magnitu API |
| Cron | `refresh_cron.php` | No-op (refresh via mothership) |

**Provision:** `bin/seismo-satellite-provision.sh <slug>` — MariaDB create/grant, `php migrate.php --scores-db=seismo_<slug>`, seed Magnitu `api_key` from registry, write stub + `assets` symlink.

**Removed in 0.6.2:** `satellite-prune.json`, JSON download for `seismo-generator`, per-desk pruned codebases, `SEISMO_SATELLITE_MODE` / `SEISMO_MOTHERSHIP_DB` config.

---

## Magnitu v3

Companion app (separate checkout). Seismo exposes `magnitu_entries`, `magnitu_scores`, `magnitu_labels`, `magnitu_recipe`, `magnitu_status`. Do not change JSON shapes or `entry_type` values without updating the Magnitu client.

Each satellite desk has its own `api_key` and training labels in `seismo_<slug>`.

---

## Deploy notes

- **Apache:** `.htaccess` forwards `Authorization` for Bearer APIs on CGI/FastCGI.
- **Nginx:** pass `Authorization` and set `fastcgi_param HTTPS` / `X-Forwarded-Proto` when TLS terminates in front of PHP. One server block for the app root; no extra vhost per desk.
- **Cron:** mothership only — `refresh_cron.php`; overlapping runs skipped via MySQL advisory lock.
- **Migrations:** `php migrate.php` on `seismo`; `php migrate.php --scores-db=seismo_<slug>` for each desk (provision script runs this).

---

## Development

```bash
composer install          # includes PHPUnit
./vendor/bin/phpunit
```

---

## License / attribution

See in-app **About** (`?action=about`) for product context. Built by [hektopascal.org](https://hektopascal.org).
