<?php
/**
 * SQL-only repository for the local `system_config` key/value table.
 *
 * This is the successor to `MagnituConfigRepository` from Slices 0–5.
 * The table was renamed from `magnitu_config` to `system_config` in
 * Slice 5a's Migration 005 (schema 21) to match reality: it has always
 * been a generic instance-level key/value store used for
 *
 *   - schema_version
 *   - Magnitu Bearer key (`api_key`)
 *   - Read-only export Bearer key (`export:api_key`)
 *   - Recipe JSON + version + last sync timestamp
 *   - Alert threshold + sort-by-relevance preference
 *   - Plugin configuration blocks (`plugin:<identifier>`, folded in from
 *     `lex_config.json` and `calendar_config.json` by Migration 005)
 *   - Retention policy rows (`retention:<family>` — see RetentionService)
 *
 * IMPORTANT: `system_config` is a **local** instance table, not an entry
 * source. It is never wrapped in entryTable() and never cross-DB read
 * from a mothership. Each satellite keeps its own keys — including its
 * own Bearer token, its own recipe, its own retention policy.
 *
 * Request-local cache. `get()` memoises per (PDO, key) so a single
 * request rendering many plugin blocks (e.g. the Settings page) doesn't
 * hit MariaDB once per block. The cache is invalidated on `set()` for
 * the key that was written; no cross-process coherence is needed because
 * every HTTP request and every cron tick starts with a clean PHP
 * interpreter.
 */

declare(strict_types=1);

namespace Seismo\Repository;

use PDO;
use PDOException;

final class SystemConfigRepository
{
    /**
     * Prefix for plugin configuration rows, e.g. `plugin:parliament_ch`.
     * Each row stores a JSON blob matching the shape the plugin's
     * `fetch()` method expects.
     */
    public const PLUGIN_PREFIX = 'plugin:';

    /**
     * Prefix for per-family retention settings, e.g. `retention:feed_items`.
     * Values are JSON like `{"days": 180}` or `{"days": null}` for unlimited.
     */
    public const RETENTION_PREFIX = 'retention:';

    /** @var array<string, string|null> */
    private array $cache = [];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Schema version the migrator wrote the last time it ran.
     *
     * Returns null when the table doesn't exist yet (brand-new database,
     * migrations have never been executed).
     */
    public function getSchemaVersion(): ?int
    {
        $raw = $this->get('schema_version');
        return $raw === null ? null : (int)$raw;
    }

    /**
     * Raw string fetch for any `system_config` key. Returns null when
     * the table is absent (first install) or the key isn't present.
     *
     * Memoised per-request. `set()` is the only invalidation path.
     */
    public function get(string $key): ?string
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $value = $this->selectOne('system_config', $key);

        return $this->cache[$key] = $value;
    }

    /**
     * Upsert a key. Used by migrations for `schema_version` and by
     * settings / plugin code. Throws PDOException if `system_config`
     * is missing — run base migrations first.
     */
    public function set(string $key, string $value): void
    {
        $this->upsertInto('system_config', $key, $value);

        $this->cache[$key] = $value;
    }

    /**
     * Remove a row when present. Used for clearing chunked-refresh cursors.
     */
    public function delete(string $key): void
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM system_config WHERE config_key = ?');
            $stmt->execute([$key]);
        } catch (PDOException $e) {
            if (!self::isMissingTable($e)) {
                throw $e;
            }
        }
        unset($this->cache[$key]);
    }

    private function selectOne(string $table, string $key): ?string
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT config_value FROM ' . $table . ' WHERE config_key = ?'
            );
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
        } catch (PDOException $e) {
            if (!self::isMissingTable($e)) {
                throw $e;
            }
            return null;
        }

        if ($value === false || $value === null) {
            return null;
        }
        return (string)$value;
    }

    private function upsertInto(string $table, string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . $table . ' (config_key, config_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
        );
        $stmt->execute([$key, $value]);
    }

    private static function isMissingTable(PDOException $e): bool
    {
        $code = (string)($e->errorInfo[1] ?? $e->getCode());
        // MariaDB 1146 = table missing, 1051 = table unknown. Both safe to
        // treat as "fall back / give up" depending on call site.
        return $code === '1146' || $code === '1051';
    }

    /**
     * Decode a JSON-valued config key to an associative array. Returns
     * the provided `$default` when the row is missing or malformed
     * (logs the JSON error but does not throw — consumers treat empty
     * config as "use defaults", same contract as 0.4's JSON file stores).
     *
     * @param array<string, mixed> $default
     * @return array<string, mixed>
     */
    public function getJson(string $key, array $default = []): array
    {
        $raw = $this->get($key);
        if ($raw === null || $raw === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            error_log('SystemConfigRepository: malformed JSON for key ' . $key);
            return $default;
        }

        /** @var array<string, mixed> */
        return $decoded;
    }

    /**
     * Encode and persist a JSON-valued config key. Array-only by
     * contract; scalar / null values should use `set()` directly.
     *
     * @param array<string, mixed> $value
     */
    public function setJson(string $key, array $value): void
    {
        $this->set(
            $key,
            json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            ) . "\n"
        );
    }

    /**
     * All plugin configuration rows as a map of `<identifier> => <decoded block>`.
     * Used by Settings / Diagnostics when they need to render every plugin at
     * once. Individual plugin code should use `getJson('plugin:xyz', …)` instead
     * to keep the query count minimal.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllPluginBlocks(): array
    {
        try {
            $stmt = $this->pdo->query(
                "SELECT config_key, config_value FROM system_config
                  WHERE config_key LIKE 'plugin:%'"
            );
        } catch (PDOException $e) {
            return [];
        }
        if ($stmt === false) {
            return [];
        }

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string)($row['config_key'] ?? '');
            if (!str_starts_with($key, self::PLUGIN_PREFIX)) {
                continue;
            }
            $identifier = substr($key, strlen(self::PLUGIN_PREFIX));
            $raw = (string)($row['config_value'] ?? '');
            $decoded = $raw === '' ? [] : json_decode($raw, true);
            $out[$identifier] = is_array($decoded) ? $decoded : [];
        }

        return $out;
    }
}
