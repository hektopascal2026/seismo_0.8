<?php

declare(strict_types=1);

namespace Seismo\Util;

/**
 * Canonical dashboard timeline instants and card clocks (bottom-right).
 *
 * Feed, email, Lex, and Leg card clocks and sort keys all use UTC instants from
 * the DB, displayed in {@see viewTimezone()} (Europe/Zurich) so the timeline
 * order matches the bottom-right time on every card.
 * Lex/Leg: official date on the card (`document_date` / `event_date`); when that
 * is date-only, sort and clock use `created_at` (ingestion), like other news.
 */
final class TimelineEntryDatetime
{
    public const CARD_FORMAT_DATETIME = 'd.m.Y H:i';
    public const CARD_FORMAT_DATE     = 'd.m.Y';

    public static function viewTimezone(): \DateTimeZone
    {
        static $cached = null;
        if ($cached instanceof \DateTimeZone) {
            return $cached;
        }
        $name = defined('SEISMO_VIEW_TIMEZONE') ? (string)SEISMO_VIEW_TIMEZONE : 'Europe/Zurich';
        if ($name === '') {
            $name = 'Europe/Zurich';
        }
        try {
            $cached = new \DateTimeZone($name);
        } catch (\Exception $e) {
            $cached = new \DateTimeZone('Europe/Zurich');
        }

        return $cached;
    }

    public static function parseStoredUtcDatetime(?string $stored): ?\DateTimeImmutable
    {
        $stored = trim((string)$stored);
        if ($stored === '') {
            return null;
        }
        $utc = new \DateTimeZone('UTC');
        $dt  = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $stored, $utc);
        if ($dt !== false) {
            return $dt;
        }

