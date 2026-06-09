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
    public const LATEST_VERSION = Migration031EmailTemplateRules::VERSION;

    private SystemConfigRepository $systemConfig;

    public function __construct(
        private PDO $pdo,
        private MigrationTarget $target = MigrationTarget::Mothership,
        ?SystemConfigRepository $systemConfig = null,
    ) {
        $this->systemConfig = $systemConfig ?? new SystemConfigRepository($pdo);
    }

    public function getTarget(): MigrationTarget
    {
        return $this->target;
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
        $current = $this->getCurrentVersion();

        /** @var array<int, MigrationContract> $migrations */
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
            Migration014EmailBodyLongtext::VERSION => new Migration014EmailBodyLongtext(),
            Migration015ParlPressSdaFeed::VERSION => new Migration015ParlPressSdaFeed(),
            Migration016ParlPressSdaNewsListUrl::VERSION => new Migration016ParlPressSdaNewsListUrl(),
            Migration017EmailBodyProcessor::VERSION => new Migration017EmailBodyProcessor(),
            Migration018InterpolNewsScraper::VERSION => new Migration018InterpolNewsScraper(),
            Migration019AsfinagPressScraper::VERSION => new Migration019AsfinagPressScraper(),
            Migration020EmailsHidden::VERSION => new Migration020EmailsHidden(),
            Migration021ScraperListingUrlCanonical::VERSION => new Migration021ScraperListingUrlCanonical(),
            Migration022ReenableScraperFeeds::VERSION => new Migration022ReenableScraperFeeds(),
            Migration023LexContentLongtext::VERSION => new Migration023LexContentLongtext(),
            Migration024FeedsExtractFullText::VERSION => new Migration024FeedsExtractFullText(),
            Migration025FeedItemsLinkNormalized::VERSION => new Migration025FeedItemsLinkNormalized(),
            Migration026EmailSubscriptionRegexConfig::VERSION => new Migration026EmailSubscriptionRegexConfig(),
            Migration027EmailSubscriptionModuleScope::VERSION => new Migration027EmailSubscriptionModuleScope(),
            Migration028DigestSplitting::VERSION => new Migration028DigestSplitting(),
            Migration029EmailSubscriptionSubjectRouting::VERSION => new Migration029EmailSubscriptionSubjectRouting(),
            Migration030SplitDrift::VERSION => new Migration030SplitDrift(),
            Migration031EmailTemplateRules::VERSION => new Migration031EmailTemplateRules(),
        ];

        ksort($migrations, SORT_NUMERIC);

        foreach ($migrations as $targetVersion => $migration) {
            if ($current >= $targetVersion) {
                continue;
            }
            if (!$this->target->accepts($migration::migrationScope())) {
                $this->persistSchemaVersion($targetVersion);
                $current = $targetVersion;
                $log("Skipped migration {$targetVersion} for target {$this->target->value} (not applicable).\n");
                continue;
            }
            $log("Applying migration to version {$targetVersion} ({$this->target->value}) …\n");
            $migration->apply($this->pdo, $this->target);
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
