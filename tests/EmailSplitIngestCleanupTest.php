<?php

declare(strict_types=1);

namespace {
    if (!function_exists('isSatellite')) {
        function isSatellite(): bool { return false; }
    }
    if (!function_exists('entryTable')) {
        function entryTable(string $t): string { return "`{$t}`"; }
    }
}

namespace Seismo\Tests {

    use PHPUnit\Framework\TestCase;
    use PDO;
    use Seismo\Repository\EmailIngestRepository;

    final class EmailSplitIngestCleanupTest extends TestCase
    {
        private PDO $pdo;

        protected function setUp(): void
        {
            $this->pdo = new PDO('sqlite::memory:');
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // SQLite implementation of Mysql functions
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

            // Create tables needed
            $this->pdo->exec("
                CREATE TABLE emails (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    parent_email_id INTEGER DEFAULT NULL,
                    email_subscription_id INTEGER DEFAULT NULL,
                    message_id VARCHAR(512) DEFAULT NULL,
                    imap_uid INTEGER DEFAULT NULL,
                    gmail_message_id VARCHAR(255) DEFAULT NULL,
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
        }

        public function testSplitAndIngestStoriesAppliesCleanupConfigToChildStories(): void
        {
            // 1. Insert subscription with cleanup_config containing strip_regexes and strip_listing_boilerplate = 1
            $cleanupConfig = json_encode([
                'strip_regexes' => [
                    '/Ad:.*?!\s*/i',
                    '/Social Share/i'
                ],
                'webview_keywords' => []
            ]);

            $this->pdo->prepare("
                INSERT INTO email_subscriptions (
                    match_type, match_value, display_name, disabled, auto_detected, strip_listing_boilerplate, cleanup_config
                ) VALUES (
                    'email', 'newsletter@example.com', 'Example Digest', 0, 0, 1, ?
                )
            ")->execute([$cleanupConfig]);

            $subId = (int)$this->pdo->lastInsertId();

            // 2. Insert parent email
            $this->pdo->exec("
                INSERT INTO emails (
                    id, email_subscription_id, subject, derived_title, from_email, text_body, html_body, date_utc
                ) VALUES (
                    10, $subId, 'Daily Digest', 'Daily Digest', 'newsletter@example.com', 'Parent raw body',
                    '<html><body>Parent</body></html>', '2026-06-05 12:00:00'
                )
            ");

            // 3. Define digest_split_config
            $splitConfig = [
                'split_rules' => [
                    'split_method' => 'html_selector',
                    'story_selector' => '.story',
                    'title_selector' => 'h2',
                    'link_selector' => 'a',
                    'body_selector' => '.content'
                ]
            ];

            // Prepare parent row mock representation for splitter
            $parentRow = [
                'id' => 10,
                'email_subscription_id' => $subId,
                'from_email' => 'newsletter@example.com',
                'subject' => 'Daily Digest',
                'html_body' => '
                    <html><body>
                        <div class="story">
                            <h2>Story 1 Title</h2>
                            <a href="https://example.com/story1">Link 1</a>
                            <div class="content">
                                Story 1 core text.
                                Ad: buy our product!
                                Social Share this.
                            </div>
                        </div>
                        <div class="story">
                            <h2>Story 2 Title</h2>
                            <a href="https://example.com/story2">Link 2</a>
                            <div class="content">
                                Story 2 core text.
                                Ad: another ad here!
                                Social Share this again.
                            </div>
                        </div>
                    </body></html>
                ',
                'text_body' => 'Text body representation'
            ];

            $repo = new EmailIngestRepository($this->pdo);
            $repo->splitAndIngestStories(10, $parentRow, $splitConfig);

            // 4. Query inserted children
            $stmt = $this->pdo->prepare("SELECT * FROM emails WHERE parent_email_id = 10 ORDER BY id ASC");
            $stmt->execute();
            $children = $stmt->fetchAll(PDO::FETCH_ASSOC);

            self::assertCount(2, $children);

            // 5. Verify that ads and social share strings were stripped from children's text_body
            $child1 = $children[0];
            self::assertSame('Story 1 Title', $child1['subject']);
            self::assertStringContainsString('Story 1 core text.', $child1['text_body']);
            self::assertStringNotContainsString('Ad:', $child1['text_body']);
            self::assertStringNotContainsString('buy our product!', $child1['text_body']);
            self::assertStringNotContainsString('Social Share', $child1['text_body']);

            $child2 = $children[1];
            self::assertSame('Story 2 Title', $child2['subject']);
            self::assertStringContainsString('Story 2 core text.', $child2['text_body']);
            self::assertStringNotContainsString('Ad:', $child2['text_body']);
            self::assertStringNotContainsString('another ad here!', $child2['text_body']);
            self::assertStringNotContainsString('Social Share', $child2['text_body']);
        }
    }
}
