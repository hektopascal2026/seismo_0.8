<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

use Seismo\Core\Mail\Processor\DynamicRegexEmailProcessor;
use Seismo\Repository\EmailSubscriptionRepository;

/**
 * Apply the best matching subscription {@see EmailBodyProcessorInterface} and custom cleanup_config at ingest / reprocess.
 */
final class EmailSubscriptionProcessor
{
    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $subscriptionRows
     * @return array<string, mixed>
     */
    public static function apply(array $row, array $subscriptionRows): array
    {
        $from = trim((string)($row['from_email'] ?? ''));
        if ($from === '') {
            return $row;
        }

        // Find the best matching non-disabled, active subscription row
        $bestRank = 0;
        $bestRow  = null;
        foreach ($subscriptionRows as $sub) {
            if (!empty($sub['disabled']) || !empty($sub['auto_detected'])) {
                continue;
            }
            $mt = (string)($sub['match_type'] ?? '');
            $mv = (string)($sub['match_value'] ?? '');
            if (!EmailSubscriptionRepository::matchesAddress($from, $mt, $mv)) {
                continue;
            }
            $rank = $mt === 'email' ? 2 : 1;
            if ($rank > $bestRank) {
                $bestRank = $rank;
                $bestRow  = $sub;
            }
        }

        if ($bestRow === null) {
            return $row;
        }

        // 1. Run dynamic regex processor if cleanup_config is populated and specific rules are enabled
        $cfgJson = trim((string)($bestRow['cleanup_config'] ?? ''));
        if ($cfgJson !== '' && !empty($bestRow['strip_listing_boilerplate'])) {
            $config = json_decode($cfgJson, true);
            if (is_array($config)) {
                $dynProcessor = new DynamicRegexEmailProcessor($config);
                $row = $dynProcessor->process($row);
            }
        }

        // 2. Run standard body processor if specified
        $key = trim((string)($bestRow['body_processor'] ?? ''));
        if ($key !== '') {
            $processor = EmailBodyProcessorRegistry::get($key);
            if ($processor !== null) {
                $row = $processor->process($row);
            }
        }

        return $row;
    }
}
