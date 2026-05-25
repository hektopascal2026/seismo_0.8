# AI Briefing Builder — implementation slices

Plan for an in-app page that filters recent Seismo entries and generates a narrative summary via the Gemini API. Vanilla PHP 8.2+, existing MVC/routing — no Laravel/Symfony.

**Status:** complete (slices 1–5). Slice 6 = manual smoke on deploy.

---

## Locked product decisions

| Topic | Decision |
|--------|----------|
| Deploy scope | **Mothership + path satellites** — routes in `routes_mothership.inc.php` and `routes_satellite.inc.php`; satellites use local `entry_scores` / `system_config` |
| Source toggles | **Six nav-aligned modules**, all on by default: Feeds, Media, Scraper, Mail, Lex, Leg |
| Nav placement | Top-level drawer link **after Highlights**, before Label |
| Entry cap | **`MagnituExportRepository::MAX_LIMIT` (2000)** per enabled feed-family query (same ceiling as export) |
| Relevance | **Highlights tier** — `relevance_score ≥ alert_threshold` (Settings → Magnitu); optional **“Also include important band below threshold”** (`score > 50%` and `< threshold`). Optional **“Disregard Magnitu (experimental)”** (`disregard_magnitu`) skips score filter and relevance sort (modules + lookback only; newest first). Score-based, not `predicted_label`. |
| Shared pipeline | **Yes** — extract `BriefingEntryGatherer`, refactor `ExportController` to use it |
| API key | `system_config` key **`gemini:api_key`** via Settings → General (per desk on satellites) |
| Saved prompt (default) | `system_config` key **`briefing:system_prompt`** via **Save prompt (default)** on the page (per desk on satellites) |
| Prompt library | `system_config` key **`ai_briefing_prompts`** — JSON list of `{id, name, content}`; seeded with the current default prompt on first visit; **Save to library** / tab delete via `save_briefing_prompt` and `delete_briefing_prompt` |
| Briefing item count | UI **`item_count`** (allowed: **5, 7, 10, 12, 15**; default **5**). User prompt = free-form structure; platform **SYSTEM DIRECTIVE** + Gemini **`responseSchema`** enforce **`used_entry_keys`** length = `min(item_count, entries gathered)`. Context uses XML `<entry>` blocks with `<id>type:id</id>` (`MarkdownBriefingFormatter::FORMAT_XML`). Schema includes internal **`drafting_thoughts`** (listed IDs before markdown; not shown in UI). |
| Prepare step | **`briefing_builder_prepare`** — gather-only POST returns `entry_count` before Gemini (UI status line). |

### Why six toggles (not four `entry_type` values)

Media (and Scraper) are not separate `entry_type` values. They are **`feed_item` rows** partitioned by feed metadata:

| Nav module | `entry_type` | Partition |
|------------|--------------|-----------|
| Feeds | `feed_item` | RSS / Substack / Parl. press — excludes `category = media` and scraper paths (same rules as Feeds module in `EntryRepository`) |
| Media | `feed_item` | `feeds.category = 'media'` |
| Scraper | `feed_item` | Scraper sources (same rules as Scraper module) |
| Mail | `email` | — |
| Lex | `lex_item` | — |
| Leg | `calendar_event` | — |

Filter-page **per-outlet pills** are out of scope for v1; module-level toggles match main nav.

### Router note

`Router::register($action, $handler, $readOnly)` — third argument is **`readOnly`**, not CSRF. CSRF is enforced in the controller with `CsrfToken::verifyRequest()`. `briefing_builder_generate` must use `readOnly: false`; `briefing_builder` uses `readOnly: true` and must be listed in `Router::READONLY_KEEP_SESSION_FOR_CSRF`.

---

## Architecture (layer boundaries)

| Layer | Responsibility |
|-------|----------------|
| `SettingsController` + `settings_general.php` | Persist `gemini:api_key` |
| `BriefingEntryGatherer` | Fetch + shape entries + scores + label filter (shared with export) |
| `MagnituExportRepository` | SQL only; add scoped `listFeedItemsSince` variants if needed |
| `MarkdownBriefingFormatter` | Markdown for export; XML (`FORMAT_XML`) for AI builder context |
| `GeminiBriefingService` | HTTP to Gemini, parse response, safe errors |
| `AiBriefingController` | Orchestrate `show()` / `generate()` only |
| `views/briefing_builder.php` | Form + vanilla JS `fetch` |

