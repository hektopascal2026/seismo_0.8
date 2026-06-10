# Seismogramm

Seismogramm is the greenfield rebuild of Seismo's AI briefing and researcher pipeline. It introduces a clean decomposed service layer, separate from the legacy Researcher, and features a simplified preset-driven GUI.

## Route List

* `?action=seismogramm` (GET) — Main GUI.
* `?action=seismogramm_prepare` (POST) — AJAX gather count validator.
* `?action=seismogramm_generate` (POST) — Briefing generator.

## Core Presets

1. **Briefing** — Standard selection (tournament when pool &gt; 80). Magnitu highlights tier; persona/goal can override score ties. All sources.
2. **Blindspot** — Relational tournament on Lex+Leg; global title fingerprint (Media/Feeds/Scraper); negative-space protocol; persona required.
3. **Research** — Tournament always; disregards Magnitu; Magnitu snippets on; pool up to 300; topic query required.

Context caching: shared global fingerprint is uploaded once and referenced by parallel batch requests when large enough (&gt;50k chars).

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
