# Media module

**Added in Seismo 0.6.6.**

Admin surface for **news and media monitoring**, separate from **Feeds** (general RSS/Substack/Parl. press).

## Partitioning

| Module | URL | `feeds.category` | Typical ingest |
|--------|-----|------------------|----------------|
| **Feeds** | `?action=feeds` | anything except `media` / `scraper` | Newsletters, Parl. press, full-text RSS |
| **Media** | `?action=media` | `media` | Google News RSS + hydration, or Scraper listings |
| **Scraper** | `?action=scraper` | often `scraper` or `media` | Site listing → article pages |

Rows are still in `feeds` / `feed_items`; only the admin UI and refresh scope differ.

## Ingest patterns on Media

1. **Google News (thin RSS)** — add source on Media → **Extract full text** is on by default for new sources (resolves Google links, fetches the publisher page, picks the best of JSON-LD / Readability / meta). Prefer **direct NZZ RSS** when possible: `https://www.nzz.ch/startseite.rss`. See [rss-hydration.md](rss-hydration.md).
2. **Blick (Ringier)** — section RSS, e.g. `https://www.blick.ch/politik/rss.xml`. **Extract full text on** (full HTML, not paywalled like TA). If you see **HTTP 403**, set `SEISMO_MOTHERSHIP_URL` in `config.local.php` or deploy the `seismoHttpUserAgent()` fix (Ringier blocks bare `Seismo/x.y` without a `(+url)` contact).
3. **Tamedia (TA, Der Bund, …)** — do **not** use `…/rss.xml` (302 loop). Use section feeds, e.g. `https://www.tagesanzeiger.ch/ausland/rss.html` (308 → `https://partner-feeds.publishing.tamedia.ch/rss/tagesanzeiger/ausland`). **Extract full text on** pulls the same public preview as Safari Reader: kicker (`titleHeader`), pull quote (`title`), lead paragraph (`lead`), and «In Kürze» bullets (`summary-list-data`) — not the full Abo article. **20 Minuten:** `https://partner-feeds.20min.ch/rss/20minuten/ausland`.
4. **Publisher listing (full HTML)** — add on **Scraper**, set category to `media` (feed row + scraper config). Items appear on Media → Items and in the timeline with `feed_category = media`.

**Refresh Media** runs only `category = media` sources (RSS hydration path + media scrapers), not the whole cron RSS cycle.

## Code map

| Piece | Location |
|-------|----------|
| Module config | `src/Feed/FeedModule.php` |
| Shared UI/actions | `src/Controller/FeedModuleHandler.php` |
| Media routes | `MediaController`, `?action=media_*` |
| View | `views/feed_module.php` |
| Timeline SQL | `EntryRepository::getMediaModuleTimeline()` |
| Targeted refresh | `CoreRunner::runRssForCategory()` / `runScraperForCategory()` |

## Satellite impact

None — configure on mothership only (same as Feeds).