Do **not** put Gemini HTTP or SQL in the controller.

---

## Slice 1 — Settings: Gemini API key ✅

**Goal:** Store the API key in the database; never expose it in HTML.

**Files**

- `src/Controller/SettingsController.php` — `KEY_GEMINI_API_KEY = 'gemini:api_key'`; load in `show()`; save in `saveGeneral()` (non-empty POST only, else keep existing — same pattern as Gmail client secret)
- `views/partials/settings_general.php` — `type="password"`, placeholder “Leave blank to keep current key”, hint when key exists

**Acceptance**

- Save new key → row in `system_config`
- Submit empty field → previous key unchanged
- No migration (key/value table)

---

## Slice 2 — `BriefingEntryGatherer` + export refactor ✅

**Goal:** Single pipeline for export briefing and AI builder; no duplicated `ExportController::gatherEntriesAndScores()` logic.

**New:** `src/Service/BriefingEntryGatherer.php`

**Inputs**

- `?string $since` — from lookback days (1–7 → UTC ISO timestamp)
- `int $limit` — clamp 1 … `MAX_LIMIT`
- Module flags: `includeFeeds`, `includeMedia`, `includeScraper`, `includeEmail`, `includeLex`, `includeLeg` (at least one must be true)
- `BriefingScoreFilter` (builder) — Highlights tier + optional important band below threshold; export still uses `array $labelFilter` on labels

**Behavior**

- For each enabled module, fetch via `MagnituExportRepository` + `MagnituController::shape*()`
- Attach scores via `scoresByEntryKey()`
- Filter by `predicted_label` ∈ label filter
- Optional shared sort: relevance desc, then `published_date` desc (same as `ExportController::briefing()`)

**Repository**

- Prefer **scoped feed queries** (separate `WHERE` per Feeds / Media / Scraper), reusing predicates aligned with `EntryRepository::fetchFeedItemsForModule()` — avoid one `feed_item` query + PHP split near the 2000 cap

**Refactor**

- `src/Controller/ExportController.php` — delegate to gatherer; **no change** to export HTTP contracts (`export_briefing` still all `feed_item` families unless we explicitly decide otherwise later)

**Acceptance**

- `export_briefing` output unchanged (smoke or diff one run)
- Gatherer with only Media on returns only `category = media` feed items

---

## Slice 3 — `GeminiBriefingService` ✅

**Goal:** Encapsulate outbound Gemini call.

**New:** `src/Service/GeminiBriefingService.php`

**Details**

- Read `gemini:api_key` from `SystemConfigRepository`
- `BaseClient` with **90–120s** timeout (UI may wait 10–20s+; Nginx allows 300s)
- `postJson()` → `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key=...`
- Model: `gemini-2.5-flash` (default; `gemini:model` in `system_config`)
- Single-pass full markdown to Gemini (no pre-chunk summarization)
- Retries on 429/5xx and transport errors; `systemInstruction` + `generationConfig` (temp 0.2, max output 8192)
- Default entry limit **200** per module (max 2000)
- Prompt shape: user system prompt + separator + “Seismo briefing (Markdown)” + `MarkdownBriefingFormatter` output
- Parse `candidates[0].content.parts[0].text`
- Errors: generic message to client; log details; **never** return API key or raw upstream body

**Acceptance**

- Missing key → clear exception/message for controller
- Non-2xx / malformed JSON → handled without fatal

---

## Slice 4 — Controller, routes, router CSRF list ✅

**Goal:** Page + JSON generate endpoint.

**New:** `src/Controller/AiBriefingController.php`

**`show()`**

- `CsrfToken::field()`, `$basePath`, defaults (all six modules on, lookback 7, include-important off, default system prompt, limit 2000)
- `require` `views/briefing_builder.php`
- `$activeNav = 'briefing_builder'`

**`generate()`** (mirror `MagnituLabelUiController::save`)

