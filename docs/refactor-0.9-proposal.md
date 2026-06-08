# Seismo 0.9 Refactoring & Simplification Proposal

* **Date**: June 8, 2026
* **Status**: Draft Proposal
* **Target Version**: Seismo 0.9

---

## 1. Executive Summary

As Seismo has expanded over the past months, the complexity of data ingestion, LLM-based researcher processing, and dashboard timeline rendering has increased. Key areas of the application are now bottlenecked by in-memory data processing, or are built as tightly-coupled monoliths.

This proposal outlines the architectural blueprint for **Seismo 0.9**, focusing on four main objectives:
1. **SQL-Native Pagination and Merging**: Offloading multi-family item collection and sorting from PHP memory to SQL to reduce database load and memory usage.
2. **Decomposing `GeminiResearcherService`**: Separating raw HTTP multi-transport, token packaging, and selection/summary pipelines into dedicated, testable components.
3. **Strategy-Based URL Extraction**: Converting hardcoded, domain-specific email heuristics into a clean strategy pattern registry.
4. **Simplifying Digest Splitting**: Leveraging the available `symfony/css-selector` component to replace custom regex CSS-to-XPath translation logic.

---

## 2. Refactoring Target 1: SQL-Native Timeline Merging & Pagination

### Current Bottleneck
In `EntryRepository.php`, the methods `getLatestTimeline()` and `searchTimeline()` merge items across four tables (`feed_items`, `emails`, `lex_items`, `calendar_events`).
* **The Problem**: PHP queries up to 1,000 rows *per table* (4,000 rows total) on timeline filters, instantiates arrays for all of them, deduplicates them by normal URL rules, sorts them, and slices the page.
* **The Consequences**: High database payload sizes, redundant query execution, high memory allocations, and CPU cycles spent hydrating rows that are ultimately sliced off by pagination.

```
+------------+     +----------+     +----------+     +----------------+
| Feed Items |     |  Emails  |     | Lex Items|     |Calendar Events |
+-----+------+     +----+-----+     +----+-----+     +-------+--------+
      | (1000 rows)     | (1000)         | (1000)            | (1000)
      +-----------------+----------------+-------------------+
                                 |
                      [Hydrated in PHP Memory]
                                 |
                        [Deduplicated by URL]
                                 |
                          [Sorted in PHP]
                                 |
                        [Sliced (e.g. 20)]
```

### 0.9 Design Specification
We will shift sorting and pagination into SQL using a unified `UNION` query. Since database engines are optimized for sorting and indexing, database query latency will decrease from O(N) to O(log N) relative to timeline depth.

#### Core Query Strategy:
```sql
SELECT 
    'feed_item' AS entry_type, 
    fi.id AS entry_id, 
    fi.published_date AS entry_date, 
    es.relevance_score,
    fi.link_normalized AS link_key
FROM feed_items fi
LEFT JOIN entry_scores es ON es.entry_type = 'feed_item' AND es.entry_id = fi.id
WHERE fi.hidden = 0

UNION ALL

SELECT 
    'email' AS entry_type, 
    e.id AS entry_id, 
    e.date_utc AS entry_date, 
    es.relevance_score,
    e.derived_title AS link_key -- Or normalized webview URL mapping
FROM emails e
LEFT JOIN entry_scores es ON es.entry_type = 'email' AND es.entry_id = e.id
WHERE e.hidden = 0

-- ORDER & LIMIT applied globally in SQL
ORDER BY relevance_score DESC, entry_date DESC
LIMIT :limit OFFSET :offset;
```

#### Hydration Optimization:
1. Fetch only the exact page ids needed (e.g., 20 rows containing `(entry_type, entry_id)` tuples).
2. Group ids by type: `['feed_item' => [...], 'email' => [...]]`.
3. Perform a single batch query per active type for the target page ids.
4. Hydrate and map back to timeline order.

---

## 3. Refactoring Target 2: Decomposing `GeminiResearcherService`

### Current Bottleneck
`GeminiResearcherService` is a 102KB monolith that acts as:
1. **HTTP Client**: Manages raw Curl handles, `curl_multi` parallel executions, and custom API-key headers.
2. **Context Packer**: Converts database rows to target XML/Markdown schemas and calculates token budgets/body limits.
3. **Pipeline Coordinator**: Runs tournament loops, batched fallbacks, and single selection passes.
4. **Retry Engine**: Retries failed tournament batches via split-halving.

