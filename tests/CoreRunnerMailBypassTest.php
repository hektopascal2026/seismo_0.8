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
    use Seismo\Service\CoreRunner;
    use Seismo\Service\PluginRunResult;
    use Seismo\Repository\FeedItemRepository;
    use Seismo\Repository\PluginRunLogRepository;
    use Seismo\Repository\SystemConfigRepository;
    use Seismo\Repository\EmailIngestRepository;
    use DateTimeImmutable;
    use DateTimeZone;

    final class CoreRunnerMailBypassTest extends TestCase
    {
        private PDO $pdo;
        private FeedItemRepository $feedsRepo;
        private PluginRunLogRepository $runLogRepo;
        private SystemConfigRepository $configRepo;
        private EmailIngestRepository $emailIngestRepo;

        protected function setUp(): void
        {
            $this->pdo = new PDO('sqlite::memory:');
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $this->pdo->sqliteCreateFunction('UTC_TIMESTAMP', function() {
                return date('Y-m-d H:i:s');
            });

            // Create system_config table
            $this->pdo->exec("
                CREATE TABLE system_config (
                    config_key VARCHAR(255) PRIMARY KEY,
                    config_value TEXT
                )
            ");

            // Create plugin_run_log table
            $this->pdo->exec("
                CREATE TABLE plugin_run_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    plugin_id VARCHAR(100),
                    run_at DATETIME,
                    status VARCHAR(20),
                    item_count INTEGER,
                    error_message TEXT,
                    duration_ms INTEGER
                )
            ");

            // Create minimal emails table
            $this->pdo->exec("
                CREATE TABLE emails (
                    id INTEGER PRIMARY KEY AUTOINCREMENT
                )
            ");

            $this->feedsRepo = new FeedItemRepository($this->pdo);
            $this->runLogRepo = new PluginRunLogRepository($this->pdo);
            $this->configRepo = new SystemConfigRepository($this->pdo);
            $this->emailIngestRepo = new EmailIngestRepository($this->pdo);
        }

        public function testMailBypassThrottleRespectsDirectFlag(): void
        {
            // 1. Setup last successful mail run 5 minutes ago (300 seconds ago)
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $fiveMinsAgo = $now->modify('-300 seconds');

            $r = PluginRunResult::ok(0);
            $this->runLogRepo->record(CoreRunner::ID_MAIL, $r, 100);

            // Update the run_at of that log row to be exactly 5 minutes ago in the SQLite DB
            $this->pdo->exec("UPDATE plugin_run_log SET run_at = '" . $fiveMinsAgo->format('Y-m-d H:i:s') . "' WHERE plugin_id = '" . CoreRunner::ID_MAIL . "'");

            // Confirm DB reflects the correct last run time
            $lastOk = $this->runLogRepo->lastSuccessfulRunAt(CoreRunner::ID_MAIL);
            self::assertNotNull($lastOk);
            self::assertSame($fiveMinsAgo->format('Y-m-d H:i:s'), $lastOk->format('Y-m-d H:i:s'));

            // 2. Instantiate CoreRunner
            $runner = new CoreRunner(
                $this->feedsRepo,
                $this->runLogRepo,
                $this->configRepo,
                $this->emailIngestRepo
            );

            // 3. Run via runAll(force=true) or runMail(force=true) with no bypass.
            // We can test this by using reflection to call the private runMail method,
            // or by executing runOne vs runAll.
            // Let's call runOne which we updated to bypass mail throttle.
            // First, check that calling runAll(true) still respects the throttle (returns throttleSkipped)
            $resultsAll = $runner->runAll(true);
            $mailResultAll = $resultsAll[CoreRunner::ID_MAIL];
            self::assertSame('skipped', $mailResultAll->status);
            self::assertTrue($mailResultAll->isThrottleSkipped());

            // Now, check that calling runOne(CoreRunner::ID_MAIL, true) bypasses the throttle.
            // Since mail is not configured in this test environment, it should bypass the throttle and return
            // a skipped message indicating "Mail not configured" rather than "Throttled".
            $resultOne = $runner->runOne(CoreRunner::ID_MAIL, true);
            self::assertSame('skipped', $resultOne->status);
            self::assertFalse($resultOne->isThrottleSkipped());
            self::assertStringContainsString('Mail not configured', $resultOne->message);
        }
    }
}
