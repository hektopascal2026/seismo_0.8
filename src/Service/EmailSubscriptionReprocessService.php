<?php

declare(strict_types=1);

namespace Seismo\Service;

use PDO;
use Seismo\Core\Mail\DigestSplitConfigNormalizer;
use Seismo\Core\Mail\EmailIngestNormalizer;
use Seismo\Core\Mail\EmailListingBoilerplatePolicy;
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

        $ingest     = new EmailIngestRepository($this->pdo);
        $subs       = $subRepo->listActive(EmailSubscriptionRepository::MAX_LIMIT, 0);
        $total      = 0;
        $offset     = 0;

        while (true) {
            $rows = $ingest->fetchRowsForSubscription(
                $sub,
                self::BATCH_LIMIT,
                $offset,
            );
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $htmlBeforeNormalize = trim((string)($row['html_body'] ?? $row['body_html'] ?? ''));
                $row = EmailIngestNormalizer::normalizeBodies($row);
                $plainAfterNormalize = trim((string)($row['text_body'] ?? $row['body_text'] ?? ''));

                $customKeywords = [];
                $from = trim((string)($row['from_email'] ?? ''));
                if ($from !== '') {
                    foreach ($subs as $s) {
                        if (empty($s['disabled']) && !empty($s['cleanup_config'])) {
                            $mt = (string)($s['match_type'] ?? '');
                            $mv = (string)($s['match_value'] ?? '');
                            if (EmailSubscriptionRepository::matchesAddress($from, $mt, $mv)) {
                                $cfg = json_decode((string)$s['cleanup_config'], true);
                                if (is_array($cfg) && !empty($cfg['webview_keywords'])) {
                                    $customKeywords = (array)$cfg['webview_keywords'];
                                }
                                break;
                            }
                        }
                    }
                }

                $remaining = null;
                $row = $ingest->applyWebViewProcessing($row, $htmlBeforeNormalize, $plainAfterNormalize, true, $remaining, $customKeywords);
                $ui  = EmailSubscriptionRepository::resolveSubscriptionUiForFromEmail(
                    (string)($row['from_email'] ?? ''),
                    $subs,
                    isset($row['subject']) ? (string)$row['subject'] : null,
                );
                if (EmailListingBoilerplatePolicy::shouldStrip($ui)) {
                    $subj = trim((string)($row['subject'] ?? ''));
                    foreach (['text_body', 'body_text'] as $key) {
                        $t = (string)($row[$key] ?? '');
                        if ($t !== '') {
                            $row[$key] = EmailListingBoilerplateStripper::strip($t, $subj !== '' ? $subj : null);
                        }
                    }
                }
                $row = EmailSubscriptionProcessor::apply($row, $subs);
                $ingest->updateEmailSubscriptionId((int)$row['id'], $subscriptionId);
                $ingest->updateProcessedContent(
                    (int)$row['id'],
                    trim((string)($row['text_body'] ?? $row['body_text'] ?? '')),
                    self::nullIfEmpty((string)($row['derived_title'] ?? '')),
                    isset($row['metadata']) && $row['metadata'] !== null ? (string)$row['metadata'] : null
                );

                if (!empty($sub['digest_split_config'])) {
                    $cfg = DigestSplitConfigNormalizer::resolveForIngest((string)$sub['digest_split_config']);
                    if ($cfg !== null) {
                        $ingest->splitAndIngestStories((int)$row['id'], $row, $cfg);
                    }
                }

                ++$total;
            }

            if (count($rows) < self::BATCH_LIMIT) {
                break;
            }
            $offset += self::BATCH_LIMIT;
        }

        return $total;
    }

    private static function nullIfEmpty(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
