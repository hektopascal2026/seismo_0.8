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
     * When `system_config` is missing, we read `schema_version` from
     * `magnitu_config` (created by Migration 001 / db-schema.sql) until
     * Migration 005 renames that table. We only throw when a pre-v21
     * production DB is stuck on `magnitu_config` at v21+ without
     * `system_config` — i.e. Slice 5a rename never ran.
     */
    public function getCurrentVersion(): int
    {
        $v = $this->systemConfig->getSchemaVersion();
        if ($v !== null) {
            return $v;
        }

        $legacyVersion = $this->readLegacySchemaVersion();
        if ($legacyVersion === null) {
            return 0;
        }

        if (!$this->tableExists('system_config')) {
            if ($legacyVersion < Migration005SystemConfig::VERSION) {
                return $legacyVersion;
            }
            throw new RuntimeException(
                'Legacy `magnitu_config` at schema v' . $legacyVersion . ' but `system_config` is missing. '
                . 'Run migrations through v' . Migration005SystemConfig::VERSION . ' (table rename) before continuing.'
            );
        }

        throw new RuntimeException(
            'Legacy `magnitu_config` still present at schema v' . $legacyVersion . ' while `system_config` exists. '
            . 'Remove the duplicate `magnitu_config` table after confirming Migration 005 completed, or restore from backup.'
        );
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
                'Migrations only run on the mothership. This instance has SEISMO_SATELLITE_MODE enabled; do not apply DDL on a satellite.'
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
            $this->persistSchemaVersion($targetVersion);
            $current = $targetVersion;
            $log("OK — schema version is now {$targetVersion}.\n");
        }

        if ($current >= self::LATEST_VERSION) {
            $log('Schema is up to date (' . self::LATEST_VERSION . ").\n");
        }
    }

    /**
     * Migration 001 creates `magnitu_config`; Migration 005 renames it to
     * `system_config`. Until v21, store schema_version in the legacy table.
     */
    private function persistSchemaVersion(int $targetVersion): void
    {
        if ($targetVersion >= Migration005SystemConfig::VERSION && $this->tableExists('system_config')) {
            $this->systemConfig->set('schema_version', (string)$targetVersion);
            return;
        }

        if ($targetVersion >= Migration005SystemConfig::VERSION) {
            throw new RuntimeException(
                'Migration ' . Migration005SystemConfig::VERSION . ' should have created `system_config` before recording schema v'
                . $targetVersion . '.'
            );
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO magnitu_config (config_key, config_value)
             VALUES ('schema_version', ?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)"
        );
        $stmt->execute([(string)$targetVersion]);
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
        );
        $stmt->execute([$table]);

        return (bool)$stmt->fetchColumn();
    }
}
