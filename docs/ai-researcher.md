# AI Researcher — implementation slices

Plan for an in-app page that filters recent Seismo entries and generates a narrative summary via the Gemini API. Vanilla PHP 8.2+, existing MVC/routing — no Laravel/Symfony.

**Status:** complete (slices 1–5). **0.8.4** adds Deep selection modes and digest child-story gather policy. Slice 6 = manual smoke on deploy.

---

## Locked product decisions

| Topic | Decision |
|--------|----------|
| Deploy scope | **Mothership + path satellites** — routes in `routes_mothership.inc.php` and `routes_satellite.inc.php`; satellites use local `entry_scores` / `system_config` |
| Source toggles | **Seven nav-aligned modules**, all on by default: Feeds, Media, Scraper, Mail, **Newsletter**, Lex, Leg (0.8.3 split Mail vs Newsletter; both use `entry_type = email`) |
| Deep selection (0.8.4) | **Standard** — single pass with full bodies + optional `selection_reasoning`. **Tournament** — batch prelims (~35) + championship; global title fingerprint. **Relational (Blind spot)** — tournament + negative-space / cross-module contract; **keys-only** pass 1 JSON. **Pro selection** — optional `gemini-3.1-pro-preview` for pass 1 only. Resolved by `GeminiResearcherSelectionProfileResolver`; verification-heavy prompts auto-append stricter pass-1 rules. |
| Digest children (0.8.4) | Score-first gather and `MagnituExportRepository` email lists exclude parent digests when visible child rows exist (`EmailDigestExportPolicy`). Researcher cites individual story `email` ids, not parent blobs. |
| Nav placement | Top-level drawer link **after Highlights**, before Label |
| Entry cap | **`MagnituExportRepository::BRIEFING_MAX_LIMIT` (200)** per enabled module query (export API stays at 50) |
| Relevance | **Highlights tier** — `relevance_score ≥ alert_threshold` (Settings → Magnitu); optional **“Also include important band below threshold”** (`score > 50%` and `< threshold`). Optional **“Disregard Magnitu (experimental)”** (`disregard_magnitu`) skips score filter and relevance sort (modules + lookback only; newest first). Score-based, not `predicted_label`. |
| Gemini context cap | **`researcher:max_context_entries`** (default **100**) — top entries by sort order are sent to Gemini, with a **fair share per enabled source module** when multiple modules are on (so Lex/Leg rows are not dropped because Feeds score higher). Response meta: **`entries_sent_to_gemini`**, **`entries_omitted_by_cap`**, **`entries_eligible_before_cap`** (legacy **`entry_count`** = sent, **`context_truncated`** = omitted). UI warns when rows are dropped. **`ResearcherModuleGuard`** re-filters and rebuilds XML after the cap; the same guard runs again immediately before every Gemini API call (including rate-limit retry). |
| Gemini model | **`gemini-3.5-flash` only** — `system_config` **`gemini:model`** must match `gemini-3.5*` or it is coerced to the default. No `temperature` in API payloads. |
| Gemini two-pass | **Always on** (no UI toggle): pass 1 = **USER PROMPT** + dynamic entry bodies + JSON (`selection_reasoning` optional, `used_entry_keys` required); pass 2 = **plain Markdown** on selected rows. **Europe/Zurich** “today” anchor in contracts. Pass 2 bans conversational filler. |
| Gemini context pool UI | **`max_context_entries`** on Researcher (20–500, default 100); persisted to `researcher:max_context_entries` on prepare/generate. High values thin per-entry bodies via the shared XML char budget. |
| Entry body budget | **Dynamic per pool:** `MarkdownResearcherFormatter::dynamicEntryBodyMaxChars()` — default floor **2000**, ceiling **12000**, pool budget **500k** chars shared across entries (`entry_body_max_chars` in gather meta). |
| System prompt limit | **32 000** characters (`AiResearcherController::MAX_SYSTEM_PROMPT_LEN`) on generate, save-default, and library save. |
| Gemini pass-2 output | Scales **~4500 visible tokens per cited item** plus **1536** overhead for title/headings (floor 2048, practical cap **65536**). Override via `gemini:max_output_tokens` in `system_config`. **Proactive** batched pass 2 when **≥8** items selected (batch 2, then 1); otherwise one call first, then batched retry (2 keys/call, then 1/call) on truncation (`summary_batch_retry_attempted` / `summary_proactive_batch` in meta). |
| Gemini thinking | **`thinkingLevel` LOW** (selection), **MINIMAL** (summary); HTTP **429** retry uses **MINIMAL**. Skips `thought` parts in responses. Meta: `thinking_selection`, `thinking_summary`, optional `selection_reasoning`. |
| Gemini batching | Batched selection **disabled** for normal runs (`BATCHED_SELECTION_MIN_ENTRIES` very high). Rate-limit retry still uses module-aware cap + guard. |
| Rate-limit fallback | On **HTTP 429**, waits **12s**, caps context to **`rateLimitFallbackMaxEntries()`** (default ≤ **50**), forces **two-pass + batched selection** when pool ≥ **2** (batch **20**, **8s** pause), and retries **once**. UI meta: `rate_limit_fallback`. |
| Shared pipeline | **Yes** — extract `ResearcherEntryGatherer`, refactor `ExportController` to use it |
| API key | `system_config` key **`gemini:api_key`** via Settings → General (per desk on satellites) |
| Saved prompt (default) | `system_config` key **`researcher:system_prompt`** via **Save prompt (default)** on the page (per desk on satellites) |
| Prompt library | `system_config` key **`ai_researcher_prompts`** — JSON list of `{id, name, content}`; seeded with the current default prompt on first visit; **Save to library** / tab delete via `save_researcher_prompt` and `delete_researcher_prompt` |
| Prompt helper | **View: Prompt \| Helper** on Researcher — **`researcher_prompt_helper`** reformulates rough intent via Gemini using `resolveStoredSystemPrompt()` as style reference; save default/library from Helper result syncs the Prompt textarea |
| Researcher item count | UI **`item_count`** (allowed: **5, 7, 10, 12, 15**; default **5**). User prompt = free-form structure; two-pass: selection JSON then Markdown prose with inline `entry_type:entry_id` citations. Context uses XML `<entry>` blocks with `<id>type:id</id>` (`MarkdownResearcherFormatter::FORMAT_XML`). |
| Prepare step | **`researcher_prepare`** — gather-only POST returns `entry_count` before Gemini (UI status line). |

