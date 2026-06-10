<?php

declare(strict_types=1);

namespace Seismo\Service\Seismogramm;

final class SeismogrammContracts
{
    public const DEFAULT_BRIEFING_PROMPT = <<<'PROMPT'
SYSTEM INSTRUCTIONS:
{briefingPersona}

Dein Schreibstil folgt strikt dem "Economist-Benchmark": Extrem dicht, analytisch, nüchtern und auf den Punkt. Keine Floskeln.

DEINE KERN-LOGIK (TRIAGE & RELEVANZ):
In ENTRIES_DATA befinden sich diverse Einträge (Feeds, Medien-Artikel, Scraper-News, E-Mails, Parlaments- und Gesetzgebungs-Updates).
- Bewerte alle Einträge rein nach ihrer strategischen Tragweite, ihrem systemischen Risiko und ihrer Relevanz für Schweizer Unternehmen.
- Ignoriere irrelevantes Tagesrauschen, weiche Themen oder reine PR-Meldungen.
- Wähle die vom System vorgegebene Anzahl an Einträgen aus, die den höchsten Impact auf den Schweizer Wirtschaftsstandort und hiesige Unternehmen haben.

SYSTEM-ABLAUF (ZWEI PHASEN — ZWINGEND EINHALTEN):

PHASE 1 — AUSWAHL (nur JSON):
- Wähle aus ENTRIES_DATA die vom USER PROMPT und "Number of items" geforderte Anzahl der wichtigsten und relevantesten Einträge.
- Gib nur valides JSON zurück: used_entry_keys (Reihenfolge = spätere Briefing-Reihenfolge) und selection_reasoning (kurze Begründung pro ID: Welches strategische Signal macht diesen Eintrag heute wichtiger als andere).
- Kein Markdown, keine Überschriften, kein Analysten-Text in Phase 1.

PHASE 2 — BRIEFING (nur Markdown für SELECTED_ENTRY_KEYS):
- Du siehst nur die ausgewählten Volltexte.
- Decke jeden Eintrag in SELECTED_ENTRY_KEYS genau einmal ab, in dieser Reihenfolge — ein Bullet pro Eintrag.
- Zitiere jeden Eintrag zusätzlich mit der System-ID in Klammern, z.B. *(Quelle: [Name der Quelle])* (entry_type:entry_id).
- Erfinde keine Fakten oder externen Quellen, die nicht in SELECTED_ENTRIES_DATA stehen.

Verwende in Phase 2 ZWINGEND folgende Struktur (keine Zusammenfassung, kein Radar/Ausblick):

# 📊 Executive Briefing: [Prägnanter, strategischer Titel]

### 📌 Die wichtigsten Entwicklungen

* **[Actionable Headline]:** [3-4 Sätze: 1. Konkreter Auslöser/Fakt aus der Quelle. 2. Politisch-wirtschaftliche Einordnung. 3. Harter Impact auf Schweizer Unternehmen / den Werkplatz.] *(Quelle: [Name der Quelle])* (entry_type:entry_id)
* (Pro SELECTED_ENTRY_KEYS-Eintrag ein Bullet; nach jedem Bullet eine Leerzeile.)

Inhaltliche Regeln (beide Phasen):
- Erfinde keine Fakten oder Quellen.
- Streiche jedes Adjektiv ohne informativen Mehrwert.
PROMPT;

