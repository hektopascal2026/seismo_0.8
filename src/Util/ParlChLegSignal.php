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

    /** Default Leg list: Antwort BR rows stay visible this many Zurich days after Stellungnahme. */
    public const ANTWORT_BR_FEED_LOOKBACK_DAYS = 7;

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

        $status = (string)($row['status'] ?? 'scheduled');

        if ($isInsert) {
            if ($hasBr) {
                $meta['leg_signal'] = self::SIGNAL_ANTWORT_BR;
                $meta['leg_feed_at'] = self::canonicalFeedAt($meta, self::SIGNAL_ANTWORT_BR, $existingCreatedAt);
            } elseif ($status !== 'completed') {
                $meta['leg_signal'] = self::SIGNAL_NEW;
                $meta['leg_feed_at'] = self::canonicalFeedAt($meta, self::SIGNAL_NEW, $existingCreatedAt);
            }
        } elseif ($hasBr) {
            if ($hadBr && is_array($existingMetadata) && isset($existingMetadata['leg_signal'])) {
                $meta['leg_signal'] = (string)$existingMetadata['leg_signal'];
            } else {
                $meta['leg_signal'] = self::SIGNAL_ANTWORT_BR;
            }
            if ($meta['leg_signal'] === self::SIGNAL_ANTWORT_BR) {
                $meta['leg_feed_at'] = self::canonicalFeedAt($meta, self::SIGNAL_ANTWORT_BR, $existingCreatedAt);
            } elseif (is_array($existingMetadata) && isset($existingMetadata['leg_feed_at'])) {
                $meta['leg_feed_at'] = (string)$existingMetadata['leg_feed_at'];
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
     * @param array<string, mixed> $meta incoming row metadata (incl. br_response_date / submission_date)
     */
    public static function canonicalFeedAt(array $meta, string $signal, ?string $existingCreatedAt): string
    {
        if ($signal === self::SIGNAL_ANTWORT_BR) {
            $dateOnly = trim((string)($meta['br_response_date'] ?? ''));
            if (self::isDateOnly($dateOnly)) {
                return $dateOnly . ' 12:00:00';
            }
        }
        if ($signal === self::SIGNAL_NEW) {
            $dateOnly = trim((string)($meta['submission_date'] ?? ''));
            if (self::isDateOnly($dateOnly)) {
                return $dateOnly . ' 12:00:00';
            }
        }

        $created = trim((string)$existingCreatedAt);
        if ($created !== '') {
            return $created;
        }

        return gmdate('Y-m-d H:i:s');
    }

    private static function isDateOnly(string $value): bool
    {
        return $value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
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