### Why six toggles (not four `entry_type` values)

Media (and Scraper) are not separate `entry_type` values. They are **`feed_item` rows** partitioned by feed metadata:

| Nav module | `entry_type` | Partition |
|------------|--------------|-----------|
| Feeds | `feed_item` | RSS / Substack / Parl. press — excludes `category = media` and scraper paths (same rules as Feeds module in `EntryRepository`) |
| Media | `feed_item` | `feeds.category = 'media'` |
| Scraper | `feed_item` | Scraper sources (same rules as Scraper module) |
| Mail | `email` | `email_subscriptions.module_scope = mail` (or legacy null) |
| Newsletter | `email` | `module_scope = newsletter`; split child stories are separate `emails` rows |
| Lex | `lex_item` | — |
| Leg | `calendar_event` | — |

Filter-page **per-outlet pills** are out of scope for v1; module-level toggles match main nav.

### Router note

`Router::register($action, $handler, $readOnly)` — third argument is **`readOnly`**, not CSRF. CSRF is enforced in the controller with `CsrfToken::verifyRequest()`. `researcher_generate` must use `readOnly: false`; `researcher` uses `readOnly: true` and must be listed in `Router::READONLY_KEEP_SESSION_FOR_CSRF`.

---

## Architecture (layer boundaries)

