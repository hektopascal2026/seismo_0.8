<?php

declare(strict_types=1);

namespace Seismo\Util;

/**
 * Leg feed signals for parlament.ch Geschäfte (first ingest vs new Bundesrat response).
 */
final class ParlChLegSignal
{
    public const SIGNAL_NEW = 'new';

    public const SIGNAL_ANTWORT_BR = 'antwort_br';

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $existingMetadata prior metadata when row already exists
     */
    public static function applyToBusinessRow(array $row, ?array $existingMetadata, bool $isInsert): array
    {
        $externalId = (string)($row['external_id'] ?? '');
        if (str_starts_with($externalId, 'session_')) {
            return $row;
        }

        $meta = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
        $hasBr = self::metadataHasBrResponse($meta);
        $hadBr = is_array($existingMetadata) && self::metadataHasBrResponse($existingMetadata);

        $now = gmdate('Y-m-d H:i:s');

        if ($isInsert) {
            $meta['leg_signal'] = $hasBr ? self::SIGNAL_ANTWORT_BR : self::SIGNAL_NEW;
            $meta['leg_feed_at'] = $now;
        } elseif (!$hadBr && $hasBr) {
            $meta['leg_signal'] = self::SIGNAL_ANTWORT_BR;
            $meta['leg_feed_at'] = $now;
        } elseif (is_array($existingMetadata)) {
            if (isset($existingMetadata['leg_signal'])) {
                $meta['leg_signal'] = $existingMetadata['leg_signal'];
            }
            if (isset($existingMetadata['leg_feed_at'])) {
                $meta['leg_feed_at'] = $existingMetadata['leg_feed_at'];
            }
        }

        $row['metadata'] = $meta;

        return $row;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function metadataHasBrResponse(array $metadata): bool
    {
        return !empty($metadata['has_br_response']);
    }

    public static function signalLabel(?string $signal): string
    {
        return match ($signal) {
            self::SIGNAL_NEW => 'New',
            self::SIGNAL_ANTWORT_BR => 'Antwort BR',
            default => '',
        };
    }
}
