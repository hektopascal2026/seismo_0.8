# Seismogramm

Seismogramm is the greenfield rebuild of Seismo's AI briefing and researcher pipeline. It introduces a clean decomposed service layer, separate from the legacy Researcher, and features a simplified preset-driven GUI.

## Route List

* `?action=seismogramm` (GET) — Main GUI.
* `?action=seismogramm_prepare` (POST) — AJAX gather count validator.
* `?action=seismogramm_generate` (POST) — Briefing generator.

## Core Presets

1. **Briefing** (Standard selection, all sources):
   Renders a high-level executive summary summarizing top-priority entries.
2. **Blindspot** (Relational selection, Lex/Leg/Media sources):
   Analyzes legislative/parliamentary updates that have no corresponding coverage in media or scraper feeds.
3. **Research** (Standard selection, custom query query input):
   Synthesizes entries relating strictly to a search query, bypassing standard score thresholds.

## Component Layout

```
SeismogrammController
 └── SeismogrammRequestContext (parses filter requests)
 └── SeismogrammOrchestrator
      ├── ResilientGeminiClient (cURL parallel calls, retries, schemas)
      ├── StandardSelectionEngine
      ├── TournamentSelectionEngine
      ├── RelationalSelectionEngine
      └── SummaryBriefingEngine
```

---

## Technical Features

### Dynamic XML Context Filtering
During Pass 2 (Briefing Generation), the context XML pool is filtered down to include only the selected `used_entry_keys`, reducing input tokens and improving content accuracy.

### Citations
Referenced source entry cards are automatically parsed from the generated Markdown summary and rendered directly below it, ensuring transparency and validation capabilities.
