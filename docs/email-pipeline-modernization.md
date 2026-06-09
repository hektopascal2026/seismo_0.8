# Email Pipeline Modernization - Refactor Specification (v0.9)

This document specifies the refactoring strategy for Seismo's email ingestion, sanitization, and digest splitting architecture.

---

## 1. Architectural Summary & Objectives

The email processing flow in Seismo handles high-throughput ingestion from IMAP and Gmail API, sanitizes HTML layout structures for previews/analysis, and splits digest newsletters into discrete, database-backed timeline entries. 

The current implementation suffers from architectural debt:
1. **Non-Standard MIME Parsing**: Native PHP `ext-imap` calls inside `ImapMailFetchService` and hand-rolled JSON payload walkers in `GmailMessageParser` are fragile and require compilation variables that limit container portability.
2. **Brittle HTML Parsing**: Native `DOMDocument::loadHTML` fails to conform to the HTML5 standard, leading to rendering errors on modern marketing tables and template structures.
3. **Overengineered Layout Traversal**: Custom CSS-to-XPath conversions, recursive tree traversals (`innermostStoryNodes`), and class regex matching logic are used where a standard utility crawler fits best.
4. **Configuration Pollution**: Serialized layout configurations are coupled directly to the `email_subscription` table inside a single JSON blob.

### Target Architecture Overview

```
                      +------------------+      +------------------+
                      |    IMAP Stream   |      |    Gmail API     |
                      +--------+---------+      +--------+---------+
                               |                         |
                               +------------+------------+
                                            | (Raw RFC 822 / MIME Stream)
                                            v
                               +----------------------------+
                               | zbateson/mail-mime-parser  | (Pure-PHP)
                               +------------+---------------+
                                            |
                                            v
                               +----------------------------+
                               |  EmailIngestNormalizer     |
                               |  & Masterminds/HTML5       | (Standard parsing)
                               +------------+---------------+
                                            |
                                            v
                               +----------------------------+
                               |    EmailHtmlSanitizer      | (Symfony HTML Sanitizer)
                               +------------+---------------+
                                            |
                                            v
                               +----------------------------+
                               | EmailDigestSplitterService | (Symfony DomCrawler &
                               |                            |  Relational DB Schema)
                               +----------------------------+
```

---

## 2. Detailed Technical Specification

### A. Core Library Integrations

We introduce the following dependencies to standardise HTML5 parsing, DOM walking, email parsing, and sanitization:
* **`zbateson/mail-mime-parser`**: A pure-PHP, stream-based MIME parser to handle multi-part bodies, attachments, headers, and encoding conversions without native binaries.
* **`masterminds/html5`**: A compliant HTML5 parser replacing native PHP `DOMDocument` load hacks.
* **`symfony/dom-crawler`**: Provides a standard DOM navigation API supporting modern CSS selectors.
* **`symfony/html-sanitizer`**: Fast, customisable, and highly optimized sanitization for PHP 8+.

---

### B. Ingest Standardization

Instead of extracting bodies inside fetch services using custom base64 string manipulation and header decoding:
1. Both `ImapMailFetchService` and `GmailApiInboxClient` retrieve the **raw** MIME message stream.
2. The stream is parsed by `MailMimeParser`.
3. Headers, HTML bodies, text bodies, and metadata are extracted uniformly.

```php
use ZBateson\MailMimeParser\MailMimeParser;

$parser = new MailMimeParser();
$message = $parser->parse($rawMessageStream);

$subject = $message->getHeaderValue('Subject');
$fromEmail = $message->getHeader('From')->getAddresses()[0]->getEmail();
$htmlBody = $message->getHtmlContent();
$textBody = $message->getTextContent();
```

---

### C. Sanitizer & Tracking Cleanup