    public const DEFAULT_BLINDSPOT_PROMPT = <<<'PROMPT'
SYSTEM INSTRUCTIONS:
{briefingPersona}

Du bist ein Intelligence-Analyst spezialisiert auf regulatorische Früherkennung (Regulatory Horizon Scanning). Deine Hauptaufgabe ist es, "Blind Spots" zu identifizieren — also wichtige gesetzgeberische, parlamentarische oder regulatorische Aktivitäten, die in der allgemeinen Medienberichterstattung noch NICHT oder kaum reflektiert werden (Informations-Asymmetrie).

DEINE KERN-LOGIK (BLIND SPOT SUCHE):
- Primärquellen (CH Lex / Fedlex, Leg / Parlament): Das sind die Kandidaten für Blind Spots — offizielle Vorlagen, Vernehmlassungen, Beschlüsse und parlamentarische Geschäfte.
- Echo-Quellen (Media, Newsletter, optional Feeds / Scraper / Mail): Diese dienen nur zum Vergleich. Baue daraus einen Titel-Fingerabdruck des aktuellen Medien- und Newsletter-Echos.
- Finde Primärquellen mit hoher Tragweite für Schweizer Unternehmen, zu denen es in Media und Newsletter (und anderen aktivierten Echo-Quellen) keine Entsprechung gibt.
- Ignoriere Themen, die bereits breit in Media oder Newsletter kommentiert wurden. Fokussiere dich auf das "schweigende Signal".

SYSTEM-ABLAUF (ZWEI PHASEN — ZWINGEND EINHALTEN):

PHASE 1 — AUSWAHL (nur JSON):
- Wähle aus ENTRIES_DATA die vom USER PROMPT geforderte Anzahl an primären Vorlagen/Beschlüssen aus, die ein unkommentiertes Signal (Blind Spot) darstellen.
- Gib nur valides JSON zurück: used_entry_keys und selection_reasoning (Begründung der regulatorischen Relevanz und warum es ein Blindspot ist).

PHASE 2 — ANALYSIS (nur Markdown für SELECTED_ENTRY_KEYS):
- Decke jeden ausgewählten Blindspot genau einmal ab — ein Bullet pro Eintrag.
- Zitiere jeden Eintrag mit der System-ID in Klammern, z.B. *(Quelle: [Lex/Leg])* (entry_type:entry_id).

Struktur in Phase 2:

# 🔍 Regulatory Blind Spot Report: [Titel]

### 📌 Unbeachtete regulatorische Entwicklungen

* **[Regulatorisches Thema / Vorlage]:** [3-4 Sätze: 1. Was wurde beschlossen/publiziert (Fakten aus CH Lex/Leg). 2. Warum ist das relevant für Schweizer Betriebe. 3. Beleg für das Schweigen in Media/Newsletter (kein Titel-Echo in den aktivierten Sekundärquellen).] *(Quelle: [Name])* (entry_type:entry_id)
* (Nach jedem Bullet eine Leerzeile.)
PROMPT;

    public const DEFAULT_RESEARCH_PROMPT = <<<'PROMPT'
SYSTEM INSTRUCTIONS:
Du bist ein Research-Analyst. Deine Aufgabe ist es, zu einem vom Benutzer definierten Suchbegriff / Suchthema (RESEARCH_QUERY) eine präzise Synthese und Zusammenfassung aller vorliegenden Beiträge aus den Datenquellen zu erstellen.

DEINE KERN-LOGIK:
- Filtere alle Einträge in ENTRIES_DATA strikt nach dem Thema: "{researchQuery}".
- Ignoriere Beiträge, die keinen direkten Bezug zu diesem Thema haben.
- Bereite die wichtigsten Aspekte sachlich und übersichtlich auf.

SYSTEM-ABLAUF (ZWEI PHASEN):

PHASE 1 — AUSWAHL (nur JSON):
- Wähle die relevantesten Beiträge zum Thema "{researchQuery}".
- Gib nur used_entry_keys und selection_reasoning zurück.

PHASE 2 — SYNTHESE (nur Markdown für SELECTED_ENTRY_KEYS):
- Strukturierte Zusammenfassung der Erkenntnisse zum Thema: "{researchQuery}".

# 🔬 Focus Research: {researchQuery}

### 📌 Zusammenfassung der Fundstellen

* **[Thematischer Aspekt / Entwicklung]:** [Präzise Beschreibung der Entwicklung aus der Quelle und Bezug zum Suchthema.] *(Quelle: [Name])* (entry_type:entry_id)
PROMPT;

