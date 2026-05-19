<?php
/**
 * Migration 004 — reserve `export:api_key` in `magnitu_config` (Slice 5, schema version 20).
 *
 * Slice 5 adds a second Bearer-auth key alongside the existing Magnitu `api_key`.
 * The read-only export endpoints (`?action=export_briefing`, `?action=export_entries`)
 * validate against this row exclusively, so a briefing / automation script can never
 * POST scores or labels — two-key model. The admin UI polish lives in Slice 6.
 *
 * This migration does NOT generate a secret: the row is seeded empty so validators
 * treat the instance as "no export key configured" until the admin explicitly
 * generates one (UI or CLI `bin2hex(random_bytes(24))`). Behaviour identical to how
 * `api_key` has been seeded since 0.4 — only created on demand.
 *
 * Idempotent via `ON DUPLICATE KEY UPDATE config_key = config_key` (a true no-op
 * that still fires any real errors — unlike `INSERT IGNORE`, which would mask
 * schema-level problems such as a missing `magnitu_config` table). The whole
 * migration is skipped when `schema_version >= 20`.
 *
 * Table rename (`magnitu_config` → `system_config`) is Slice 5a; the key survives
 * that migration unchanged.
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;
use RuntimeException;

final class Migration004ExportKey
{
    public const VERSION = 20;

    public function apply(PDO $pdo): void
    {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO magnitu_config (config_key, config_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE config_key = config_key'
            );
            $stmt->execute(['export:api_key', '']);
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Migration 004 failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
