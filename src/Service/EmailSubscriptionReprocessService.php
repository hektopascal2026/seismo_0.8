<?php

declare(strict_types=1);

namespace Seismo\Service;

use PDO;
use Seismo\Core\Mail\EmailIngestNormalizer;
use Seismo\Core\Mail\EmailListingBoilerplateStripper;
use Seismo\Core\Mail\EmailSubscriptionProcessor;
use Seismo\Repository\EmailIngestRepository;
use Seismo\Repository\EmailSubscriptionRepository;

/**
 * Re-run body normalization and subscription processors on stored mail (no Gmail refetch).
 */
final class EmailSubscriptionReprocessService
{
    private const BATCH_LIMIT = 200;

    public function __construct(private PDO $pdo)
    {
    }

    public function reprocessSubscription(int $subscriptionId): int
    {
        if (isSatellite()) {
            throw new \RuntimeException('Email reprocess must not run on a satellite.');
        }
        $subRepo = new EmailSubscriptionRepository($this->pdo);
        $sub     = $subRepo->findById($subscriptionId);
        if ($sub === null) {
            throw new \InvalidArgumentException('Subscription not found.');
        }

        $matchType  = (string)($sub['match_type'] ?? '');
        $matchValue = (string)($sub['match_value'] ?? '');
        $ingest     = new EmailIngestRepository($this->pdo);
        $rows       = $ingest->fetchRowsForSubscriptionMatch($matchType, $matchValue, self::BATCH_LIMIT);
        if ($rows === []) {
            return 0;
        }

        $subs = $subRepo->listActive(EmailSubscriptionRepository::MAX_LIMIT, 0);
        $n    = 0;
        foreach ($rows as $row) {
            $row = EmailIngestNormalizer::normalizeBodies($row);
            $ui  = EmailSubscriptionRepository::resolveSubscriptionUiForFromEmail((string)($row['from_email'] ?? ''), $subs);
            if (!empty($ui['strip_listing_boilerplate'])) {
                $subj = trim((string)($row['subject'] ?? ''));
                foreach (['text_body', 'body_text'] as $key) {
                    $t = (string)($row[$key] ?? '');
                    if ($t !== '') {
                        $row[$key] = EmailListingBoilerplateStripper::strip($t, $subj !== '' ? $subj : null);
                    }
                }
            }
            $row = EmailSubscriptionProcessor::apply($row, $subs);
            $ingest->updateProcessedContent(
                (int)$row['id'],
                trim((string)($row['text_body'] ?? $row['body_text'] ?? '')),
                self::nullIfEmpty((string)($row['derived_title'] ?? ''))
            );
            ++$n;
        }

        return $n;
    }

    private static function nullIfEmpty(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
