<?php

namespace {
    if (!function_exists('isSatellite')) {
        function isSatellite(): bool { return false; }
    }
    if (!function_exists('entryTable')) {
        function entryTable(string $t): string { return "`{$t}`"; }
    }
    if (!function_exists('entryDbSchemaExpr')) {
        function entryDbSchemaExpr(): string { return "'main'"; }
    }
    if (!function_exists('getEmailTableName')) {
        function getEmailTableName(): string { return 'emails'; }
    }
    if (!function_exists('seismo_email_timeline_unix')) {
        function seismo_email_timeline_unix(array $row): int {
            return \Seismo\Util\TimelineEntryDatetime::emailUnix($row);
        }
    }
    if (!function_exists('seismo_format_wrapper_card_clock')) {
        function seismo_format_wrapper_card_clock(array $wrapper): string {
            return \Seismo\Util\TimelineEntryDatetime::formatWrapperCardClock($wrapper);
        }
    }
}

namespace Seismo\Tests {

    use PHPUnit\Framework\TestCase;
    use PDO;
    use Seismo\Repository\EntryRepository;

    final class EntryRepositoryDigestTest extends TestCase
    {
        private PDO $pdo;

        protected function setUp(): void
        {
            $this->pdo = new PDO('sqlite::memory:');
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Register MySQL functions in SQLite
            $this->pdo->sqliteCreateFunction('SUBSTRING_INDEX', function ($str, $delim, $count) {
                if ($str === null || $delim === null || $count === null) {
                    return null;
                }
                $parts = explode($delim, $str);
                if ($count > 0) {
                    return implode($delim, array_slice($parts, 0, $count));
                } elseif ($count < 0) {
                    return implode($delim, array_slice($parts, $count));
                }
                return '';
            }, 3);

            $this->pdo->sqliteCreateFunction('CONCAT', function (...$args) {
                return implode('', $args);
            });

            $this->pdo->sqliteCreateFunction('SUBSTRING', function ($str, $pos, $len = null) {
                if ($len === null) {
                    return substr($str, $pos - 1);
                }
                return substr($str, $pos - 1, $len);
            });

            $this->pdo->sqliteCreateFunction('JSON_UNQUOTE', function ($val) {
                return $val;
            });

            $this->pdo->sqliteCreateFunction('STR_TO_DATE', function ($str, $format) {
                return $str;
            });

            // Create emails table schema in SQLite (simplified)
            $this->pdo->exec("
                CREATE TABLE emails (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    parent_email_id INTEGER DEFAULT NULL,
                    email_subscription_id INTEGER DEFAULT NULL,
                    subject VARCHAR(255),
                    derived_title VARCHAR(255),
                    from_email VARCHAR(255),
                    from_name VARCHAR(255),
                    from_addr VARCHAR(255),
                    to_addr VARCHAR(255),
                    cc_addr VARCHAR(255),
                    text_body TEXT,
                    html_body TEXT,
                    body_text TEXT,
                    body_html TEXT,
                    metadata TEXT,
                    hidden INTEGER DEFAULT 0,
                    date_utc DATETIME,
                    date_received DATETIME,
                    date_sent DATETIME,
                    created_at DATETIME
                )
            ");

            $this->pdo->exec("
                CREATE TABLE feed_items (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    feed_id INTEGER,
                    guid VARCHAR(255),
                    title VARCHAR(255),
                    link VARCHAR(255),
                    description TEXT,
                    content TEXT,
                    author VARCHAR(255),
                    published_date DATETIME,
                    content_hash VARCHAR(32),
                    hidden INTEGER DEFAULT 0,
                    cached_at TIMESTAMP
                )
            ");

            $this->pdo->exec("
                CREATE TABLE feeds (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    url VARCHAR(500),
                    source_type VARCHAR(20),
                    title VARCHAR(255),
                    description TEXT,
                    link VARCHAR(500),
                    category VARCHAR(100),
                    disabled INTEGER DEFAULT 0,
                    extract_full_text INTEGER DEFAULT 0
                )
            ");

            $this->pdo->exec("
                CREATE TABLE lex_items (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    celex VARCHAR(255),
                    title TEXT,
                    description TEXT,
                    document_date DATE,
                    document_type VARCHAR(100),
                    eurlex_url VARCHAR(500),
                    work_uri VARCHAR(500),
                    source VARCHAR(20),
                    content TEXT,
                    fetched_at TIMESTAMP,
                    created_at TIMESTAMP
                )
            ");

            $this->pdo->exec("
                CREATE TABLE calendar_events (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    source VARCHAR(50),
                    external_id VARCHAR(255),
                    title TEXT,
                    description TEXT,
                    event_date DATE,
                    event_end_date DATE,
                    event_type VARCHAR(50),
                    status VARCHAR(30),
                    council VARCHAR(10),
                    url VARCHAR(500),
                    metadata TEXT,
                    fetched_at TIMESTAMP,
                    created_at TIMESTAMP
                )
            ");

            $this->pdo->exec("
                CREATE TABLE scraper_configs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(255),
                    url VARCHAR(500),
                    link_pattern VARCHAR(500),
                    date_selector VARCHAR(500),
                    exclude_selectors TEXT,
                    category VARCHAR(100),
                    disabled INTEGER DEFAULT 0,
                    created_at TIMESTAMP
                )
            ");

            // Create entry_scores table in SQLite
            $this->pdo->exec("
                CREATE TABLE entry_scores (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    entry_type VARCHAR(50),
                    entry_id INTEGER,
                    relevance_score FLOAT,
                    predicted_label VARCHAR(50),
                    explanation TEXT,
                    score_source VARCHAR(50),
                    scored_at DATETIME
                )
            ");

            // Create email_subscriptions table in SQLite
            $this->pdo->exec("
                CREATE TABLE email_subscriptions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    match_type VARCHAR(20),
                    match_value VARCHAR(255),
                    display_name VARCHAR(255),
                    subject_filter VARCHAR(255) DEFAULT NULL,
                    digest_split_config TEXT DEFAULT NULL,
                    module_scope VARCHAR(20) DEFAULT 'mail',
                    disabled INTEGER DEFAULT 0,
                    auto_detected INTEGER DEFAULT 0,
                    split_drift INTEGER DEFAULT 0,
                    strip_listing_boilerplate INTEGER DEFAULT 0,
                    category VARCHAR(255) DEFAULT NULL,
                    show_in_magnitu INTEGER DEFAULT 1,
                    body_processor VARCHAR(255) DEFAULT NULL,
                    cleanup_config TEXT DEFAULT NULL,
                    unsubscribe_url TEXT DEFAULT NULL,
                    unsubscribe_mailto TEXT DEFAULT NULL,
                    unsubscribe_one_click INTEGER DEFAULT 0,
                    item_count INTEGER DEFAULT 0,
                    removed_at DATETIME DEFAULT NULL
                )
            ");

            // Create sender_tags table in SQLite
            $this->pdo->exec("
                CREATE TABLE sender_tags (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    from_email VARCHAR(255),
                    tag VARCHAR(100),
                    disabled INTEGER DEFAULT 0,
                    removed_at DATETIME DEFAULT NULL
                )
            ");
        }

        public function testNewsletterTimelineFiltersOutChildEmailsAndAttachesThem(): void
        {
            // 0. Insert matching subscription row
        $this->pdo->exec("
            INSERT INTO email_subscriptions (match_type, match_value, display_name, module_scope, disabled, auto_detected)
            VALUES ('domain', 'nzz.ch', 'NZZ am Morgen', 'newsletter', 0, 0)
        ");

        // 1. Insert parent email
            $this->pdo->exec("
                INSERT INTO emails (id, parent_email_id, subject, derived_title, from_email, text_body, html_body, hidden, date_utc)
                VALUES (10, NULL, 'NZZ am Morgen', 'NZZ am Morgen', 'newsletter@nzz.ch', 'Raw parent email body', '<html>Parent</html>', 0, '2026-06-05 12:00:00')
            ");

            // 2. Insert child emails
            $this->pdo->exec("
                INSERT INTO emails (id, parent_email_id, subject, derived_title, from_email, text_body, html_body, hidden, date_utc)
                VALUES (11, 10, 'Story 1', 'Story 1', 'newsletter@nzz.ch', 'Child story 1 text', '<html>Story 1</html>', 0, '2026-06-05 12:01:00')
            ");
            $this->pdo->exec("
                INSERT INTO emails (id, parent_email_id, subject, derived_title, from_email, text_body, html_body, hidden, date_utc)
                VALUES (12, 10, 'Story 2', 'Story 2', 'newsletter@nzz.ch', 'Child story 2 text', '<html>Story 2</html>', 0, '2026-06-05 12:02:00')
            ");

            // 3. Insert child scores
            $this->pdo->exec("
                INSERT INTO entry_scores (entry_type, entry_id, relevance_score, score_source)
                VALUES ('email', 11, 0.85, 'recipe')
            ");
            $this->pdo->exec("
                INSERT INTO entry_scores (entry_type, entry_id, relevance_score, score_source)
                VALUES ('email', 12, 0.42, 'recipe')
            ");

            $repo = new EntryRepository($this->pdo);

            // Newsletter feed keeps parent rows and nests child stories beneath them.
            $timeline = $repo->getEmailModuleTimeline(10, 0, 'newsletter');

            // We only expect one email entry (the parent ID 10) in the top-level timeline
            $emails = array_values(array_filter($timeline, fn($item) => $item['type'] === 'email'));
            self::assertCount(1, $emails);
            self::assertSame(10, (int)$emails[0]['entry_id']);

            // Check if the child stories are attached to the parent
            $data = $emails[0]['data'];
            self::assertArrayHasKey('child_stories', $data);
            self::assertCount(2, $data['child_stories']);

            // Check that scores are attached to child stories
            self::assertSame(11, (int)$data['child_stories'][0]['id']);
            self::assertNotNull($data['child_stories'][0]['score']);
            self::assertSame(0.85, (float)$data['child_stories'][0]['score']['relevance_score']);

            self::assertSame(12, (int)$data['child_stories'][1]['id']);
            self::assertNotNull($data['child_stories'][1]['score']);
            self::assertSame(0.42, (float)$data['child_stories'][1]['score']['relevance_score']);
        }

        public function testMailTimelineShowsChildStoriesAsStandaloneCards(): void
        {
            $this->pdo->exec("
                INSERT INTO email_subscriptions (match_type, match_value, display_name, module_scope, disabled, auto_detected)
                VALUES ('domain', 'nzz.ch', 'NZZ am Morgen', 'mail', 0, 0)
            ");
            $this->pdo->exec("
                INSERT INTO emails (id, parent_email_id, subject, derived_title, from_email, text_body, html_body, hidden, date_utc)
                VALUES (10, NULL, 'NZZ am Morgen', 'NZZ am Morgen', 'newsletter@nzz.ch', 'Raw parent email body', '<html>Parent</html>', 0, '2026-06-05 12:00:00')
            ");
            $this->pdo->exec("
                INSERT INTO emails (id, parent_email_id, subject, derived_title, from_email, text_body, html_body, hidden, date_utc)
                VALUES (11, 10, 'Story 1', 'Story 1', 'newsletter@nzz.ch', 'Child story 1 text', '<html>Story 1</html>', 0, '2026-06-05 12:01:00')
            ");
            $this->pdo->exec("
                INSERT INTO emails (id, parent_email_id, subject, derived_title, from_email, text_body, html_body, hidden, date_utc)
                VALUES (12, 10, 'Story 2', 'Story 2', 'newsletter@nzz.ch', 'Child story 2 text', '<html>Story 2</html>', 0, '2026-06-05 12:02:00')
            ");

            $repo = new EntryRepository($this->pdo);
            $timeline = $repo->getEmailModuleTimeline(10, 0, 'mail');

            $emails = array_values(array_filter($timeline, fn($item) => $item['type'] === 'email'));
            self::assertCount(2, $emails);
            self::assertSame(12, (int)$emails[0]['entry_id']);
            self::assertSame(11, (int)$emails[1]['entry_id']);
            self::assertEmpty($emails[0]['data']['child_stories'] ?? []);
            self::assertEmpty($emails[1]['data']['child_stories'] ?? []);
        }

        public function testHighlightsShowsChildStoriesNotParentDigest(): void
        {
            $this->pdo->exec("
                INSERT INTO emails (id, parent_email_id, subject, derived_title, from_email, text_body, html_body, hidden, date_utc)
                VALUES (20, NULL, 'Digest parent', 'Digest parent', 'news@example.com', 'Parent body', '<html>Parent</html>', 0, '2026-06-05 10:00:00')
            ");
            $this->pdo->exec("
                INSERT INTO emails (id, parent_email_id, subject, derived_title, from_email, text_body, html_body, hidden, date_utc)
                VALUES (21, 20, 'Child A', 'Child A', 'news@example.com', 'Story A', '<html>A</html>', 0, '2026-06-05 10:01:00')
            ");
            $this->pdo->exec("
                INSERT INTO entry_scores (entry_type, entry_id, relevance_score, score_source)
                VALUES ('email', 20, 0.95, 'recipe')
            ");
            $this->pdo->exec("
                INSERT INTO entry_scores (entry_type, entry_id, relevance_score, score_source)
                VALUES ('email', 21, 0.80, 'recipe')
            ");

            $repo = new EntryRepository($this->pdo);
            $scoreRows = $repo->listResearcherScoreCandidates(0.5, false, 20);

            $emailIds = array_map(
                static fn (array $row): int => (int)$row['entry_id'],
                array_values(array_filter($scoreRows, static fn (array $row): bool => $row['entry_type'] === 'email')),
            );

            self::assertContains(21, $emailIds);
            self::assertNotContains(20, $emailIds);
        }

        public function testSaveAndUpdateDigestSubscription(): void
        {
            $repo = new \Seismo\Repository\EmailSubscriptionRepository($this->pdo);

            // 1. Insert
            $id = $repo->insert([
                'match_type' => 'domain',
                'match_value' => 'nzz.ch',
                'display_name' => 'NZZ Pro',
                'subject_filter' => 'Pro',
                'digest_split_config' => '{"type": "regex", "delimiter": "---"}',
                'category' => 'Business',
                'module_scope' => 'mail',
            ]);
            self::assertGreaterThan(0, $id);

            $row = $repo->findById($id);
            self::assertNotNull($row);
            self::assertSame('Pro', $row['subject_filter']);
            self::assertNull($row['digest_split_config']);

            // 2. Update
            $repo->update($id, [
                'match_type' => 'domain',
                'match_value' => 'nzz.ch',
                'display_name' => 'NZZ Pro v2',
                'subject_filter' => 'Pro v2',
                'digest_split_config' => '{"type": "html_css", "selector_story": ".story"}',
                'category' => 'Business v2',
            ]);

            $rowUpdated = $repo->findById($id);
            self::assertNotNull($rowUpdated);
            self::assertSame('NZZ Pro v2', $rowUpdated['display_name']);
            self::assertSame('Pro v2', $rowUpdated['subject_filter']);
            self::assertNull($rowUpdated['digest_split_config']);
        }

        public function testIndexTimelineNestsNewsletterChildStories(): void
        {
            // Insert subscription row with scope=newsletter
            $this->pdo->exec("
                INSERT INTO email_subscriptions (id, match_type, match_value, display_name, module_scope, disabled, auto_detected)
                VALUES (101, 'domain', 'nzz.ch', 'NZZ am Morgen', 'newsletter', 0, 0)
            ");

            // Insert parent email
            $this->pdo->exec("
                INSERT INTO emails (id, parent_email_id, email_subscription_id, subject, derived_title, from_email, text_body, html_body, hidden, date_utc)
                VALUES (100, NULL, 101, 'NZZ am Morgen Digest', 'NZZ am Morgen Digest', 'newsletter@nzz.ch', 'Parent body', '<html>Parent</html>', 0, '2026-06-05 12:00:00')
            ");

            // Insert child emails
            $this->pdo->exec("
                INSERT INTO emails (id, parent_email_id, email_subscription_id, subject, derived_title, from_email, text_body, html_body, hidden, date_utc)
                VALUES (101, 100, 101, 'Story A', 'Story A', 'newsletter@nzz.ch', 'A body', '<html>A</html>', 0, '2026-06-05 12:01:00')
            ");
            $this->pdo->exec("
                INSERT INTO emails (id, parent_email_id, email_subscription_id, subject, derived_title, from_email, text_body, html_body, hidden, date_utc)
                VALUES (102, 100, 101, 'Story B', 'Story B', 'newsletter@nzz.ch', 'B body', '<html>B</html>', 0, '2026-06-05 12:02:00')
            ");

            $repo = new EntryRepository($this->pdo);

            // Index timeline has pinNewsletterEmails = true
            $filter = new \Seismo\Repository\TimelineFilter(pinNewsletterEmails: true);
            $timeline = $repo->getLatestTimeline(10, 0, $filter);

            $emails = array_values(array_filter($timeline, fn($item) => $item['type'] === 'email'));
            self::assertCount(1, $emails);
            self::assertSame(100, (int)$emails[0]['entry_id']); // Parent
            self::assertCount(2, $emails[0]['data']['child_stories']); // Nested child stories
        }

        public function testFilterTimelineShowsNewsletterChildStoriesIndividually(): void
        {
            // Insert subscription row with scope=newsletter
            $this->pdo->exec("
                INSERT INTO email_subscriptions (id, match_type, match_value, display_name, module_scope, disabled, auto_detected)
                VALUES (201, 'domain', 'nzz.ch', 'NZZ am Morgen', 'newsletter', 0, 0)
            ");

            // Insert parent email
            $this->pdo->exec("
                INSERT INTO emails (id, parent_email_id, email_subscription_id, subject, derived_title, from_email, text_body, html_body, hidden, date_utc)
                VALUES (200, NULL, 201, 'NZZ am Morgen Digest', 'NZZ am Morgen Digest', 'newsletter@nzz.ch', 'Parent body', '<html>Parent</html>', 0, '2026-06-05 12:00:00')
            ");

            // Insert child emails
            $this->pdo->exec("
                INSERT INTO emails (id, parent_email_id, email_subscription_id, subject, derived_title, from_email, text_body, html_body, hidden, date_utc)
                VALUES (201, 200, 201, 'Story A', 'Story A', 'newsletter@nzz.ch', 'A body', '<html>A</html>', 0, '2026-06-05 12:01:00')
            ");
            $this->pdo->exec("
                INSERT INTO emails (id, parent_email_id, email_subscription_id, subject, derived_title, from_email, text_body, html_body, hidden, date_utc)
                VALUES (202, 200, 201, 'Story B', 'Story B', 'newsletter@nzz.ch', 'B body', '<html>B</html>', 0, '2026-06-05 12:02:00')
            ");

            $repo = new EntryRepository($this->pdo);

            // Filter timeline (pinNewsletterEmails = false)
            $filter = new \Seismo\Repository\TimelineFilter(pinNewsletterEmails: false);
            $timeline = $repo->getLatestTimeline(10, 0, $filter);

            $emails = array_values(array_filter($timeline, fn($item) => $item['type'] === 'email'));
            // Standard exportable view hides parents when visible children exist, showing only children.
            self::assertCount(2, $emails);
            self::assertSame(202, (int)$emails[0]['entry_id']); // Child B
            self::assertSame(201, (int)$emails[1]['entry_id']); // Child A
        }
    }
}
