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
    public static function applyToBusinessRow(
        array $row,
        ?array $existingMetadata,
        bool $isInsert,
        ?string $existingContent = null,
        ?string $existingCreatedAt = null,
    ): array {
        $externalId = (string)($row['external_id'] ?? '');
        if (str_starts_with($externalId, 'session_')) {
            return $row;
        }

        $meta = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
        $hasBr = self::metadataHasBrResponse($meta);
        $incomingContent = trim((string)($row['content'] ?? ''));
        $hadBr = self::hadBrResponseAlready($existingMetadata, $existingContent, $incomingContent);

        $now = gmdate('Y-m-d H:i:s');

        if ($isInsert) {
            $meta['leg_signal'] = $hasBr ? self::SIGNAL_ANTWORT_BR : self::SIGNAL_NEW;
            $meta['leg_feed_at'] = $now;
        } elseif ($hasBr) {
            if ($hadBr) {
                if (is_array($existingMetadata) && isset($existingMetadata['leg_signal'])) {
                    $meta['leg_signal'] = $existingMetadata['leg_signal'];
                } else {
                    $meta['leg_signal'] = self::SIGNAL_ANTWORT_BR;
                }
                $meta['leg_feed_at'] = self::resolveLegFeedAt($existingMetadata, $existingCreatedAt);
            } else {
                $meta['leg_signal'] = self::SIGNAL_ANTWORT_BR;
                $meta['leg_feed_at'] = $now;
            }
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
     * @param array<string, mixed>|null $existingMetadata
     */
    public static function hadBrResponseAlready(
        ?array $existingMetadata,
        ?string $existingContent,
        ?string $incomingContent = null,
    ): bool {
        if (is_array($existingMetadata)) {
            if (self::metadataHasBrResponse($existingMetadata)) {
                return true;
            }
            if (($existingMetadata['leg_signal'] ?? '') === self::SIGNAL_ANTWORT_BR) {
                return true;
            }
        }

        $prior = trim((string)$existingContent);
        $incoming = trim((string)($incomingContent ?? ''));

        return $prior !== '' && $prior === $incoming;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function metadataHasBrResponse(array $metadata): bool
    {
        return !empty($metadata['has_br_response']);
    }

    /**
     * @param array<string, mixed>|null $existingMetadata
     */
    private static function resolveLegFeedAt(?array $existingMetadata, ?string $existingCreatedAt): string
    {
        if (is_array($existingMetadata) && isset($existingMetadata['leg_feed_at'])) {
            return (string)$existingMetadata['leg_feed_at'];
        }
        $created = trim((string)$existingCreatedAt);
        if ($created !== '') {
            return $created;
        }

        return gmdate('Y-m-d H:i:s');
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