| Layer | Responsibility |
|-------|----------------|
| `SettingsController` + `settings_general.php` | Persist `gemini:api_key` |
| `ResearcherEntryGatherer` | Fetch + shape entries + scores + label filter (shared with export) |
| `MagnituExportRepository` | SQL only; add scoped `listFeedItemsSince` variants if needed |
| `MarkdownResearcherFormatter` | Markdown for export; XML (`FORMAT_XML`) for AI builder context |
| `GeminiResearcherService` | HTTP to Gemini, parse response, safe errors |
| `ResearcherPromptHelperService` | Single Gemini call to draft a researcher prompt from intent + style reference |
| `AiResearcherController` | Orchestrate `show()` / `generate()` / `promptHelper()` only |
| `views/researcher.php` | Form + vanilla JS `fetch` |

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

## Slice 2 — `ResearcherEntryGatherer` + export refactor ✅

**Goal:** Single pipeline for export researcher and AI builder; no duplicated `ExportController::gatherEntriesAndScores()` logic.

**New:** `src/Service/ResearcherEntryGatherer.php`

**Inputs**

- `?string $since` — from lookback days (1–7 → UTC ISO timestamp)
- `int $limit` — clamp 1 … `MAX_LIMIT`
- Module flags: `includeFeeds`, `includeMedia`, `includeScraper`, `includeEmail`, `includeLex`, `includeLeg` (at least one must be true)
- `ResearcherScoreFilter` (builder) — Highlights tier + optional important band below threshold; export still uses `array $labelFilter` on labels

**Behavior**

- For each enabled module, fetch via `MagnituExportRepository` + `MagnituController::shape*()`
- Attach scores via `scoresByEntryKey()`
- Filter by `predicted_label` ∈ label filter
- Optional shared sort: relevance desc, then `published_date` desc (same as `ExportController::researcher()`)

**Repository**

- Prefer **scoped feed queries** (separate `WHERE` per Feeds / Media / Scraper), reusing predicates aligned with `EntryRepository::fetchFeedItemsForModule()` — avoid one `feed_item` query + PHP split near the 2000 cap

**Refactor**

- `src/Controller/ExportController.php` — delegate to gatherer; **no change** to export HTTP contracts (`export_researcher` still all `feed_item` families unless we explicitly decide otherwise later)

**Acceptance**

- `export_researcher` output unchanged (smoke or diff one run)
- Gatherer with only Media on returns only `category = media` feed items

---

## Slice 3 — `GeminiResearcherService` ✅

**Goal:** Encapsulate outbound Gemini call.

**New:** `src/Service/GeminiResearcherService.php`

**Details**

- Read `gemini:api_key` from `SystemConfigRepository`
- `BaseClient` with **90–120s** timeout (UI may wait 10–20s+; Nginx allows 300s)
- `postJson()` → `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key=...`
- Model: `gemini-3.5-flash` (default; `gemini:model` must be 3.5 family)
- Two-pass: JSON selection then plain Markdown summary (always)
- Retries on 429/5xx and transport errors; `systemInstruction` + `generationConfig` (`thinkingLevel`, max output tokens)
- Default entry limit **200** per module (max 2000)
- Prompt shape: user system prompt + separator + “Seismo researcher (Markdown)” + `MarkdownResearcherFormatter` output
- Parse `candidates[0].content.parts[0].text`
- Errors: generic message to client; log details; **never** return API key or raw upstream body

**Acceptance**

- Missing key → clear exception/message for controller
- Non-2xx / malformed JSON → handled without fatal

---

## Slice 4 — Controller, routes, router CSRF list ✅

**Goal:** Page + JSON generate endpoint.

**New:** `src/Controller/AiResearcherController.php`

**`show()`**

- `CsrfToken::field()`, `$basePath`, defaults (all six modules on, lookback 7, include-important off, default system prompt, limit 2000)
- `require` `views/researcher.php`
- `$activeNav = 'researcher'`

