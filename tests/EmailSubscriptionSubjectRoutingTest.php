<?php

namespace {
    if (!function_exists('isSatellite')) {
        function isSatellite(): bool
        {
            return false;
        }
    }
    if (!function_exists('entryTable')) {
        function entryTable(string $t): string
        {
            return "`{$t}`";
        }
    }
    if (!function_exists('getEmailTableName')) {
        function getEmailTableName(): string
        {
            return 'emails';
        }
    }
    if (!function_exists('entryDbSchemaExpr')) {
        function entryDbSchemaExpr(): string
        {
            return "'main'";
        }
    }
    if (!function_exists('seismo_email_timeline_unix')) {
        function seismo_email_timeline_unix(array $row): int
        {
            return \Seismo\Util\TimelineEntryDatetime::emailUnix($row);
        }
    }
    if (!function_exists('seismo_format_wrapper_card_clock')) {
        function seismo_format_wrapper_card_clock(array $wrapper): string
        {
            return \Seismo\Util\TimelineEntryDatetime::formatWrapperCardClock($wrapper);
        }
    }
}

namespace Seismo\Tests {

    use PHPUnit\Framework\TestCase;
    use PDO;
    use Seismo\Repository\EmailIngestRepository;
    use Seismo\Repository\EmailSubscriptionRepository;
    use Seismo\Repository\EntryRepository;

    final class EmailSubscriptionSubjectRoutingTest extends TestCase
    {
        private PDO $pdo;

