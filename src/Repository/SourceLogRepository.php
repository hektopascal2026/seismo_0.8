<?php
/**
 * Append-only log of new RSS / Substack / Parl. press feeds, scraper sources, and mail subscriptions.
 */

declare(strict_types=1);

namespace Seismo\Repository;

use PDO;
use PDOException;

final class SourceLogRepository
{
    public const KIND_RSS         = 'rss';
    public const KIND_SUBSTACK    = 'substack';
    public const KIND_PARL_PRESS  = 'parl_press';
    public const KIND_SCRAPER     = 'scraper';
    public const KIND_MAIL        = 'mail';

    /** @var list<string> */
    public const KINDS = [
        self::KIND_RSS,
        self::KIND_SUBSTACK,
        self::KIND_PARL_PRESS,
        self::KIND_SCRAPER,
        self::KIND_MAIL,
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function append(string $kind, int $refId, string $labelSnapshot): void
    {
        if (!in_array($kind, self::KINDS, true) || $refId <= 0) {
            return;
        }
        $labelSnapshot = mb_substr(trim($labelSnapshot), 0, 255, 'UTF-8');
        if ($labelSnapshot === '') {
            $labelSnapshot = '(untitled)';
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO source_log (kind, ref_id, label_snapshot) VALUES (?, ?, ?)'
        );
        $stmt->execute([$kind, $refId, $labelSnapshot]);
    }

    /**
     * Newest first.
     *
     * @return list<array{id:int|string, occurred_at:string, kind:string, ref_id:int, label_snapshot:string}>
     */
    public function listRecent(int $limit = 1000): array
    {
        $limit = max(1, min(5000, $limit));
        try {
            $stmt = $this->pdo->query(
                'SELECT id, occurred_at, kind, ref_id, label_snapshot
                   FROM source_log
                  ORDER BY occurred_at DESC, id DESC
                  LIMIT ' . $limit
            );

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), '1146') && str_contains($e->getMessage(), 'source_log')) {
                return [];
            }
            throw $e;
        }
    }
}
