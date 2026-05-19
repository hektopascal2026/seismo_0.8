<?php

declare(strict_types=1);

namespace Seismo\Repository;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Seismo\Service\PluginRunResult;

/**
 * Structured diagnostics table for plugin invocations (schema v18).
 *
 * Rows are written by RefreshAllService after a plugin runs (ok / error) or
 * after the runner explicitly decides to skip for a runtime reason (e.g.
 * satellite mode, plugin disabled in config). **Throttle-skipped runs are
 * deliberately NOT logged** — see docblock on RefreshAllService.
 */
final class PluginRunLogRepository
{
    /**
     * Hard cap on list methods. Same posture as other repositories
     * (`core-plugin-architecture.mdc`, "Bounded queries").
     */
    public const MAX_LIMIT = 200;

    public function __construct(private PDO $pdo)
    {
    }

    public function record(string $pluginId, PluginRunResult $result, int $durationMs): void
    {
        $sql = 'INSERT INTO plugin_run_log
            (plugin_id, run_at, status, item_count, error_message, duration_ms)
            VALUES (?, UTC_TIMESTAMP(), ?, ?, ?, ?)';

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $pluginId,
                $result->status,
                $result->count,
                ($result->status === 'ok') ? null : $result->message,
                $durationMs,
            ]);
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                // Migration 002 not applied yet — don't take down the refresh,
                // just emit to the PHP log. Admin will see the missing-table
                // error on Settings → Diagnostics if they open the page.
                error_log('Seismo plugin_run_log: ' . $e->getMessage());

                return;
            }
            throw $e;
        }
    }

    /**
     * Timestamp (UTC) of the most recent successful run (`ok` or `warn`) for
     * $pluginId, or null. Used by the throttle check. `error` / `skipped`
     * rows are deliberately ignored so a broken upstream gets retried on
     * every cron tick instead of being silenced for the throttle interval.
     */
    public function lastSuccessfulRunAt(string $pluginId): ?DateTimeImmutable
    {
        $sql = 'SELECT MAX(run_at) FROM plugin_run_log WHERE plugin_id = ? AND status IN (\'ok\', \'warn\')';

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$pluginId]);
            $raw = $stmt->fetchColumn();
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return null;
            }
            throw $e;
        }

        if ($raw === false || $raw === null || $raw === '') {
            return null;
        }

        return new DateTimeImmutable((string)$raw, new DateTimeZone('UTC'));
    }

    /**
     * Latest row per plugin id. Keys in the result mirror the input order.
     *
     * @param list<string> $pluginIds
     * @return array<string, array{status: string, run_at: DateTimeImmutable, item_count: int, error_message: ?string, duration_ms: int}>
     */
    public function latestPerPlugin(array $pluginIds): array
    {
        $out = [];
        if ($pluginIds === []) {
            return $out;
        }

        $placeholders = implode(',', array_fill(0, count($pluginIds), '?'));
        $sql = 'SELECT l.plugin_id, l.run_at, l.status, l.item_count, l.error_message, l.duration_ms
            FROM plugin_run_log l
            INNER JOIN (
                SELECT plugin_id, MAX(run_at) AS m
                FROM plugin_run_log
                WHERE plugin_id IN (' . $placeholders . ')
                GROUP BY plugin_id
            ) latest
            ON latest.plugin_id = l.plugin_id AND latest.m = l.run_at
            WHERE l.plugin_id IN (' . $placeholders . ')';

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_merge($pluginIds, $pluginIds));
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $out[(string)$row['plugin_id']] = [
                    'status'        => (string)$row['status'],
                    'run_at'        => new DateTimeImmutable((string)$row['run_at'], new DateTimeZone('UTC')),
                    'item_count'    => (int)$row['item_count'],
                    'error_message' => $row['error_message'] !== null ? (string)$row['error_message'] : null,
                    'duration_ms'   => (int)$row['duration_ms'],
                ];
            }
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return $out;
            }
            throw $e;
        }

        return $out;
    }

    /**
     * Recent runs for a single plugin, newest first. Bounded.
     *
     * @return list<array{run_at: DateTimeImmutable, status: string, item_count: int, error_message: ?string, duration_ms: int}>
     */
    public function recentForPlugin(string $pluginId, int $limit = 20): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $sql = 'SELECT run_at, status, item_count, error_message, duration_ms
            FROM plugin_run_log
            WHERE plugin_id = ?
            ORDER BY run_at DESC
            LIMIT ' . (int)$limit;

        $rows = [];
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$pluginId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rows[] = [
                    'run_at'        => new DateTimeImmutable((string)$row['run_at'], new DateTimeZone('UTC')),
                    'status'        => (string)$row['status'],
                    'item_count'    => (int)$row['item_count'],
                    'error_message' => $row['error_message'] !== null ? (string)$row['error_message'] : null,
                    'duration_ms'   => (int)$row['duration_ms'],
                ];
            }
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return [];
            }
            throw $e;
        }

        return $rows;
    }

    /**
     * Recent runs for each of the supplied plugin ids. Result shape mirrors
     * the input order, each inner list is newest-first and capped at $limit.
     * Single round-trip via per-id `UNION ALL` subqueries — each leg is
     * `WHERE plugin_id = ? ORDER BY run_at DESC LIMIT $limit`, so the
     * `(plugin_id, run_at)` index carries every leg and we avoid the N+1
     * pattern of calling {@see recentForPlugin} in a loop.
     *
     * Portable to MariaDB 10.x without window functions. Missing plugin ids
     * (never run) appear in the result with an empty list, so the caller can
     * iterate the input array without key checks.
     *
     * @param list<string> $pluginIds
     * @return array<string, list<array{run_at: DateTimeImmutable, status: string, item_count: int, error_message: ?string, duration_ms: int}>>
     */
    public function recentForPlugins(array $pluginIds, int $limit = 20): array
    {
        $out = [];
        foreach ($pluginIds as $id) {
            $out[$id] = [];
        }
        if ($pluginIds === []) {
            return $out;
        }

        $limit = max(1, min($limit, self::MAX_LIMIT));
        $legs  = [];
        $binds = [];
        foreach ($pluginIds as $id) {
            $legs[]  = '(SELECT plugin_id, run_at, status, item_count, error_message, duration_ms
                FROM plugin_run_log
                WHERE plugin_id = ?
                ORDER BY run_at DESC
                LIMIT ' . (int)$limit . ')';
            $binds[] = $id;
        }
        $sql = implode("\nUNION ALL\n", $legs);

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($binds);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $pid = (string)$row['plugin_id'];
                $out[$pid][] = [
                    'run_at'        => new DateTimeImmutable((string)$row['run_at'], new DateTimeZone('UTC')),
                    'status'        => (string)$row['status'],
                    'item_count'    => (int)$row['item_count'],
                    'error_message' => $row['error_message'] !== null ? (string)$row['error_message'] : null,
                    'duration_ms'   => (int)$row['duration_ms'],
                ];
            }
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return $out;
            }
            throw $e;
        }

        return $out;
    }
}
