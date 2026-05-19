<?php
/**
 * Migration 005 — Rename `magnitu_config` → `system_config` and fold
 * plugin config JSON files into it (Slice 5a, schema version 21).
 *
 * Three independently observable effects, applied in order. Each is
 * idempotent: re-running the migration after a partial success is safe.
 *
 * 1. Table rename.  `ALTER TABLE magnitu_config RENAME TO system_config`.
 *    MariaDB DDL — cannot live inside a transaction. The rename is
 *    skipped when `system_config` already exists (re-run safety).
 *
 * 2. Plugin config fold-in (mothership only).  Reads `lex_config.json`
 *    and `calendar_config.json` from the install root, decodes each
 *    top-level block, and upserts a row per block keyed
 *    `plugin:<block-name>` in `system_config`.  Lex sidecar `jus_banned_words`
 *    stores under `lex:jus_banned_words` instead (not a plugin).
 *    Missing files → skipped silently (the store falls back to its
 *    built-in defaults, same behaviour as before). Malformed JSON →
 *    migration aborts so the admin can fix the file before retrying.
 *
 * 3. JSON sidecar rename.  `lex_config.json` → `lex_config.json.migrated-v21`
 *    (same for `calendar_config.json`). Files are kept on disk as a
 *    manual rollback sample — the admin deletes them once v21 is
 *    confirmed on the live host. Never rewritten; the stores now read
 *    exclusively from `system_config`.
 *
 * Satellites run step 1 only (they have a local `magnitu_config`
 * that needs renaming for auth keys to keep working), skip steps 2–3
 * (they don't own plugin configs — those live on the mothership and
 * are not cross-DB read because plugins don't execute on satellites).
 *
 * Rollback (manual, documented here):
 *   -- 1. Rename back:
 *   ALTER TABLE system_config RENAME TO magnitu_config;
 *   -- 2. Drop folded-in rows:
 *   DELETE FROM magnitu_config WHERE config_key LIKE 'plugin:%';
 *   DELETE FROM magnitu_config WHERE config_key LIKE 'lex:%';
 *   -- 3. mv lex_config.json.migrated-v21 lex_config.json (and calendar)
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;
use RuntimeException;
use Seismo\Repository\SystemConfigRepository;

final class Migration005SystemConfig
{
    public const VERSION = 21;

    private const LEGACY_TABLE = 'magnitu_config';
    private const NEW_TABLE    = 'system_config';

    /**
     * Mapping of (JSON file basename) => (row-key prefix, special-case keys).
     * Every top-level key in the file becomes `<prefix>:<key>` in system_config,
     * except the explicit overrides in `specials` which route elsewhere
     * (e.g. `lex_config.jus_banned_words` → `lex:jus_banned_words`).
     *
     * @var array<string, array{prefix:string, specials:array<string, string>}>
     */
    private const JSON_SOURCES = [
        'lex_config.json' => [
            'prefix'   => 'plugin:',
            'specials' => ['jus_banned_words' => 'lex:jus_banned_words'],
        ],
        'calendar_config.json' => [
            'prefix'   => 'plugin:',
            'specials' => [],
        ],
    ];

    public function apply(PDO $pdo): void
    {
        $this->renameTable($pdo);

        if (isSatellite()) {
            return;
        }

        $config = new SystemConfigRepository($pdo);
        foreach (self::JSON_SOURCES as $basename => $rules) {
            $this->foldJsonFile($config, $basename, $rules['prefix'], $rules['specials']);
        }
    }

    private function renameTable(PDO $pdo): void
    {
        // Step 1: if the new table already exists (re-run / fresh install via
        // a future migration), do nothing.
        if ($this->tableExists($pdo, self::NEW_TABLE)) {
            return;
        }
        if (!$this->tableExists($pdo, self::LEGACY_TABLE)) {
            // Neither table present — this should be impossible because the
            // base migration creates `magnitu_config`. Fail loudly rather
            // than silently leave the instance without a config table.
            throw new RuntimeException(
                'Migration 005: neither ' . self::NEW_TABLE
                . ' nor ' . self::LEGACY_TABLE . ' exists; schema is corrupt.'
            );
        }

        try {
            $pdo->exec('ALTER TABLE ' . self::LEGACY_TABLE . ' RENAME TO ' . self::NEW_TABLE);
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Migration 005 rename failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * @param array<string, string> $specials
     */
    private function foldJsonFile(
        SystemConfigRepository $config,
        string $basename,
        string $prefix,
        array $specials,
    ): void {
        $path = SEISMO_ROOT . '/' . $basename;
        if (!is_file($path)) {
            return;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(
                'Migration 005: ' . $basename . ' is not valid JSON — fix the file and retry. '
                . 'json_decode: ' . json_last_error_msg()
            );
        }

        foreach ($decoded as $key => $block) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $destKey = $specials[$key] ?? ($prefix . $key);
            $encoded = json_encode(
                $block,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            );
            if ($encoded === false) {
                throw new RuntimeException(
                    'Migration 005: could not re-encode ' . $basename . ' block ' . $key
                );
            }
            // Only write if the key doesn't already exist. If the admin
            // re-runs Migration 005 after the JSON has diverged from the
            // DB, we keep whatever's in the DB (it's been edited in the
            // Settings UI since the first run).
            if ($config->get($destKey) === null) {
                $config->set($destKey, $encoded . "\n");
            }
        }

        // Rename the sidecar so a re-run doesn't re-import stale JSON, and
        // so the admin has a manual rollback sample on disk.
        $archive = $path . '.migrated-v' . self::VERSION;
        if (!is_file($archive)) {
            @rename($path, $archive);
        }
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?'
            );
            $stmt->execute([$table]);
            return (bool)$stmt->fetchColumn();
        } catch (PDOException $e) {
            return false;
        }
    }
}
