# Multi-story email digests

**Status:** Implemented in **Seismo 0.8.4**. See README release notes and About → History (Era X). Operator path: Newsletter → Sources → **AI Split Configurator** → **Apply Split Config** (reprocesses stored mail).

Original design note (May 2026) below — approach **B** (fan-out child `emails` rows) was chosen.

## Problem

Today: one IMAP/Gmail message → one `emails` row → one timeline entry (`entry_type = email`, `entry_id = emails.id`).

Digest newsletters flatten to a single `text_body`. Processors like `EuroparlPressProcessor` only strip boilerplate and set `derived_title` for the **first** headline; remaining stories stay buried in one card.

`NewsletterBodyExtractor` intentionally avoids Readability (article heuristic, not table layouts). Parsing happens **after** plain-text conversion, so structure is already lost.

## Product goal

- Each story in a digest should be **browsable, scorable, and favouritable** on its own (if we care about per-story signals).
- Reprocess (`EmailSubscriptionReprocessService`) must re-split deterministically from stored `html_body` when possible.
- Satellites: any new table or entry shape must respect `entryTable()` rules and migration discipline.

## Options (pick one before coding)

| Approach | Pros | Cons |
|----------|------|------|
| **A. `email_digest_items` table** | Clean parent/child; keeps raw message intact | New read paths; scoring/favourites/Magnitu need `(parent_id, item_index)` or synthetic ids; migration + satellite sync |
| **B. Fan-out N `emails` rows** | Reuses existing entry model | Duplicate metadata; shared `message_id`; awkward “one message” semantics |
| **C. JSON sub-stories on parent + expand in UI** | No schema until scoring per story is needed | `entry_scores` still one row per message; Magnitu/export complexity |
| **D. Virtual expansion only in `EntryRepository::wrapEmail`** | Zero DB change | Cannot score/favourite individual stories |

**Recommendation when implementing:** Start with **HTML-first splitting at ingest** into either **A** or **B**. Prefer **A** if per-story scoring is in scope; prefer **C** for a fast UI-only win.

Do **not** split on regex over `text_body` alone — use `html_body` (tables, `tr`, story wrappers) via DOM traversal, same stack as mail sanitization (`EmailHtmlSanitizer`).

## Suggested pipeline

```
html_body (stored)
    → subscription-specific DigestSplitter (interface, like EmailBodyProcessor)
    → list<{ title, body, link?, sort_order }>
    → persist items (table or metadata)
    → timeline layer emits N cards OR 1 card with structured sections
```

Register splitters in `EmailBodyProcessorRegistry` (or sibling `EmailDigestSplitterRegistry`) keyed by `body_processor` / sender.

## Touch points (grep before coding)

- `EmailIngestRepository::prepareRow` — split **after** sanitize, **before** body cap
- `EntryRepository::wrapEmail` / `getEmailModuleTimeline`
- `EntryScoreRepository` — email body column pick
- `MagnituExportRepository`
- Admin: Subscriptions page (`body_processor` choices)
- Tests: extend `EuroparlPressProcessorTest` or add fixture HTML per sender

## Out of scope for digest work

- Readability on newsletter HTML (wrong tool)
- Headless browser for email
- Changing scraper article extraction (see `ScraperContentExtractor` + Readability there)

## Acceptance sketch

- ep today (or one bund digest fixture): n stories → n timeline rows (or n visible sections with stable ids)
- reprocess idempotent
- single-story press mail unchanged (splitter returns one item)
- satellite: document whether digest items replicate or stay mothership-only

## Refinement and Manual Adjustments (June 2026)

When the automatically generated selectors for a digest require fine-tuning, the operator can manually refine them using the **Refine rules** interface. The configurator supports:
1. **Exclusions (Noise)**: Elements marked as noise are appended to `exclude_titles` and automatically skipped.
2. **Merges (Glue)**: Adjacent blocks can be glued together. Since CSS selectors cannot always group disparate/sibling table cells or elements natively without matching children separately, the splitter supports manual **`glue_rules`** in the `split_rules` config:
   ```json
   "glue_rules": [
     {
       "first_title": "First Block Title",
       "second_title": "Second Block Title"
     }
   ]
   ```
   Post-splitting, `EmailDigestSplitterService` processes these rules sequentially, combining the matching adjacent fragments (merging their HTML markup and joining text bodies with double newlines).

To assist Gemini during refinement generation, `EmailHtmlSanitizer` preserves `class` and `id` attributes. Additionally, the refinement loop merges manual feedback before verification checking, allowing the split verification count check to succeed when glue rules are active.

