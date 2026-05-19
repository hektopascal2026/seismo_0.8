<?php
/**
 * Migration 009 — `scraper_configs.exclude_selectors` (schema 25).
 *
 * Optional multiline text: one CSS- or XPath-style selector per line; matched
 * elements are removed from the DOM before text extraction.
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;

final class Migration009ScraperExcludeSelectors
{
    public const VERSION = 25;

    public function apply(PDO $pdo): void
    {
        if ($this->columnExists($pdo, 'scraper_configs', 'exclude_selectors')) {
            return;
        }
        try {
            $pdo->exec('ALTER TABLE scraper_configs ADD COLUMN exclude_selectors MEDIUMTEXT NULL AFTER date_selector');
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), '1060') && str_contains($e->getMessage(), 'exclude_selectors')) {
                return;
            }
            if (str_contains($e->getMessage(), '1054') && str_contains($e->getMessage(), 'date_selector')) {
                $pdo->exec('ALTER TABLE scraper_configs ADD COLUMN exclude_selectors MEDIUMTEXT NULL');
                return;
            }
            throw $e;
        }
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->query(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table) . " AND COLUMN_NAME = " . $pdo->quote($column) . " LIMIT 1"
            );
            if ($stmt === false) {
                return false;
            }
            $row = $stmt->fetchColumn();

            return $row !== false;
        } catch (PDOException) {
            return false;
        }
    }
}
