<?php

declare(strict_types=1);

namespace Seismo\Util;

/**
 * Canonical dashboard timeline instants and card clocks (bottom-right).
 *
 * Repository sort keys and view labels must both come from here so feed/email
 * UTC storage and Europe/Zurich display stay aligned.
 */
final class TimelineEntryDatetime
{
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

    public static function formatStoredUtcDatetime(?string $stored, string $format = 'd.m.Y H:i'): string
    {
        $dt = self::parseStoredUtcDatetime($stored);
        if ($dt === null) {
            return '';
        }

        return $dt->setTimezone(self::viewTimezone())->format($format);
    }

    public static function unixDateOnlyInViewTz(?string $dateOnly): int
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

        $ts = strtotime($dateOnly);
        if ($ts === false) {
            return 0;
        }

        return (new \DateTimeImmutable('@' . $ts))
            ->setTimezone($viewTz)
            ->setTime(0, 0)
            ->getTimestamp();
    }

    public static function formatDateOnlyLabel(?string $dateOnly): string
    {
        $unix = self::unixDateOnlyInViewTz($dateOnly);
        if ($unix <= 0) {
            return '';
        }

        return (new \DateTimeImmutable('@' . $unix))
            ->setTimezone(self::viewTimezone())
            ->format('d.m.Y');
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
    public static function formatFeedItemDatetime(array $row, string $format = 'd.m.Y H:i'): string
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
    public static function formatEmailDatetime(array $row, string $format = 'd.m.Y H:i'): string
    {
        $raw = self::emailStoredDatetime($row);

        return $raw !== null ? self::formatStoredUtcDatetime($raw, $format) : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function lexItemUnix(array $row): int
    {
        return self::unixDateOnlyInViewTz(isset($row['document_date']) ? (string)$row['document_date'] : null);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function formatLexItemDate(array $row): string
    {
        return self::formatDateOnlyLabel(isset($row['document_date']) ? (string)$row['document_date'] : null);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function calendarEventUnix(array $row): int
    {
        return self::unixDateOnlyInViewTz(isset($row['event_date']) ? (string)$row['event_date'] : null);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function formatCalendarEventDate(array $row): string
    {
        $eventDate = trim((string)($row['event_date'] ?? ''));
        if ($eventDate === '') {
            return '';
        }

        $label = self::formatDateOnlyLabel($eventDate);
        if ($label === '') {
            return '';
        }

        $viewTz = self::viewTimezone();
        $eventDay = \DateTimeImmutable::createFromFormat('Y-m-d', $eventDate, $viewTz);
        if ($eventDay === false) {
            return $label;
        }

        $today = new \DateTimeImmutable('today', $viewTz);
        $daysUntil = (int)$today->diff($eventDay)->format('%r%a');
        if ($daysUntil === 0) {
            $label .= ' (today)';
        } elseif ($daysUntil === 1) {
            $label .= ' (tomorrow)';
        } elseif ($daysUntil > 1 && $daysUntil <= 14) {
            $label .= ' (in ' . $daysUntil . 'd)';
        }

        return $label;
    }
}