        try {
            return new \DateTimeImmutable($stored, $utc);
        } catch (\Exception) {
            return null;
        }
    }

    public static function storedUtcToUnix(?string $stored): int
    {
        return self::parseStoredUtcDatetime($stored)?->getTimestamp() ?? 0;
    }

    /**
     * Email / generic UTC-stored columns → Zurich card clock.
     */
    public static function formatStoredUtcDatetime(?string $stored, string $format = self::CARD_FORMAT_DATETIME): string
    {
        $dt = self::parseStoredUtcDatetime($stored);
        if ($dt === null) {
            return '';
        }

        return $dt->setTimezone(self::viewTimezone())->format($format);
    }

    /** Calendar day key for timeline separators (view timezone). */
    public static function timelineDayKeyInViewTz(int $unix): string
    {
        if ($unix <= 0) {
            return '';
        }

        return (new \DateTimeImmutable('@' . $unix))
            ->setTimezone(self::viewTimezone())
            ->format('Y-m-d');
    }

    /**
     * Card clock from wrapper `date` unix — always matches {@see sortMergedTimeline()}.
     */
    public static function formatCardClockFromUnix(int $unix, bool $dateOnly): string
    {
        if ($unix <= 0) {
            return '';
        }

        $tz = self::viewTimezone();
        $dt = (new \DateTimeImmutable('@' . $unix))->setTimezone($tz);

        return $dt->format($dateOnly ? self::CARD_FORMAT_DATE : self::CARD_FORMAT_DATETIME);
    }

    /**
     * Start of calendar day in view TZ (date-only sources).
     */
    public static function unixDateOnlyStartInViewTz(?string $dateOnly): int
    {
        $dateOnly = trim((string)$dateOnly);
        if ($dateOnly === '') {
            return 0;
        }

        $viewTz = self::viewTimezone();
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOnly)) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $dateOnly, $viewTz);

            return $dt !== false ? $dt->getTimestamp() : 0;
        }

        $parsed = self::parseStoredUtcDatetime($dateOnly);
        if ($parsed !== null) {
            return $parsed->setTimezone($viewTz)->setTime(0, 0)->getTimestamp();
        }

        return 0;
    }

    /**
     * End of calendar day in view TZ — date-only rows sort after timed entries the same day.
     */
    public static function unixDateOnlyEndInViewTz(?string $dateOnly): int
    {
        $dateOnly = trim((string)$dateOnly);
        if ($dateOnly === '') {
            return 0;
        }

        $viewTz = self::viewTimezone();
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOnly)) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $dateOnly, $viewTz);
            if ($dt === false) {
                return 0;
            }

            return $dt->setTime(23, 59, 59)->getTimestamp();
        }

        return self::unixDateOnlyStartInViewTz($dateOnly);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function feedItemStoredDatetime(array $row): ?string
    {
        $pub = trim((string)($row['published_date'] ?? ''));
        if ($pub !== '') {
            return $pub;
        }
        $cached = trim((string)($row['cached_at'] ?? ''));
        if ($cached !== '') {
            return $cached;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function feedItemUnix(array $row): int
    {
        $raw = self::feedItemStoredDatetime($row);

        return $raw !== null ? self::storedUtcToUnix($raw) : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function formatFeedItemDatetime(array $row, string $format = self::CARD_FORMAT_DATETIME): string
    {
        $raw = self::feedItemStoredDatetime($row);

        return $raw !== null ? self::formatStoredUtcDatetime($raw, $format) : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function emailStoredDatetime(array $row): ?string
    {
        foreach (['date_received', 'date_utc', 'created_at', 'date_sent'] as $col) {
            $v = trim((string)($row[$col] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function emailUnix(array $row): int
    {
        $raw = self::emailStoredDatetime($row);

        return $raw !== null ? self::storedUtcToUnix($raw) : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function formatEmailDatetime(array $row, string $format = self::CARD_FORMAT_DATETIME): string
    {
        $raw = self::emailStoredDatetime($row);

        return $raw !== null ? self::formatStoredUtcDatetime($raw, $format) : '';
    }

    public static function isDateOnlyString(?string $value): bool
    {
        $value = trim((string)$value);

        return $value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    /**
     * Official publication / session date is date-only → timeline uses ingestion.
     *
     * @param array<string, mixed> $row
     */
    public static function unixForOfficialDateOrIngestion(array $row, string $officialDateField): int
    {
        $official = trim((string)($row[$officialDateField] ?? ''));
        if ($official === '') {
            return 0;
        }

        if (!self::isDateOnlyString($official)) {
            $parsed = self::parseStoredUtcDatetime($official);
            if ($parsed !== null) {
                return $parsed->getTimestamp();
            }

            return self::unixDateOnlyEndInViewTz($official);
        }

        $created = trim((string)($row['created_at'] ?? ''));
        if ($created !== '') {
            return self::storedUtcToUnix($created);
        }

        return self::unixDateOnlyEndInViewTz($official);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function cardShowsDateOnly(array $row, string $officialDateField): bool
    {
        return self::isDateOnlyString($row[$officialDateField] ?? null)
            && trim((string)($row['created_at'] ?? '')) === '';
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function lexItemUnix(array $row): int
    {
        return self::unixForOfficialDateOrIngestion($row, 'document_date');
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function lexItemCardIsDateOnly(array $row): bool
    {
        return self::cardShowsDateOnly($row, 'document_date');
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function formatLexItemDatetime(array $row): string
    {
        $unix = self::lexItemUnix($row);
        if ($unix <= 0) {
            return '';
        }

        return self::formatCardClockFromUnix($unix, self::lexItemCardIsDateOnly($row));
    }

    /**
     * Leg rows with `leg_signal` sort/display via `metadata.leg_feed_at` (substantive
     * date); others fall back to Lex-style official/ingestion rules.
     *
     * @param array<string, mixed> $row
     */
    public static function calendarEventUnix(array $row): int
    {
        $feedUnix = self::calendarEventLegFeedUnix($row);
        if ($feedUnix > 0) {
            return $feedUnix;
        }

        return self::unixForOfficialDateOrIngestion($row, 'event_date');
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function calendarEventLegFeedUnix(array $row): int
    {
        $meta = self::decodeRowMetadata($row);
        $signal = $meta['leg_signal'] ?? null;
        if ($signal !== 'new' && $signal !== 'antwort_br') {
            return 0;
        }
        $feedAt = trim((string)($meta['leg_feed_at'] ?? ''));

        return $feedAt !== '' ? self::storedUtcToUnix($feedAt) : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function calendarEventCardIsDateOnly(array $row): bool
    {
        if (self::calendarEventLegFeedUnix($row) > 0) {
            return false;
        }

        return self::cardShowsDateOnly($row, 'event_date');
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function formatCalendarEventDate(array $row): string
    {
        $unix = self::calendarEventUnix($row);
        if ($unix <= 0) {
            return '';
        }

        return self::formatCardClockFromUnix($unix, self::calendarEventCardIsDateOnly($row));
    }

    /**
     * @param array<string, mixed> $wrapper dashboard entry wrapper from EntryRepository
     */
    public static function formatWrapperCardClock(array $wrapper): string
    {
        $unix = (int)($wrapper['date'] ?? 0);
        if ($unix <= 0) {
            return '';
        }

        $entryType = (string)($wrapper['entry_type'] ?? '');
        $row       = is_array($wrapper['data'] ?? null) ? $wrapper['data'] : [];

        return match ($entryType) {
            'feed_item'       => self::formatFeedItemDatetime($row),
            'email'           => self::formatEmailDatetime($row),
            'lex_item'        => self::formatLexItemDatetime($row),
            'calendar_event'  => self::formatCalendarEventDate($row),
            default           => self::formatCardClockFromUnix($unix, false),
        };
    }

    /**
     * @param array<string, mixed> $wrapper
     */
    public static function wrapperCardIsDateOnly(array $wrapper): bool
    {
        $entryType = (string)($wrapper['entry_type'] ?? '');
        $row       = is_array($wrapper['data'] ?? null) ? $wrapper['data'] : [];

        return match ($entryType) {
            'lex_item'       => self::lexItemCardIsDateOnly($row),
            'calendar_event' => self::calendarEventCardIsDateOnly($row),
            default          => false,
        };
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decodeRowMetadata(array $row): array
    {
        $raw = $row['metadata'] ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Exception) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
