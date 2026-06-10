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
    use ReflectionMethod;

    class StuckQueueTestPDO extends PDO
    {
        public function prepare(string $query, array $options = []): \PDOStatement|false
        {
            $query = str_replace(
                'ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)',
                'ON CONFLICT(config_key) DO UPDATE SET config_value = excluded.config_value',
                $query
            );
            return parent::prepare($query, $options);
        }

        public function exec(string $statement): int|false
        {
            $statement = str_replace(
                'ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)',
                'ON CONFLICT(config_key) DO UPDATE SET config_value = excluded.config_value',
                $statement
            );
            return parent::exec($statement);
        }
    }

    final class CoreRunnerStuckQueueTest extends TestCase
    {
        private StuckQueueTestPDO $pdo;
        private FeedItemRepository $feedsRepo;
        private PluginRunLogRepository $runLogRepo;
        private SystemConfigRepository $configRepo;
        private EmailIngestRepository $emailIngestRepo;

        protected function setUp(): void
        {
            $this->pdo = new StuckQueueTestPDO('sqlite::memory:');
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

            // Create feeds table
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

            // Create minimal emails table for EmailIngestRepository constructor dependency
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

        public function testConsecutivePausesIncrementOnBudgetExceeded(): void
        {
            $runner = new CoreRunner(
                $this->feedsRepo,
                $this->runLogRepo,
                $this->configRepo,
                $this->emailIngestRepo
            );

            $method = new ReflectionMethod(CoreRunner::class, 'runChunkedCoreWithBudget');
            
            $runOnce = function (bool $force): PluginRunResult {
                return PluginRunResult::ok(0);
            };

            // Initially consecutive_pauses should be null
            self::assertNull($this->configRepo->get('refresh_chunk:rss_consecutive_pauses'));

            // Run with negative cron budget to trigger budget timeout path
            $result = $method->invoke($runner, false, 120, -5, $runOnce, 'RSS');

            self::assertSame('ok', $result->status);
            self::assertStringContainsString('paused (time budget)', $result->message);

            // Consecutive pauses should be incremented to 1
            self::assertSame('1', $this->configRepo->get('refresh_chunk:rss_consecutive_pauses'));
        }

        public function testBudgetPauseWithChunkProgressResetsConsecutiveCounter(): void
        {
            $this->configRepo->set('refresh_chunk:rss_consecutive_pauses', '2');

            $runner = new CoreRunner(
                $this->feedsRepo,
                $this->runLogRepo,
                $this->configRepo,
                $this->emailIngestRepo
            );

            $method = new ReflectionMethod(CoreRunner::class, 'runChunkedCoreWithBudget');

            $runOnce = static function (bool $force): PluginRunResult {
                return PluginRunResult::batchFeeds(1, 1, 0)->withPersist(false);
            };

            $result = $method->invoke($runner, false, 120, 1, $runOnce, 'RSS');

            self::assertSame('ok', $result->status);
            self::assertStringContainsString('paused (time budget)', $result->message);
            self::assertSame('0', $this->configRepo->get('refresh_chunk:rss_consecutive_pauses'));
        }

        public function testStuckQueueSelfHealingTriggeredAtThreePauses(): void
        {
            // Seed 2 consecutive pauses and cursor position
            $this->configRepo->set('refresh_chunk:rss_consecutive_pauses', '2');
            $this->configRepo->set('refresh_chunk:rss_after_id', '10');

            // Insert feeds that will be reported as stuck (IDs > 10)
            $this->pdo->exec("
                INSERT INTO feeds (id, url, source_type, title, disabled)
                VALUES (12, 'https://stuck-a.org', 'rss', 'Stuck Feed A', 0)
            ");
            $this->pdo->exec("
                INSERT INTO feeds (id, url, source_type, title, disabled)
                VALUES (15, 'https://stuck-b.org', 'rss', 'Stuck Feed B', 0)
            ");

            $runner = new CoreRunner(
                $this->feedsRepo,
                $this->runLogRepo,
                $this->configRepo,
                $this->emailIngestRepo
            );

            $method = new ReflectionMethod(CoreRunner::class, 'runChunkedCoreWithBudget');
            
            $runOnce = function (bool $force): PluginRunResult {
                return PluginRunResult::ok(0);
            };

            // Run with negative budget to trigger the timeout path (reaches 3rd consecutive pause)
            $result = $method->invoke($runner, false, 120, -5, $runOnce, 'RSS');

            // Verification
            self::assertSame('warn', $result->status);
            self::assertStringContainsString('Stuck Queue Alert', $result->message);
            self::assertStringContainsString('Stuck Feed A', $result->message);
            self::assertStringContainsString('Stuck Feed B', $result->message);

            // The cursor must be advanced past the max ID of the batch (15)
            self::assertSame('15', $this->configRepo->get('refresh_chunk:rss_after_id'));

            // The consecutive pauses counter must be reset to 0
            self::assertSame('0', $this->configRepo->get('refresh_chunk:rss_consecutive_pauses'));

            // The warning must be recorded in plugin_run_log
            $stmt = $this->pdo->query("SELECT * FROM plugin_run_log WHERE plugin_id = 'core:rss'");
            $logs = $stmt->fetchAll();
            self::assertCount(1, $logs);
            self::assertSame('warn', $logs[0]['status']);
            self::assertStringContainsString('Stuck Queue Alert', $logs[0]['error_message']);
        }
    }
}
