# Seismogramm

Seismogramm is the greenfield rebuild of Seismo's AI briefing and researcher pipeline. It introduces a clean decomposed service layer, separate from the legacy Researcher, and features a simplified preset-driven GUI.

## Route List

* `?action=seismogramm` (GET) — Main GUI.
* `?action=seismogramm_prepare` (POST) — AJAX gather count validator.
* `?action=seismogramm_generate` (POST) — Briefing generator.

## Core Presets

1. **Briefing** — Standard selection (tournament when pool &gt; 80). Magnitu highlights tier; persona/goal can override score ties in every selection pass. All sources.
2. **Blindspot** — Relational tournament on CH Lex+Leg; global title fingerprint from enabled echo sources (Media and Newsletter by default; Feeds/Scraper/Mail optional); negative-space protocol; persona required.
3. **Research** — Tournament when pool &gt; ~35 (batch size); disregards Magnitu; Magnitu snippets on; pool up to 300 per request; newest-first cap ordering by default; topic query required.

**Advanced — cap ordering:** Prioritize highest (Magnitu relevance) or newest (published date) within each source module when the context cap trims the pool. Defaults: Briefing and Blindspot → highest; Research → newest.

Context caching (experimental, advanced settings only): optional checkbox uploads the global title fingerprint once and references it from parallel batch requests when the fingerprint exceeds 50k characters.

## Component Layout

```
SeismogrammController
 └── SeismogrammRequestContext (parses filter requests)
 └── SeismogrammOrchestrator
      ├── ResilientGeminiClient (cURL parallel calls, retries, schemas)
      ├── StandardSelectionEngine
      ├── TournamentSelectionEngine (relational mode = negative-space flag)
      └── SummaryBriefingEngine
```

---

## Technical Features

### Dynamic XML Context Filtering
During Pass 2 (Briefing Generation), the context XML pool is filtered down to include only the selected `used_entry_keys`, reducing input tokens and improving content accuracy.

### Citations
Referenced source entry cards are automatically parsed from the generated Markdown summary and rendered directly below it, ensuring transparency and validation capabilities.

---

## Roadmap (resilience & observability)

Planned work stays preset-native — no port of legacy `GeminiResearcherGenerationMeta::normalize()` or automatic 429 shrink.

### P1 — Tournament batch recovery ✅

- **When:** A parallel tournament batch returns HTTP ≠ 200 or empty `used_entry_keys`.
- **Action:** Retry **that batch only**, **once**, sequentially (same payload; no batch splitting).
- **UX:** `selection_batch_recovered`, `selection_batch_errors`, and `meta_summary_line` (via `SeismogrammPipelineMeta`) when recovery succeeds.
- **Modes:** Research, Blindspot, Briefing (pool &gt; 80).

### P2 — User-initiated 429 retry ✅

- **Research preset:** Fail fast on 429 — suggest shorter lookback / fewer sources. No smaller-pool button.
- **Briefing / Blindspot:** Warning panel + **Retry with smaller pool** button after HTTP 429 (user must click).
- **Retry:** POST `rate_limit_user_retry=1` → halved `maxContextEntries` (floor 20). Meta: `rate_limit_user_retry`, `rate_limit_retry_original_cap`, `meta_summary_line` note.

### P3 — Observability ✅

- **`by_phase` usage** on `ResilientGeminiClient` (`selection`, `summary`, `context_cache`) — in `gemini_usage` and `cost_estimate.by_phase`.
- **`SeismogrammPipelineMeta`:** `normalize()` + `meta_summary_line` + `buildCostEstimate()` — preset-native, not legacy `GeminiResearcherGenerationMeta`.
- **UI:** Preset-aware loading steps during generate; meta summary line and per-phase token hints under the cost estimate.

### Deferred

- **Pass 2 proactive/reactive batching** — legacy splits summary into batches of 5 when output truncates or ≥8 cited items. Seismogramm pass 2 already receives a **subset** XML (5–15 keys). Revisit only if production shows truncation; see README.

### Explicitly out of scope

- `GeminiResearcherGenerationMeta::normalize()` — legacy schema (`selection_profile`, `pro_selection_mode`, inferred `selection_strategy`); contradicts preset-first Seismogramm.
- Automatic orchestrator 429 shrink (legacy re-gathers at 50 entries without user consent).
- Global fingerprint wiring — **Blindspot only** (media/feeds/scraper title index) in `SeismogrammOrchestrator`.
