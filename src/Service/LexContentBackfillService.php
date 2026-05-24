<?php

declare(strict_types=1);

namespace Seismo\Service;

use Seismo\Config\LexConfigStore;
use Seismo\Core\Lex\LexEurLexContentFetcher;
use Seismo\Core\Lex\LexJusDecisionMapper;
use Seismo\Core\Lex\LexLegifranceApiClient;
use Seismo\Core\Lex\LexLegifranceContentFetcher;
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
        private LexEurLexContentFetcher $eurLex = new LexEurLexContentFetcher(),
    ) {
    }

    public static function boot(\PDO $pdo): self
    {
        return new self(
            new LexItemRepository($pdo),
            new BaseClient(30),
            new LexEurLexContentFetcher(new BaseClient(45)),
        );
    }

    /**
     * @param array<string, int>|null $reasons
     * @return array{updated: int, skipped: int, failed: int}
     */
    public function backfillJus(int $limit = self::DEFAULT_BATCH, ?array &$reasons = null): array
    {
        if (isSatellite()) {
            throw new \RuntimeException('Lex content backfill must run on the mothership.');
        }

        if ($reasons !== null) {
            $reasons = [];
        }

        $limit = max(1, min($limit, LexItemRepository::MAX_LIMIT));
        $rows  = $this->lex->listMissingContentBySources(TimelineFilter::JUS_LEX_SOURCES, $limit);

        $updated = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($rows as $row) {
            $id      = (int)($row['id'] ?? 0);
            $celex   = trim((string)($row['celex'] ?? ''));
            $workUri = trim((string)($row['work_uri'] ?? ''));
            if ($id <= 0) {
                $skipped++;
                $this->noteReason($reasons, 'invalid_row');
                continue;
            }

            $corpus = LexJusDecisionMapper::fetchCorpusForRow(
                $this->http,
                $celex,
                $workUri !== '' ? $workUri : null,
            );
            if ($corpus === null) {
                $failed++;
                $this->noteReason($reasons, 'fetch_failed');
                continue;
            }
            $content = trim((string)($corpus['content'] ?? ''));
            if ($content === '') {
                $skipped++;
                $this->noteReason($reasons, 'empty_corpus');
                continue;
            }

            if ($this->lex->updateCorpus($id, $content, $corpus['description'] ?? null)) {
                $updated++;
            } else {
                $failed++;
                $this->noteReason($reasons, 'db_update_failed');
            }
        }

        return ['updated' => $updated, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * @return array{updated: int, skipped: int, failed: int, reasons: array<string, int>}
     */
    public function backfillJusDetailed(int $limit = self::DEFAULT_BATCH, bool $verbose = false): array
    {
        $reasons = [];
        $result  = $this->backfillJus($limit, $reasons);
        $result['reasons'] = $reasons;

        if ($verbose && $reasons !== []) {
            foreach ($reasons as $reason => $count) {
                fwrite(STDOUT, "  {$reason}: {$count}\n");
            }
        }

        return $result;
    }

    /**
     * Promote existing Fedlex consultation text from `description` into `content`.
     */
    public function backfillChFromDescription(int $limit = 500): int
    {
        if (isSatellite()) {
            throw new \RuntimeException('Lex content backfill must run on the mothership.');
        }

        return $this->lex->promoteDescriptionToContent('ch', max(1, min($limit, 5000)));
    }

    /**
     * @return array{updated: int, skipped: int, failed: int}
     */
    public function backfillEuFromEurlex(int $limit = self::DEFAULT_BATCH, ?array &$reasons = null): array
    {
        if (isSatellite()) {
            throw new \RuntimeException('Lex content backfill must run on the mothership.');
        }

        if ($reasons !== null) {
            $reasons = [];
        }

        $limit = max(1, min($limit, LexItemRepository::MAX_LIMIT));
        $rows  = $this->lex->listMissingContentBySources(['eu'], $limit);

        $updated = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($rows as $row) {
            $id  = (int)($row['id'] ?? 0);
            $url = trim((string)($row['eurlex_url'] ?? ''));
            if ($id <= 0 || $url === '') {
                $skipped++;
                $this->noteReason($reasons, 'no_eurlex_url');
                continue;
            }

            $content = $this->eurLex->fetchPlainTextFromUrl($url);
            if ($content === null || $content === '') {
                $skipped++;
                $this->noteReason($reasons, 'empty_corpus');
                continue;
            }

            $description = trim((string)($row['description'] ?? ''));
            if ($description === '') {
                $description = null;
            }

            if ($this->lex->updateCorpus($id, $content, $description)) {
                $updated++;
            } else {
                $failed++;
                $this->noteReason($reasons, 'db_update_failed');
            }
        }

        return ['updated' => $updated, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * @return array{updated: int, skipped: int, failed: int, reasons: array<string, int>}
     */
    public function backfillEuDetailed(int $limit = self::DEFAULT_BATCH, bool $verbose = false): array
    {
        $reasons = [];
        $result  = $this->backfillEuFromEurlex($limit, $reasons);
        $result['reasons'] = $reasons;

        if ($verbose && $reasons !== []) {
            foreach ($reasons as $reason => $count) {
                fwrite(STDOUT, "  {$reason}: {$count}\n");
            }
        }

        return $result;
    }

    /**
     * Fetch JORF full text via PISTE /consult/jorf for stored FR rows.
     *
     * @return array{updated: int, skipped: int, failed: int}
     */
    public function backfillFrFromLegifrance(int $limit = self::DEFAULT_BATCH, ?array &$reasons = null): array
    {
        if (isSatellite()) {
            throw new \RuntimeException('Lex content backfill must run on the mothership.');
        }

        if ($reasons !== null) {
            $reasons = [];
        }

        $frCfg = (new LexConfigStore())->load()['fr'] ?? [];
        if (!is_array($frCfg)) {
            throw new \RuntimeException('Légifrance configuration block is missing.');
        }

        $client  = LexLegifranceApiClient::fromConfig($frCfg);
        $fetcher = new LexLegifranceContentFetcher($client);

        $limit = max(1, min($limit, LexItemRepository::MAX_LIMIT));
        $rows  = $this->lex->listMissingContentBySources(['fr'], $limit);

        $updated = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                $skipped++;
                $this->noteReason($reasons, 'invalid_row');
                continue;
            }

            $consultId = LexLegifranceContentFetcher::consultIdFromRow($row);
            if ($consultId === null) {
                $skipped++;
                $this->noteReason($reasons, 'no_jorf_text_cid');
                continue;
            }

            $reason = null;
            $title  = trim((string)($row['title'] ?? ''));
            $content = $fetcher->fetchPlainTextForConsultId(
                $consultId,
                $reason,
                $title !== '' ? $title : null,
            );
            if ($content === null || $content === '') {
                $skipped++;
                $this->noteReason($reasons, $reason ?? 'empty_corpus');
                continue;
            }

            $description = trim((string)($row['description'] ?? ''));
            if ($description === '') {
                $description = null;
            }

            if ($this->lex->updateCorpus($id, $content, $description)) {
                $updated++;
            } else {
                $failed++;
                $this->noteReason($reasons, 'db_update_failed');
            }
        }

        return ['updated' => $updated, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * @return array{updated: int, skipped: int, failed: int, reasons: array<string, int>}
     */
    public function backfillFrDetailed(int $limit = self::DEFAULT_BATCH, bool $verbose = false): array
    {
        $reasons = [];
        $result  = $this->backfillFrFromLegifrance($limit, $reasons);
        $result['reasons'] = $reasons;

        if ($verbose && $reasons !== []) {
            foreach ($reasons as $reason => $count) {
                fwrite(STDOUT, "  {$reason}: {$count}\n");
            }
        }

        return $result;
    }

    /**
     * Promote Légifrance synopsis already stored in `description` into `content`.
     */
    public function backfillFrFromDescription(int $limit = 500): int
    {
        if (isSatellite()) {
            throw new \RuntimeException('Lex content backfill must run on the mothership.');
        }

        return $this->lex->promoteDescriptionToContent('fr', max(1, min($limit, 5000)));
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

    /**
     * @return array<int, array{source: string, missing: int, no_work_uri: int, has_description: int}>
     */
    public function contentBackfillStats(): array
    {
        if (isSatellite()) {
            throw new \RuntimeException('Lex content backfill stats must run on the mothership.');
        }

        return $this->lex->contentBackfillStatsBySource();
    }

    /**
     * @param array<string, int>|null $reasons
     */
    private function noteReason(?array &$reasons, string $key): void
    {
        if ($reasons === null) {
            return;
        }
        $reasons[$key] = ($reasons[$key] ?? 0) + 1;
    }
}
