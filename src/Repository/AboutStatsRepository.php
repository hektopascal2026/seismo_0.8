<?php

declare(strict_types=1);

namespace Seismo\Repository;

use PDO;
use PDOException;

/**
 * Read-only row counts for the in-app About page.
 *
 * Entry-source tables use {@see entryTable()} so satellite installs count the
 * mothership. Never used for unbounded list payloads — single COUNT per family.
 */
final class AboutStatsRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{
     *   feeds: int,
     *   feed_items: int,
     *   emails: int,
     *   lex_items: int,
     *   calendar_events: int,
     *   scraper_configs: int
     * }
     */
    public function entrySnapshot(): array
    {
        return [
            'feeds'            => $this->countFrom(entryTable('feeds')),
            'feed_items'       => $this->countFrom(entryTable('feed_items')),
            'emails'           => $this->countFrom(entryTable('emails')),
            'lex_items'        => $this->countFrom(entryTable('lex_items')),
            'calendar_events' => $this->countFrom(entryTable('calendar_events')),
            'scraper_configs'  => $this->countFrom(entryTable('scraper_configs')),
        ];
    }

    /**
     * @param string $fromExpr SQL FROM fragment, e.g. `feed_items` or `` `db`.`feed_items` ``
     */
    private function countFrom(string $fromExpr): int
    {
        try {
            $sql = 'SELECT COUNT(*) FROM ' . $fromExpr;

            return (int)$this->pdo->query($sql)->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }
}
