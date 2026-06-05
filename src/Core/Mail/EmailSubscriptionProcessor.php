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

        $subject = trim((string)($row['subject'] ?? ''));
        $bestRow = EmailSubscriptionRepository::findBestMatchingSubscription($from, $subject, $subscriptionRows);

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