        protected function setUp(): void
        {
            $this->pdo = new PDO('sqlite::memory:');
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $this->pdo->sqliteCreateFunction('SUBSTRING_INDEX', function ($str, $delim, $count) {
                $parts = explode((string)$delim, (string)$str);

                return $count > 0
                    ? implode($delim, array_slice($parts, 0, $count))
                    : implode($delim, array_slice($parts, $count));
            }, 3);

            $this->pdo->exec("
                CREATE TABLE email_subscriptions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    match_type VARCHAR(20) NOT NULL,
                    match_value VARCHAR(255) NOT NULL,
                    display_name VARCHAR(255) NOT NULL,
                    subject_filter VARCHAR(255) NOT NULL DEFAULT '',
                    category VARCHAR(255) DEFAULT NULL,
                    module_scope VARCHAR(20) NOT NULL DEFAULT 'newsletter',
                    disabled INTEGER NOT NULL DEFAULT 0,
                    show_in_magnitu INTEGER NOT NULL DEFAULT 1,
                    strip_listing_boilerplate INTEGER NOT NULL DEFAULT 0,
                    body_processor VARCHAR(64) DEFAULT NULL,
                    cleanup_config TEXT DEFAULT NULL,
                    digest_split_config TEXT DEFAULT NULL,
                    auto_detected INTEGER NOT NULL DEFAULT 0,
                    unsubscribe_url TEXT DEFAULT NULL,
                    unsubscribe_mailto TEXT DEFAULT NULL,
                    unsubscribe_one_click INTEGER NOT NULL DEFAULT 0,
                    item_count INTEGER NOT NULL DEFAULT 0,
                    removed_at DATETIME DEFAULT NULL,
                    UNIQUE (match_type, match_value, subject_filter, module_scope)
                )
            ");

            $this->pdo->exec("
                CREATE TABLE emails (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    parent_email_id INTEGER DEFAULT NULL,
                    email_subscription_id INTEGER DEFAULT NULL,
                    message_id VARCHAR(512),
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
                CREATE TABLE sender_tags (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    from_email VARCHAR(255),
                    tag VARCHAR(100),
                    removed_at DATETIME DEFAULT NULL
                )
            ");

            $this->pdo->exec("
                CREATE TABLE entry_scores (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    entry_type VARCHAR(50),
                    entry_id INTEGER,
                    relevance_score FLOAT
                )
            ");

            $this->pdo->exec("
                CREATE TABLE feed_items (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    hidden INTEGER DEFAULT 0
                )
            ");
        }

        public function testAllowsTwoSubscriptionsForSameSenderWithDifferentSubjectFilters(): void
        {
            $repo = new EmailSubscriptionRepository($this->pdo);

            $politpulsId = $repo->insert([
                'match_type'     => 'email',
                'match_value'    => 'news@zhdk.ch',
                'display_name'   => 'Politpuls',
                'subject_filter' => 'Politpuls',
                'module_scope'   => 'newsletter',
                'auto_detected'  => 0,
            ]);
            $stimmeId = $repo->insert([
                'match_type'     => 'email',
                'match_value'    => 'news@zhdk.ch',
                'display_name'   => 'Stimme der Wirtschaft',
                'subject_filter' => 'Stimme der Wirtschaft',
                'module_scope'   => 'newsletter',
                'auto_detected'  => 0,
            ]);

            self::assertGreaterThan(0, $politpulsId);
            self::assertGreaterThan(0, $stimmeId);
            self::assertNotSame($politpulsId, $stimmeId);
        }

        public function testFetchRowsForSubscriptionRespectsSubjectFilter(): void
        {
            $subRepo = new EmailSubscriptionRepository($this->pdo);
            $politpulsId = $subRepo->insert([
                'match_type'     => 'email',
                'match_value'    => 'news@zhdk.ch',
                'display_name'   => 'Politpuls',
                'subject_filter' => 'Politpuls',
                'module_scope'   => 'newsletter',
                'auto_detected'  => 0,
            ]);
            $stimmeId = $subRepo->insert([
                'match_type'     => 'email',
                'match_value'    => 'news@zhdk.ch',
                'display_name'   => 'Stimme der Wirtschaft',
                'subject_filter' => 'Stimme der Wirtschaft',
                'module_scope'   => 'newsletter',
                'auto_detected'  => 0,
            ]);

            $this->pdo->exec("
                INSERT INTO emails (id, subject, from_email, email_subscription_id, hidden, date_utc)
                VALUES
                    (1, 'Politpuls: Woche 12', 'news@zhdk.ch', {$politpulsId}, 0, '2026-06-01 10:00:00'),
                    (2, 'Stimme der Wirtschaft — Juni', 'news@zhdk.ch', {$stimmeId}, 0, '2026-06-02 10:00:00')
            ");

            $ingestRepo = new EmailIngestRepository($this->pdo);
            $politpulsRows = $ingestRepo->fetchRowsForSubscription($subRepo->findById($politpulsId), 10);
            $stimmeRows    = $ingestRepo->fetchRowsForSubscription($subRepo->findById($stimmeId), 10);

            self::assertCount(1, $politpulsRows);
            self::assertSame(1, (int)$politpulsRows[0]['id']);
            self::assertCount(1, $stimmeRows);
            self::assertSame(2, (int)$stimmeRows[0]['id']);
        }

        public function testBackfillAssignsSubscriptionIdsFromSubjectRouting(): void
        {
            $subRepo = new EmailSubscriptionRepository($this->pdo);
            $politpulsId = $subRepo->insert([
                'match_type'     => 'email',
                'match_value'    => 'news@zhdk.ch',
                'display_name'   => 'Politpuls',
                'subject_filter' => 'Politpuls',
                'module_scope'   => 'newsletter',
                'auto_detected'  => 0,
            ]);
            $stimmeId = $subRepo->insert([
                'match_type'     => 'email',
                'match_value'    => 'news@zhdk.ch',
                'display_name'   => 'Stimme der Wirtschaft',
                'subject_filter' => 'Stimme der Wirtschaft',
                'module_scope'   => 'newsletter',
                'auto_detected'  => 0,
            ]);

            $this->pdo->exec("
                INSERT INTO emails (id, subject, from_email, hidden, date_utc)
                VALUES
                    (10, 'Politpuls: update', 'news@zhdk.ch', 0, '2026-06-01 10:00:00'),
                    (11, 'Stimme der Wirtschaft — Mai', 'news@zhdk.ch', 0, '2026-06-02 10:00:00')
            ");

            self::assertSame(2, $subRepo->backfillEmailSubscriptionIds());

            $stmt = $this->pdo->query('SELECT id, email_subscription_id FROM emails ORDER BY id');
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            self::assertSame((string)$politpulsId, (string)$rows[0]['email_subscription_id']);
            self::assertSame((string)$stimmeId, (string)$rows[1]['email_subscription_id']);
        }

        public function testEnsurePendingNewsletterTypeWhenSubjectDoesNotMatchExisting(): void
        {
            $subRepo = new EmailSubscriptionRepository($this->pdo);
            $stimmeId = $subRepo->insert([
                'match_type'     => 'email',
                'match_value'    => 'news@zhdk.ch',
                'display_name'   => 'Stimme der Wirtschaft',
                'subject_filter' => 'Stimme der Wirtschaft',
                'module_scope'   => 'newsletter',
                'auto_detected'  => 0,
            ]);
            self::assertGreaterThan(0, $stimmeId);

            $created = $subRepo->ensurePendingNewsletterTypesFromIngest([
                [
                    'from_email' => 'news@zhdk.ch',
                    'subject'    => 'Politpuls: Woche 12',
                ],
            ]);
            self::assertSame(1, $created);

            $pending = $subRepo->listPendingForModule('newsletter', 10, 0);
            self::assertCount(1, $pending);
            self::assertSame('Politpuls', $pending[0]['display_name']);
            self::assertSame('Politpuls', $pending[0]['subject_filter']);
            self::assertSame('newsletter', $pending[0]['module_scope']);
        }

        public function testEnsurePendingNewsletterTypeSkippedWhenSubjectAlreadyMatches(): void
        {
            $subRepo = new EmailSubscriptionRepository($this->pdo);
            $subRepo->insert([
                'match_type'     => 'email',
                'match_value'    => 'news@zhdk.ch',
                'display_name'   => 'Stimme der Wirtschaft',
                'subject_filter' => 'Stimme der Wirtschaft',
                'module_scope'   => 'newsletter',
                'auto_detected'  => 0,
            ]);

            $created = $subRepo->ensurePendingNewsletterTypesFromIngest([
                [
                    'from_email' => 'news@zhdk.ch',
                    'subject'    => 'Stimme der Wirtschaft — Juni',
                ],
            ]);
            self::assertSame(0, $created);
            self::assertSame([], $subRepo->listPendingForModule('newsletter', 10, 0));
        }

        public function testEnsurePendingNewsletterTypeSkippedWithoutConfirmedNewsletterSender(): void
        {
            $subRepo = new EmailSubscriptionRepository($this->pdo);
            $subRepo->insert([
                'match_type'     => 'email',
                'match_value'    => 'news@zhdk.ch',
                'display_name'   => 'ZHDK Mail',
                'subject_filter' => '',
                'module_scope'   => 'mail',
                'auto_detected'  => 0,
            ]);

            $created = $subRepo->ensurePendingNewsletterTypesFromIngest([
                [
                    'from_email' => 'news@zhdk.ch',
                    'subject'    => 'Politpuls: Woche 12',
                ],
            ]);
            self::assertSame(0, $created);
        }

        public function testListPendingForModulePartitionsMailAndNewsletter(): void
        {
            $subRepo = new EmailSubscriptionRepository($this->pdo);
            $subRepo->insert([
                'match_type'    => 'domain',
                'match_value'   => 'example.com',
                'display_name'  => 'Example',
                'module_scope'  => 'mail',
                'auto_detected' => 1,
            ]);
            $subRepo->insert([
                'match_type'     => 'email',
                'match_value'    => 'news@zhdk.ch',
                'display_name'   => 'Politpuls',
                'subject_filter' => 'Politpuls',
                'module_scope'   => 'newsletter',
                'auto_detected'  => 1,
            ]);

            self::assertCount(1, $subRepo->listPendingForModule('mail', 10, 0));
            self::assertCount(1, $subRepo->listPendingForModule('newsletter', 10, 0));
        }

        public function testPeekLatestEmailForPendingSenderUsesStoredSample(): void
        {
            $subRepo = new EmailSubscriptionRepository($this->pdo);
            $pendingId = $subRepo->insert([
                'match_type'    => 'domain',
                'match_value'   => 'republik.ch',
                'display_name'  => 'Republik',
                'module_scope'  => 'mail',
                'auto_detected' => 1,
            ]);

            $this->pdo->exec("
                INSERT INTO emails (id, subject, from_email, hidden, date_utc)
                VALUES (50, 'Republik Daily: Example', 'news@republik.ch', 0, '2026-06-05 12:00:00')
            ");

            $entryRepo = new EntryRepository($this->pdo);
            $peek = $entryRepo->peekLatestEmailForSubscription($pendingId, 'mail');

            self::assertNotNull($peek);
            self::assertSame(50, $peek['email_id']);
            self::assertSame('Republik Daily: Example', $peek['subject']);
        }

        public function testModuleTimelineForSubscriptionFiltersBySubscriptionId(): void
        {
            $subRepo = new EmailSubscriptionRepository($this->pdo);
            $politpulsId = $subRepo->insert([
                'match_type'     => 'email',
                'match_value'    => 'news@zhdk.ch',
                'display_name'   => 'Politpuls',
                'subject_filter' => 'Politpuls',
                'module_scope'   => 'newsletter',
                'auto_detected'  => 0,
            ]);
            $stimmeId = $subRepo->insert([
                'match_type'     => 'email',
                'match_value'    => 'news@zhdk.ch',
                'display_name'   => 'Stimme der Wirtschaft',
                'subject_filter' => 'Stimme der Wirtschaft',
                'module_scope'   => 'newsletter',
                'auto_detected'  => 0,
            ]);

            $this->pdo->exec("
                INSERT INTO emails (id, parent_email_id, subject, derived_title, from_email, text_body, html_body, email_subscription_id, hidden, date_utc)
                VALUES
                    (20, NULL, 'Politpuls digest', 'Politpuls digest', 'news@zhdk.ch', 'body', '<p>b</p>', {$politpulsId}, 0, '2026-06-01 10:00:00'),
                    (21, NULL, 'Stimme digest', 'Stimme digest', 'news@zhdk.ch', 'body', '<p>b</p>', {$stimmeId}, 0, '2026-06-02 10:00:00')
            ");

            $entryRepo = new EntryRepository($this->pdo);
            $politpulsTimeline = $entryRepo->getEmailModuleTimelineForSubscription($politpulsId, 10, 0, 'newsletter');
            $stimmeTimeline    = $entryRepo->getEmailModuleTimelineForSubscription($stimmeId, 10, 0, 'newsletter');

            self::assertCount(1, $politpulsTimeline);
            self::assertSame(20, (int)$politpulsTimeline[0]['entry_id']);
            self::assertCount(1, $stimmeTimeline);
            self::assertSame(21, (int)$stimmeTimeline[0]['entry_id']);
        }
    }
}