- `Content-Type: application/json; charset=utf-8`
- POST only → 405
- `CsrfToken::verifyRequest(false)` → 403 (no rotation on long AJAX)
- `session_write_close()` after CSRF verify
- Validate POST: module checkboxes, `lookback_days` ∈ {1,…,7} (invalid → 2), `item_count` ∈ {5,7,10,12,15} (invalid → 5), `include_important`, `disregard_magnitu`, `system_prompt` (min/max length), `limit`
- Pipeline: `gatherBriefingContext()` → `MarkdownBriefingFormatter::format(..., includeEntryIds: true)` → `GeminiBriefingService` (`responseSchema` + envelope; falls back if model rejects schema)
- Response: `{ "ok": true, "text": "...", "meta": { "entry_count", "since", "modules", "labels" } }` or `{ "ok": false, "error": "..." }`

**Routes** (`routes_mothership.inc.php` and `routes_satellite.inc.php`)

```php
$router->register('briefing_builder', AiBriefingController::class . '::show', true);
$router->register('briefing_builder_prepare', AiBriefingController::class . '::prepare', false);
$router->register('briefing_builder_generate', AiBriefingController::class . '::generate', false);
$router->register('briefing_builder_save_prompt', AiBriefingController::class . '::savePrompt', false);
$router->register('save_briefing_prompt', AiBriefingController::class . '::savePromptLibrary', false);
$router->register('delete_briefing_prompt', AiBriefingController::class . '::deletePromptLibrary', false);
```

**`src/Http/Router.php`**

- Add `'briefing_builder'` to `READONLY_KEEP_SESSION_FOR_CSRF`

**Auth**

- No `AuthGate` whitelist entry — session login when admin password enabled (same as `label`)

**Acceptance**

- `?action=briefing_builder` loads when logged in / auth off
- POST without CSRF → 403 JSON

---

## Slice 5 — View, JS, navigation ✅

**Goal:** Usable UI.

**New:** `views/briefing_builder.php`

- `partials/site_header.php`
- Six module checkboxes (Feeds / Media / Scraper / Mail / Lex / Leg), all checked by default; optional All / None shortcuts (Filter-style)
- UI copy: “What gets selected” panel + Relevance field (Highlights tier; optional important band below threshold)
- Lookback: 1 / 3 / 7 days
- Limit: number input, max 2000
- System prompt `<textarea>` (sensible default)
- Generate button + output `<div>`
- Hidden CSRF; `fetch` to `briefing_builder_generate` with loading state; render result with `textContent` (not unsanitized `innerHTML`)

**Edit:** `views/partials/site_header.php`

- Nav link after Highlights: `?action=briefing_builder` (mothership and satellites)

**Acceptance**

- Generate shows loading, then text or error
- Turning off all modules → validation error before Gemini call

---

## Slice 6 — Verification & PR checklist

**Commands**

- `php -l` on all touched PHP files

**Manual smoke**

1. Settings → General: save Gemini key; blank submit keeps key
2. Open Briefing Builder from nav
3. Generate with defaults
4. Toggle off Media only → summary should exclude media-category items
5. Enable “Also include important” → more entries in markdown context
6. CSRF failure (stale token) → 403 JSON
7. Missing API key → JSON error pointing to Settings
8. Empty window → formatter “no entries”; LLM still returns something sensible

**PR description (required)**

- **Risk:** Gemini cost/latency; very large prompts when limit = 2000 across six modules; secrets in `system_config`
- **Rollback:** Remove routes/controller/view/service; delete `gemini:api_key` row optional
- **Satellite impact:** **No** for v1 (mothership routes only)

---

## Follow-ups (not v1)

- Satellite routes + cross-DB behaviour note
- Filter-level pills (per feed category, lex source, email tag)
- `gemini:model` in settings
- Align `export_briefing` with six-module scopes if external consumers need parity
- Source config export: document whether `gemini:api_key` is included in bundle (sensitive)

---

## Reference implementations in repo

| Pattern | Location |
|---------|----------|
| Export briefing pipeline | `src/Controller/ExportController.php` |
| Markdown output | `src/Formatter/MarkdownBriefingFormatter.php` |
| AJAX + CSRF JSON | `src/Controller/MagnituLabelUiController.php` |
| HTTP client | `src/Service/Http/BaseClient.php` |
| Secret in settings | `views/partials/settings_mail.php` (OAuth secret) |
| Feed module partitions | `src/Repository/EntryRepository.php` (`fetchFeedItemsForModule`) |
| Media module docs | `docs/media-module.md` |
