<?php
/**
 * Append-only log of new RSS / Substack / Parl. press feeds, scraper sources, and mail subscriptions.
 */

declare(strict_types=1);

namespace Seismo\Repository;

use DateTimeImmutable;
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
     * Log a new RSS / Substack / Parl. press feed (not scraper-backed feeds).
     */
    public function appendFeed(int $feedId, string $sourceType, string $title): void
    {
        $kind = self::feedKindForSourceType($sourceType);
        if ($kind === null) {
            return;
        }
        $this->append($kind, $feedId, $title);
    }

    public function appendFeedQuietly(int $feedId, string $sourceType, string $title): void
    {
        try {
            $this->appendFeed($feedId, $sourceType, $title);
        } catch (\Throwable $e) {
            $kind = self::feedKindForSourceType($sourceType) ?? 'feed';
            error_log('Seismo source_log (' . $kind . '): ' . $e->getMessage());
        }
    }

    public static function feedKindForSourceType(string $sourceType): ?string
    {
        return match (strtolower(trim($sourceType))) {
            'rss'        => self::KIND_RSS,
            'substack'   => self::KIND_SUBSTACK,
            'parl_press' => self::KIND_PARL_PRESS,
            default      => null,
        };
    }

    /**
     * Best-effort append; never throws to callers (cron / ingest must not fail).
     */
    public function appendQuietly(string $kind, int $refId, string $labelSnapshot): void
    {
        try {
            $this->append($kind, $refId, $labelSnapshot);
        } catch (\Throwable $e) {
            error_log('Seismo source_log (' . $kind . '): ' . $e->getMessage());
        }
    }

    /**
     * Newest first.
     *
     * @return list<array{id:int|string, occurred_at:string, kind:string, ref_id:int, label_snapshot:string}>
     */
    public function listRecent(int $limit = 1000): array
    {
        $limit = max(1, min(5000, $limit));
        $stmt = $this->pdo->query(
            'SELECT id, occurred_at, kind, ref_id, label_snapshot
               FROM source_log
              ORDER BY occurred_at DESC, id DESC
              LIMIT ' . $limit
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function pruneOlderThan(DateTimeImmutable $cutoff): int
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM source_log WHERE occurred_at < ?');
            $stmt->execute([$cutoff->format('Y-m-d H:i:s')]);

            return $stmt->rowCount();
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return 0;
            }
            throw $e;
        }
    }
}
