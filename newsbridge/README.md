# Newsbridge (v0.4 / gaia parity)

The four aggregate feeds Seismo reads as normal RSS are built here from **NewsAPI.org** (same `streams.php` logic as the historical gaia + v0.4 staging `newsbridge`).

Output files: **`feeds/top-ch.xml`**, **`feeds/ch-en.xml`**, **`feeds/ch-de.xml`**, **`feeds/ch-fr.xml`**.

## Setup

1. **NewsAPI key** — [newsapi.org](https://newsapi.org) (free tier is enough for light use; check their limits).

2. **Copy** `config.example.php` → **`config.local.php`** (gitignored) and set at least:
   - `newsapi_key`
   - `cron_token` — long random string for the HTTP cron URL
   - `site_base_url` — public URL of this folder, **no trailing slash**, e.g. `https://www.hektopascal.org/seismo/newsbridge`  
     (So feed URLs are `https://…/seismo/newsbridge/feeds/top-ch.xml`.)

3. **PHP extensions** (most shared hosts have these): `curl`, `pdo_sqlite`, `dom`.

## Run (generate XML)

- **CLI** (no token):
  ```bash
  php /path/to/seismo/newsbridge/newsbridge_cron.php
  ```

- **HTTP** (same as old staging — Plesk “URL cron”):
  ```text
  https://<host>/seismo/newsbridge/cron.php?token=YOUR_CRON_TOKEN
  ```

- **Diagnose** News API from the server:
  ```text
  https://<host>/seismo/newsbridge/cron.php?token=…&diagnose=1
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