**`generate()`** (mirror `MagnituLabelUiController::save`)

- `Content-Type: application/json; charset=utf-8`
- POST only → 405
- `CsrfToken::verifyRequest(false)` → 403 (no rotation on long AJAX)
- `session_write_close()` after CSRF verify
- Validate POST: module checkboxes, `lookback_days` ∈ {1,…,7} (invalid → 2), `item_count` ∈ {5,7,10,12,15} (invalid → 5), `include_important`, `disregard_magnitu`, `system_prompt` (min 20 / max **32 000** chars), `limit`
- Pipeline: `gatherResearcherContext()` → `MarkdownResearcherFormatter::format(..., includeEntryIds: true)` → `GeminiResearcherService` (`responseSchema` + envelope; falls back if model rejects schema)
- Response: `{ "ok": true, "text": "...", "meta": { "entry_count", "since", "modules", "labels" } }` or `{ "ok": false, "error": "..." }`

**Routes** (`routes_mothership.inc.php` and `routes_satellite.inc.php`)

```php
$router->register('researcher', AiResearcherController::class . '::show', true);
$router->register('researcher_prepare', AiResearcherController::class . '::prepare', false);
$router->register('researcher_generate', AiResearcherController::class . '::generate', false);
$router->register('researcher_save_prompt', AiResearcherController::class . '::savePrompt', false);
$router->register('save_researcher_prompt', AiResearcherController::class . '::savePromptLibrary', false);
$router->register('delete_researcher_prompt', AiResearcherController::class . '::deletePromptLibrary', false);
$router->register('researcher_prompt_helper', AiResearcherController::class . '::promptHelper', false);
```

**`src/Http/Router.php`**

- Add `'researcher'` to `READONLY_KEEP_SESSION_FOR_CSRF`

**Auth**

- No `AuthGate` whitelist entry — session login when admin password enabled (same as `label`)

**Acceptance**

- `?action=researcher` loads when logged in / auth off
- POST without CSRF → 403 JSON

---

## Slice 5 — View, JS, navigation ✅

**Goal:** Usable UI.

**New:** `views/researcher.php`

- `partials/site_header.php`
- Six module checkboxes (Feeds / Media / Scraper / Mail / Lex / Leg), all checked by default; optional All / None shortcuts (Filter-style)
- UI copy: “What gets selected” panel + Relevance field (Highlights tier; optional important band below threshold)
- Lookback: 1 / 3 / 7 days
- Limit: number input, max 2000
- System prompt `<textarea>` (sensible default)
- Generate button + output `<div>`
- Hidden CSRF; `fetch` to `researcher_generate` with loading state; render result with `textContent` (not unsanitized `innerHTML`)

**Edit:** `views/partials/site_header.php`

- Nav link after Highlights: `?action=researcher` (mothership and satellites)

**Acceptance**

- Generate shows loading, then text or error
- Turning off all modules → validation error before Gemini call

---

## Slice 6 — Verification & PR checklist

**Commands**

- `php -l` on all touched PHP files

**Manual smoke**

1. Settings → General: save Gemini key; blank submit keeps key
2. Open Researcher from nav
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
- Align `export_researcher` with six-module scopes if external consumers need parity
- Source config export: document whether `gemini:api_key` is included in bundle (sensitive)

---

## Reference implementations in repo

| Pattern | Location |
|---------|----------|
| Export researcher pipeline | `src/Controller/ExportController.php` |
| Markdown output | `src/Formatter/MarkdownResearcherFormatter.php` |
| AJAX + CSRF JSON | `src/Controller/MagnituLabelUiController.php` |
| HTTP client | `src/Service/Http/BaseClient.php` |
| Secret in settings | `views/partials/settings_mail.php` (OAuth secret) |
| Feed module partitions | `src/Repository/EntryRepository.php` (`fetchFeedItemsForModule`) |
| Media module docs | `docs/media-module.md` |
