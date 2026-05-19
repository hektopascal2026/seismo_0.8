# Seismo 0.6.1

**Seismo** is a self-hosted monitoring dashboard: RSS and Substack feeds, Gmail/IMAP mail, web scrapers, legal gazettes (Lex), and Swiss parliamentary business (Leg) in one searchable timeline — with recipe scoring and optional **Magnitu v3** ML scores over HTTP.

Built on **PHP 8.2+**, **MariaDB/MySQL**, and vanilla PHP (no Redis or worker daemons). One web app plus **`refresh_cron.php`** for production ingest.

**Seismo 0.5** is frozen in the sibling repo [`seismo_0.5`](https://github.com/hektopascal2026/seismo_0.5) (tag `v0.5.3`). This tree is the active product line.

### Release notes

| Version | Notes |
|---------|--------|
| **0.6.0** | **VPS-ready baseline** — production layout on Hetzner (Nginx, PHP-FPM, MariaDB via socket), unified `emails` table, Gmail API ingest with OAuth, numbered migrations through schema v30, source-config JSON export/import, satellite bundles. |
| **0.6.1** | **Gmail + QoL** — unknown Gmail sender domains queue in **Mail → Subscriptions → New senders** with a proposed display name and **Review** flow before they become active subscriptions; fixes for large Gmail HTML bodies and EUR-Lex refresh. |

---


## Quick start

1. Copy **`config.local.php.example`** → **`config.local.php`** and set database credentials (or use **`?action=configuration`** in the browser on first install).
2. Run migrations: **`php migrate.php`** (or **`?action=migrate`** with `SEISMO_MIGRATE_KEY` when you have no shell).
3. Open **`?action=health`**, then **`?action=index`**.
4. Register cron: **`*/5 * * * * php /path/to/seismo/refresh_cron.php`**

Requires **`docs/db-schema.sql`** on the server — the migrator reads it at runtime.

---

## Main URLs

| Action | Purpose |
|--------|---------|
| `?action=index` | Dashboard timeline |
| `?action=feeds` / `scraper` / `mail` / `lex` / `leg` | Module admin (Items \| Sources) |
| `?action=settings` | Magnitu keys, mail OAuth, retention, satellites, diagnostics |
| `?action=settings&tab=general` | UI prefs, **source config export** (JSON bundle for migrations) |
| `magnitu_*` | Magnitu v3 API (Bearer `api_key` in `system_config`) |
| `export_*` | Read-only export API (separate `export:api_key`) |

Internal links use **`getBasePath()`** — the app can live at `/` or in a subfolder (e.g. `/seismo/`).

---

## Repository layout

```
index.php, bootstrap.php, refresh_cron.php, migrate.php
src/          Controllers, repositories, plugins, migrations, core fetchers
views/        PHP templates
assets/       CSS, images
vendor/       Composer dependencies (committed for deploy without Composer on host)
docs/         db-schema.sql only (reference + migrator input)
newsbridge/   Optional RSS bridge sidecar
tests/        PHPUnit
```

---

## Configuration

| Location | Role |
|----------|------|
| **`config.local.php`** | DB credentials, optional auth hash, satellite knobs, migrate key — never commit |
| **`system_config`** | Magnitu keys, recipe JSON, plugin blocks (`plugin:*`), retention, mail OAuth secrets |
| **`feeds` / `scraper_configs` / `email_subscriptions`** | Source definitions (exportable via `?action=export_source_configs`) |

Optional session auth: set **`SEISMO_ADMIN_PASSWORD_HASH`** in `config.local.php` (see example file). Magnitu/export APIs use Bearer tokens independently.

---

## Magnitu v3

Companion app (separate checkout). Seismo exposes `magnitu_entries`, `magnitu_scores`, `magnitu_labels`, `magnitu_recipe`, `magnitu_status`. Do not change JSON shapes or `entry_type` values without updating the Magnitu client.

**Satellite mode:** a second instance reads entry tables from a mothership database (`SEISMO_MOTHERSHIP_DB`) and keeps scores/labels/config local.

---

## Deploy notes

- **Apache:** `.htaccess` forwards `Authorization` for Bearer APIs on CGI/FastCGI.
- **Nginx:** pass `Authorization` and set `fastcgi_param HTTPS` / `X-Forwarded-Proto` when TLS terminates in front of PHP.
- **Cron:** CLI only; overlapping runs are skipped via a MySQL advisory lock.
- **Satellite bundles:** see **`satellite-prune.json`** and **`routes_satellite.inc.php`**.

---

## Development

```bash
composer install          # includes PHPUnit
./vendor/bin/phpunit
```

---

## License / attribution

See in-app **About** (`?action=about`) for product context. Built by [hektopascal.org](https://hektopascal.org).