### 0.9 Design Specification
Decompose the monolith into clean, single-responsibility components:

```
                  +--------------------------------+
                  |    GeminiResearcherService     |  <-- Main Entry Point
                  +---------------+----------------+
                                  |
         +------------------------+------------------------+
         |                        |                        |
+--------v-------+       +--------v-------+       +--------v-------+
| ContextPacker  |       |PipelineStrategy|       |  Transport     |
| (XML, Tokens)  |       | (Tournament,   |       |  (CurlMulti,   |
+----------------+       |  Batch, Single)|       |   Rate Limits) |
                         +----------------+       +----------------+
```

1. **`Seismo\Service\Http\GeminiTransportClient`**
   - Handles multi-curl requests, payload JSON encoding/decoding, token timeouts, and exponential backoffs for RPM/TPM limits.
2. **`Seismo\Service\Researcher\ContextPacker`**
   - Takes raw entry lists, formats them as XML/Markdown via target presenters, and handles selective truncation when context budgets are exceeded.
3. **`Seismo\Service\Researcher\SelectionStrategyInterface`**
   - Declares `public function select(array $entries, int $targetCount): array`.
   - Implementing classes:
     - `SinglePassSelection`: Standard lookup for small sets.
     - `TournamentSelection`: Manages parallel chunking, championship passes, and handles split-halving retries.
     - `BatchedSelection`: Manages rate-limited sequential chunk passes.

---

## 4. Refactoring Target 3: Strategy-Based Email WebView Extractor

### Current Bottleneck
`EmailWebViewUrlExtractor.php` contains a series of domain-specific helper methods to locate webview links for specific swiss/EU newsletters (e.g., `isAdminChNewnsbUrl`, `isEuroparlPressRoomUrl`, `isParlamentChNewsUrl`, etc.) as static functions inside a single class. As more newsletter formats are supported, this file will continue to grow and become harder to test.

### 0.9 Design Specification
Refactor the parsing rules into isolated strategies registered in a central extractor.

#### Implementation Architecture:
```php
namespace Seismo\Core\Mail\Extraction;

interface EmailUrlExtractorStrategyInterface
{
    /**
     * Determine if this strategy can extract URLs from the given email template.
     */
    public function supports(string $html, string $plain, array $metadata): bool;

    /**
     * Extract the browser/web view URL from the email bodies.
     */
    public function extract(string $html, string $plain, array $metadata): ?string;
}
```

We will create strategy implementations for specific newsletters:
* `AdminChNewsletterStrategy`
* `EuroparlPressStrategy`
* `EuCommissionPressStrategy`
* `FallbackRegexStrategy` (for simple webview link matching)

A central registry class `EmailWebViewUrlExtractor` will run through the registered strategies in priority order, resolving the first matching URL.

---

## 5. Refactoring Target 4: Simplifying DOM/CSS Queries in Digest Splitting

### Current Bottleneck
`EmailDigestSplitterService.php` contains a custom string converter `cssToXPath` designed to translate CSS selectors to XPath queries (converting tags, classes, and attributes manually). It also contains a state machine that handles node traversal and element matching.

### 0.9 Design Specification
Seismo has `symfony/css-selector` defined in `composer.json`. We will deprecate `cssToXPath` and replace it with Symfony's robust CSS translation component.

#### Refactored Selectors:
```php
use Symfony\Component\CssSelector\CssSelectorConverter;

$converter = new CssSelectorConverter();
$xPathQuery = $converter->toXPath('div.story-card > h2.title');
```

This reduces code size in `EmailDigestSplitterService` by removing the manual parsing routines and eliminates potential edge-case bugs associated with custom attribute/class parsing.

---

## 6. Implementation Plan & Execution Phases

Refactoring will be split into iterative phases to ensure backward compatibility and continuous verification:

* **Phase 1: Query & Sorting Offloading**: Refactor `EntryRepository` to fetch ID pairs and execute batched hydrations. Verify that timeline orders remain unchanged using unit tests.
* **Phase 2: Gemini Service Decomposition**: Extract `GeminiTransportClient` and verify that AI Researcher runs correctly.
* **Phase 3: Selector Library Integration**: Integrate `symfony/css-selector` into `EmailDigestSplitterService` and verify split outcomes.
* **Phase 4: Extractor Registry Pattern**: Refactor email webview heuristics into distinct strategy classes.
