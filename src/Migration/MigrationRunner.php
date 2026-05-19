<?php
/**
 * Ordered, versioned migrations. Each migration bumps the
 * `schema_version` row in `system_config` (renamed from `magnitu_config`
 * by Migration 005 / Slice 5a).
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;
use RuntimeException;
use Seismo\Repository\SystemConfigRepository;

final class MigrationRunner
{
    /** Highest schema version shipped by built-in migrations. */
    public const LATEST_VERSION = Migration013EmailGmail::VERSION;

    private SystemConfigRepository $systemConfig;

    public function __construct(
        private PDO $pdo,
        ?SystemConfigRepository $systemConfig = null,
    ) {
        $this->systemConfig = $systemConfig ?? new SystemConfigRepository($pdo);
    }

    /**
     * Returns current stored schema version, or 0 if unreadable / not set.
     *
     * When `system_config` is missing (fresh install OR a pre-v21 instance
     * that still has `magnitu_config`), we probe the legacy table explicitly
     * before returning 0. On a legacy hit we refuse to proceed rather than
     * silently re-running Migration 001..004 against a populated database —
     * the admin must deploy Slice 5a first, apply Migration 005, then
     * deploy Slice 6 code. This is the safety net that replaced
     * {@see SystemConfigRepository}'s transparent fallback (removed in
     * Slice 6 per the scope-fidelity notes for Slice 5a).
     */
    public function getCurrentVersion(): int
    {
        $v = $this->systemConfig->getSchemaVersion();
        if ($v !== null) {
            return $v;
        }

        $legacyVersion = $this->readLegacySchemaVersion();
        if ($legacyVersion !== null) {
            throw new RuntimeException(
                'Legacy `magnitu_config` table detected at schema v' . $legacyVersion . '. '
                . 'Slice 5a must be deployed and migrated before Slice 6. '
                . 'Restore Slice 5a code, run ?action=migrate (or `php migrate.php`) to reach v21, then deploy Slice 6 again.'
            );
        }

        return 0;
    }

    /**
     * Read `schema_version` from the legacy `magnitu_config` table, if it
     * still exists. Returns null on a truly fresh database (both tables
     * absent) or on an unreadable row.
     */
    private function readLegacySchemaVersion(): ?int
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT config_value FROM magnitu_config WHERE config_key = 'schema_version'"
            );
            $stmt->execute();
            $raw = $stmt->fetchColumn();
        } catch (PDOException $e) {
            return null;
        }
        if ($raw === false || $raw === null || $raw === '') {
            return null;
        }

        return (int)$raw;
    }

    /**
     * Apply all pending migrations in order. Idempotent on re-run when already up to date.
     *
     * @param callable(string): void $log Echo or fwrite for progress lines
     */
    public function run(callable $log): void
    {
        if (isSatellite()) {
            throw new RuntimeException(
                'Migrations only run on the mothership. This instance has SEISMO_MOTHERSHIP_DB set (satellite mode); do not apply DDL to the local database.'
            );
        }

        $current = $this->getCurrentVersion();

        $migrations = [
            Migration001BaseSchema::VERSION    => new Migration001BaseSchema(),
            Migration002PluginRunLog::VERSION  => new Migration002PluginRunLog(),
            Migration003EmailsUnified::VERSION => new Migration003EmailsUnified(),
            Migration004ExportKey::VERSION     => new Migration004ExportKey(),
            Migration005SystemConfig::VERSION  => new Migration005SystemConfig(),
            Migration006EmailSubscriptionsShowInMagnitu::VERSION => new Migration006EmailSubscriptionsShowInMagnitu(),
            Migration007ParlPressToFeedItems::VERSION => new Migration007ParlPressToFeedItems(),
            Migration008FeedsUrlNonUnique::VERSION => new Migration008FeedsUrlNonUnique(),
            Migration009ScraperExcludeSelectors::VERSION => new Migration009ScraperExcludeSelectors(),
            Migration010EmailSubscriptionStripListing::VERSION => new Migration010EmailSubscriptionStripListing(),
            Migration011SourceLog::VERSION => new Migration011SourceLog(),
            Migration012PluginRunLogWarn::VERSION => new Migration012PluginRunLogWarn(),
            Migration013EmailGmail::VERSION       => new Migration013EmailGmail(),
        ];

        ksort($migrations, SORT_NUMERIC);

        foreach ($migrations as $targetVersion => $migration) {
            if ($current >= $targetVersion) {
                continue;
            }
            $log("Applying migration to version {$targetVersion} …\n");
            $migration->apply($this->pdo);
            $this->systemConfig->set('schema_version', (string)$targetVersion);
            $current = $targetVersion;
            $log("OK — schema version is now {$targetVersion}.\n");
        }

        if ($current >= self::LATEST_VERSION) {
            $log('Schema is up to date (' . self::LATEST_VERSION . ").\n");
        }
    }
}
