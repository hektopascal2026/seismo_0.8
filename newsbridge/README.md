# Newsbridge (v0.4 / gaia parity)

The four aggregate feeds Seismo reads as normal RSS are built here from **NewsAPI.org** (same `streams.php` logic as the historical gaia + v0.4 staging `newsbridge`).

Output files: **`feeds/top-ch.xml`**, **`feeds/ch-en.xml`**, **`feeds/ch-de.xml`**, **`feeds/ch-fr.xml`**.

## Setup

1. **NewsAPI key** — [newsapi.org](https://newsapi.org) (free tier is enough for light use; check their limits).

2. **Copy** `config.example.php` → **`config.local.php`** (gitignored) and set at least:
   - `newsapi_key`
   - `site_base_url` — public URL of this folder, **no trailing slash**, e.g. `https://www.example.org/seismo/newsbridge`  
     (So feed URLs are `https://…/seismo/newsbridge/feeds/top-ch.xml`.)

3. **PHP extensions:** `curl`, `pdo_sqlite`, `dom`.

## Run (generate XML)

Register a system crontab entry (CLI only — no HTTP endpoint):

```bash
*/30 * * * * /usr/bin/php /var/www/seismo/newsbridge/newsbridge_cron.php
```

SQLite cache and logs: `newsbridge/data/` (auto-created).

## Seismo → Feeds

Point the four `feeds.url` entries at (adjust host + base path):

- `…/newsbridge/feeds/top-ch.xml`
- `…/newsbridge/feeds/ch-en.xml`
- `…/newsbridge/feeds/ch-de.xml`
- `…/newsbridge/feeds/ch-fr.xml`

## Editing streams

Change **`streams.php`** (swiss domains for `top-ch`, or the `q` / `language` queries for the language feeds). Not editable from the Seismo UI — same as gaia.

## Optional: `config.example.json` + `Seismo\Service\NewsbridgeGenerator`

A separate, experimental **RSS-merge** path (merge plain RSS URLs into XML) lives in the main app; it is **not** the v0.4 News API pipeline. Use the files above for parity with staging.