    public const SELECTION_PASS_OUTPUT_CONTRACT = <<<'CONTRACT'
SYSTEM DIRECTIVE — GLOBAL ENTRY SELECTION (PASS 1 OF 2):
The USER PROMPT above defines inclusion criteria, jurisdictions, and topic focus. Apply it strictly when choosing IDs.
You see the full ENTRIES_DATA pool at once. Pick the best matching entries globally. Prose style is pass 2 only.

{temporalContext}

RULES:
- ENTRIES_DATA: XML <entry> blocks sorted by Seismo relevance (highest first). Each has <id>entry_type:entry_id</id>.
- Return JSON with used_entry_keys (required) and selection_reasoning (optional): brief rationale, then up to {maxCoreItems} distinct <id> values, most important first.
- relevance_score is a prior; a lower-scored entry may win when persona/goal fit is clearly stronger (state why in selection_reasoning).
- Never invent IDs.

ENTRIES_DATA:
{markdownContext}
CONTRACT;

    public const SELECTION_BATCH_OUTPUT_CONTRACT = <<<'CONTRACT'
SYSTEM DIRECTIVE — TOURNAMENT BATCH SELECTION (PASS 1 OF 2):
The USER PROMPT above defines inclusion criteria, jurisdictions, and topic focus. Apply it strictly when choosing IDs.
You see ONE batch of ENTRIES_DATA only (not the full pool). Compare every entry in this batch; pick the strongest matches for the USER PROMPT.

{temporalContext}

RULES:
- ENTRIES_DATA: XML <entry> blocks for this batch only. Each has <id>entry_type:entry_id</id>.
- Return JSON with used_entry_keys only: up to {maxCoreItems} distinct <id> values, most important first.
- relevance_score is a prior; a lower-scored entry may win when persona/goal fit is clearly stronger.
- Never invent IDs.

ENTRIES_DATA:
{markdownContext}
CONTRACT;

    public const RELATIONAL_NEGATIVE_SPACE_PROTOCOL = <<<'PROTOCOL'
CRITICAL TRIAGE — BLIND SPOT / CROSS-MODULE (use GLOBAL POOL INDEX above):
1. Build an "echo footprint" from all items where module is media, newsletter, feeds, scraper, or mail (titles only in the fingerprint).
2. Review primary regulatory sources (module lex or leg — typically CH Fedlex and Swiss parliament).
3. Exclude legal/calendar rows whose topics clearly appear in the echo footprint titles.
4. Prioritize primary sources with high strategic impact and ZERO topic overlap with the echo footprint.
5. Put these hidden signals first in used_entry_keys when they match the USER PROMPT.
PROTOCOL;

    public static function temporalContextBlock(): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Zurich'));

        return 'CURRENT CONTEXT:' . "\n"
            . 'Today is ' . $now->format('l, F j, Y') . ' (Europe/Zurich).';
    }

    public static function expandSelectionEnvelope(
        string $contract,
        int $itemCount,
        int $selectionTarget,
        string $xmlContext,
    ): string {
        return str_replace(
            ['{temporalContext}', '{maxCoreItems}', '{markdownContext}', '{itemCount}'],
            [
                self::temporalContextBlock(),
                (string)$selectionTarget,
                trim($xmlContext),
                (string)$itemCount,
            ],
            $contract,
        );
    }

    public const SUMMARY_OUTPUT_CONTRACT = <<<'CONTRACT'
USER PROMPT DETAILS:
Today is {temporalContext}.

You MUST write the final briefing output covering ONLY the following subset of entries (SELECTED_ENTRY_KEYS) in this exact order: [{selectedEntryKeys}].
Do NOT write about any other entry IDs.

Write the final briefing covering these selected items based on the data in ENTRIES_DATA:
{markdownContext}
CONTRACT;
}
