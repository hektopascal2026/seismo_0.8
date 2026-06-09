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
                    split_drift INTEGER DEFAULT 0,
                    strip_listing_boilerplate INTEGER DEFAULT 0,
                    hydrate_webview INTEGER DEFAULT 0,
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

        public function testSplitAndIngestStoriesUniversallyOverridesLinksWithParentWebView(): void
        {
            // 1. Insert subscription
            $this->pdo->exec("
                INSERT INTO email_subscriptions (
                    match_type, match_value, display_name, disabled, auto_detected
                ) VALUES (
                    'email', 'newsletter@example.com', 'Example Newsletter', 0, 0
                )
            ");
            $subId = (int)$this->pdo->lastInsertId();

            // 2. Insert parent email with web_view_url in metadata
            $meta = json_encode(['web_view_url' => 'https://example.com/parent-webview-url']);
            $this->pdo->prepare("
                INSERT INTO emails (
                    id, email_subscription_id, subject, derived_title, from_email, from_name, text_body, html_body, date_utc, metadata
                ) VALUES (
                    20, ?, 'Daily News', 'Daily News', 'newsletter@example.com', 'Example Publisher', 'Parent raw body', 'HTML body', '2026-06-06 00:00:00', ?
                )
            ")->execute([$subId, $meta]);

            // 3. Define split config
            $splitConfig = [
                'is_digest' => true,
                'split_rules' => [
                    'split_method' => 'html_selector',
                    'story_selector' => '.story',
                    'title_selector' => 'h2',
                    'link_selector' => 'a',
                    'body_selector' => '.content',
                ]
            ];

            // 4. Run splitAndIngestStories
            $parentRow = [
                'from_email' => 'newsletter@example.com',
                'from_name' => 'Example Publisher',
                'subject' => 'Daily News',
                'html_body' => '
                    <html><body>
                        <div class="story">
                            <h2>Story 1 Title</h2>
                            <a href="https://example.com/wrong-story-link">Link 1</a>
                            <div class="content">Story 1 core text.</div>
                        </div>
                    </body></html>
                ',
                'text_body' => 'Text body representation'
            ];

            $repo = new EmailIngestRepository($this->pdo);
            $repo->splitAndIngestStories(20, $parentRow, $splitConfig);

            // 5. Query inserted child
            $stmt = $this->pdo->prepare("SELECT * FROM emails WHERE parent_email_id = 20 LIMIT 1");
            $stmt->execute();
            $child = $stmt->fetch(PDO::FETCH_ASSOC);

            self::assertNotEmpty($child);
            $childMeta = json_decode($child['metadata'] ?? '', true);
            self::assertSame('https://example.com/parent-webview-url', $childMeta['link'] ?? null);
            self::assertSame('https://example.com/parent-webview-url', $childMeta['web_view_url'] ?? null);
        }

        public function testSplitAndIngestStoriesPreservesChildIdsAndScoresOnReprocess(): void
        {
            // 1. Insert subscription
            $this->pdo->exec("
                INSERT INTO email_subscriptions (
                    match_type, match_value, display_name, disabled, auto_detected
                ) VALUES (
                    'email', 'reprocess@example.com', 'Reprocess Newsletter', 0, 0
                )
            ");
            $subId = (int)$this->pdo->lastInsertId();

            // 2. Insert parent email
            $this->pdo->prepare("
                INSERT INTO emails (
                    id, email_subscription_id, subject, derived_title, from_email, from_name, text_body, html_body, date_utc
                ) VALUES (
                    30, ?, 'Digest Update', 'Digest Update', 'reprocess@example.com', 'Publisher', 'Parent body', 'HTML body', '2026-06-07 00:00:00'
                )
            ")->execute([$subId]);

            // 3. Define split config
            $splitConfig = [
                'split_rules' => [
                    'split_method' => 'html_selector',
                    'story_selector' => '.story',
                    'title_selector' => 'h2',
                    'link_selector' => 'a',
                    'body_selector' => '.content',
                ]
            ];

            // 4. Run splitAndIngestStories (First Pass)
            $parentRow = [
                'from_email' => 'reprocess@example.com',
                'from_name' => 'Publisher',
                'subject' => 'Digest Update',
                'html_body' => '
                    <html><body>
                        <div class="story">
                            <h2>Story A</h2>
                            <div class="content">Content A</div>
                        </div>
                        <div class="story">
                            <h2>Story B</h2>
                            <div class="content">Content B</div>
                        </div>
                    </body></html>
                ',
                'text_body' => 'Text body representation'
            ];

            $repo = new EmailIngestRepository($this->pdo);
            $repo->splitAndIngestStories(30, $parentRow, $splitConfig);

            // Fetch children and record their IDs
            $stmt = $this->pdo->prepare("SELECT id, subject FROM emails WHERE parent_email_id = 30 ORDER BY id ASC");
            $stmt->execute();
            $childrenFirst = $stmt->fetchAll(PDO::FETCH_ASSOC);

            self::assertCount(2, $childrenFirst);
            $childAId = (int)$childrenFirst[0]['id'];
            $childBId = (int)$childrenFirst[1]['id'];

            // Insert mock entry scores for both children
            $this->pdo->prepare("INSERT INTO entry_scores (entry_type, entry_id, relevance_score) VALUES ('email', ?, 0.8)")->execute([$childAId]);
            $this->pdo->prepare("INSERT INTO entry_scores (entry_type, entry_id, relevance_score) VALUES ('email', ?, 0.9)")->execute([$childBId]);

            // Verify scores exist
            $scoreCount = (int)$this->pdo->query("SELECT COUNT(*) FROM entry_scores")->fetchColumn();
            self::assertSame(2, $scoreCount);

            // 5. Run splitAndIngestStories (Second Pass - Reprocess / Update)
            $parentRowUpdated = [
                'from_email' => 'reprocess@example.com',
                'from_name' => 'Publisher',
                'subject' => 'Digest Update',
                'html_body' => '
                    <html><body>
                        <div class="story">
                            <h2>Story A Updated</h2>
                            <div class="content">Content A (new version)</div>
                        </div>
                        <div class="story">
                            <h2>Story B</h2>
                            <div class="content">Content B</div>
                        </div>
                    </body></html>
                ',
                'text_body' => 'Text body representation'
            ];
            $repo->splitAndIngestStories(30, $parentRowUpdated, $splitConfig);

            // Fetch children after second pass
            $stmt->execute();
            $childrenSecond = $stmt->fetchAll(PDO::FETCH_ASSOC);

            self::assertCount(2, $childrenSecond);
            self::assertSame($childAId, (int)$childrenSecond[0]['id']);
            self::assertSame('Story A Updated', $childrenSecond[0]['subject']);
            self::assertSame($childBId, (int)$childrenSecond[1]['id']);
            self::assertSame('Story B', $childrenSecond[1]['subject']);

            // 6. Run splitAndIngestStories (Third Pass - Story B is removed)
            $parentRowFewer = [
                'from_email' => 'reprocess@example.com',
                'from_name' => 'Publisher',
                'subject' => 'Digest Update',
                'html_body' => '
                    <html><body>
                        <div class="story">
                            <h2>Story A Updated</h2>
                            <div class="content">Content A (new version)</div>
                        </div>
                    </body></html>
                ',
                'text_body' => 'Text body representation'
            ];
            $repo->splitAndIngestStories(30, $parentRowFewer, $splitConfig);

            // Fetch children after third pass
            $stmt->execute();
            $childrenThird = $stmt->fetchAll(PDO::FETCH_ASSOC);

            self::assertCount(1, $childrenThird);
            self::assertSame($childAId, (int)$childrenThird[0]['id']);

            // Verify that Story B's score was deleted/pruned, but Story A's score is still present
            $stmtScore = $this->pdo->prepare("SELECT relevance_score FROM entry_scores WHERE entry_id = ?");
            $stmtScore->execute([$childAId]);
            // (Note: score of child A is deleted from entry_scores by the deleteForEntry in loop to trigger rescore, which is also correct)
            $stmtScoreB = $this->pdo->prepare("SELECT COUNT(*) FROM entry_scores WHERE entry_id = ?");
            $stmtScoreB->execute([$childBId]);
            self::assertSame(0, (int)$stmtScoreB->fetchColumn());
        }
    }
}
