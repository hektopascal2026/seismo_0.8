<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

/**
 * Apply the best matching subscription {@see EmailBodyProcessorInterface} at ingest / reprocess.
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
        $key = EmailBodyProcessorRegistry::resolveKeyForFromEmail((string)($row['from_email'] ?? ''), $subscriptionRows);
        if ($key === null) {
            return $row;
        }
        $processor = EmailBodyProcessorRegistry::get($key);
        if ($processor === null) {
            return $row;
        }

        return $processor->process($row);
    }
}
