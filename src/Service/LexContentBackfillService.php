<?php

declare(strict_types=1);

namespace Seismo\Service;

use Seismo\Core\Lex\LexJusDecisionMapper;
use Seismo\Repository\LexItemRepository;
use Seismo\Repository\TimelineFilter;
use Seismo\Service\Http\BaseClient;

/**
 * Phase-B backfill for `lex_items.content` on rows whose metadata refresh already ran.
 */
final class LexContentBackfillService
{
    public const DEFAULT_BATCH = 50;

    public function __construct(
        private LexItemRepository $lex,
        private BaseClient $http = new BaseClient(30),
    ) {
    }

    public static function boot(\PDO $pdo): self
    {
        return new self(new LexItemRepository($pdo));
    }

    /**
     * @return array{updated: int, skipped: int, failed: int}
     */
    public function backfillJus(int $limit = self::DEFAULT_BATCH): array
    {
        if (isSatellite()) {
            throw new \RuntimeException('Lex content backfill must run on the mothership.');
        }

        $limit = max(1, min($limit, LexItemRepository::MAX_LIMIT));
        $rows  = $this->lex->listMissingContentBySources(TimelineFilter::JUS_LEX_SOURCES, $limit);

        $updated = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($rows as $row) {
            $id      = (int)($row['id'] ?? 0);
            $workUri = trim((string)($row['work_uri'] ?? ''));
            if ($id <= 0 || $workUri === '') {
                $skipped++;
                continue;
            }

            $corpus = LexJusDecisionMapper::fetchCorpusFromWorkUri($this->http, $workUri);
            if ($corpus === null) {
                $failed++;
                continue;
            }
            $content = trim((string)($corpus['content'] ?? ''));
            if ($content === '') {
                $skipped++;
                continue;
            }

            if ($this->lex->updateCorpus($id, $content, $corpus['description'] ?? null)) {
                $updated++;
            } else {
                $failed++;
            }
        }

        return ['updated' => $updated, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Promote existing DE RSS body text from `description` into `content` (one-shot helper).
     */
    public function backfillDeFromDescription(int $limit = 500): int
    {
        if (isSatellite()) {
            throw new \RuntimeException('Lex content backfill must run on the mothership.');
        }

        return $this->lex->promoteDescriptionToContent('de', max(1, min($limit, 5000)));
    }
}