* **`EmailHtmlSanitizer`**: Swaps `ezyang/htmlpurifier` for `Symfony\Component\HtmlSanitizer\HtmlSanitizer`.
* **HTML Loading**: Uses `Masterminds\HTML5` inside a helper `HtmlParser` to load email markup safely.
* **Tracking Cleanup**: The hardcoded domain lists in `EmailHtmlSanitizer` and `EmailTrackingUrl` are unified to query [EmailTrackingUrl::isRedirectTrackingUrl()](file:///Users/oliverfuchs/Documents/GitHub/seismo/seismo_0.8/src/Core/Mail/EmailTrackingUrl.php#L18) directly.

---

### D. Relational Schema for Newsletter Splitting Rules

Instead of storing JSON strings in `email_subscription.digest_split_config`, split rules are moved into a clean relational layout to track modifications, versioning, and match criteria natively.

#### Schema Draft:
```sql
CREATE TABLE newsletter_sender (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    email_address VARCHAR(255) NOT NULL UNIQUE,
    sender_name VARCHAR(255) NULL
);

CREATE TABLE newsletter_template (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    sender_id INTEGER NOT NULL,
    template_name VARCHAR(255) NOT NULL,
    active_from DATETIME NOT NULL,
    active_to DATETIME NULL,
    FOREIGN KEY (sender_id) REFERENCES newsletter_sender(id) ON DELETE CASCADE
);

CREATE TABLE template_rule (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    template_id INTEGER NOT NULL UNIQUE,
    split_method VARCHAR(50) DEFAULT 'html_selector',
    story_selector VARCHAR(255) NOT NULL,
    title_selector VARCHAR(255) NULL,
    link_selector VARCHAR(255) NULL,
    body_selector VARCHAR(255) NULL,
    exclude_selectors TEXT NULL, -- JSON list
    exclude_titles TEXT NULL,     -- JSON list
    glue_rules TEXT NULL,         -- JSON list
    FOREIGN KEY (template_id) REFERENCES newsletter_template(id) ON DELETE CASCADE
);
```

A migration script will:
1. Scan `email_subscription` for rows where `digest_split_config` is populated.
2. Resolve or create `newsletter_sender` and `newsletter_template`.
3. Decrypt and load the JSON keys (`story_selector`, `title_selector`, etc.) into `template_rule` records.

---

### E. DomCrawler-Based Splitter

`EmailDigestSplitterService` will load HTML markup via `HtmlParser` and wrap the document in a `Crawler`. It extracts stories using CSS selectors, removing legacy string matching:

```php
use Symfony\Component\DomCrawler\Crawler;

$crawler = new Crawler($html5ParsedDocument);

// Find story elements natively with standard CSS selectors
$storyNodes = $crawler->filter($rule->story_selector);

foreach ($storyNodes as $node) {
    $subCrawler = new Crawler($node);
    
    // Extract titles, links, and body texts with standard sub-queries
    $title = $rule->title_selector ? $subCrawler->filter($rule->title_selector)->text() : '';
    $link = $rule->link_selector ? $subCrawler->filter($rule->link_selector)->attr('href') : null;
    
    // Apply sanitization & cleanup...
}
```

---

## 3. Implementation Phases & Slices

* **Slice 1 (Foundation)**: Add libraries to `composer.json`, create `HtmlParser` using `Masterminds\HTML5`, and replace `HTMLPurifier` with `Symfony\Component\HtmlSanitizer`.
* **Slice 2 (Ingestion Refactor)**: Rewrite `ImapMailFetchService` and `GmailMessageParser` to parse raw email streams via `zbateson/mail-mime-parser`.
* **Slice 3 (Database Schema)**: Apply database migrations and execute the migration script transferring existing JSON rules to relational layouts.
* **Slice 4 (Splitter Modernization)**: Rewrite `EmailDigestSplitterService` utilizing `DomCrawler` and CSS selectors, linking directly to the new database rules.
* **Slice 5 (Verification)**: Run unit tests and execute manual split comparisons on previously ingested emails to guarantee 100% regression-free outputs.
