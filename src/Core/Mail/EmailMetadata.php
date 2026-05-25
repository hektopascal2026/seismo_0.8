<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

/**
 * Read/write JSON `emails.metadata` keys used by ingest and UI.
 */
final class EmailMetadata
{
    public const KEY_WEB_VIEW_URL    = 'web_view_url';
    public const KEY_WEB_VIEW_LOCALE = 'web_view_locale';
    public const KEY_BODY_SOURCE     = 'body_source';

    public const BODY_SOURCE_INBOX    = 'inbox';
    public const BODY_SOURCE_WEB_VIEW = 'web_view';

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function mergeWebViewUrl(array $row, ?string $url): array
    {
        $url = $url !== null ? trim($url) : '';
        if ($url === '') {
            return $row;
        }

        $meta = self::decode($row['metadata'] ?? null);
        $meta[self::KEY_WEB_VIEW_URL] = $url;
        $row['metadata'] = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $row;
    }

    public static function webViewUrlFromMetadata(mixed $metadata): ?string
    {
        $meta = self::decode($metadata);
        $url  = trim((string)($meta[self::KEY_WEB_VIEW_URL] ?? ''));

        return $url !== '' ? $url : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function bodySourceFromRow(array $row): string
    {
        $meta = self::decode($row['metadata'] ?? null);
        $src  = trim((string)($meta[self::KEY_BODY_SOURCE] ?? ''));

        return $src !== '' ? $src : self::BODY_SOURCE_INBOX;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function mergeWebViewHydration(array $row, string $url, int $localeRank): array
    {
        $row = self::mergeWebViewUrl($row, $url);
        $meta = self::decode($row['metadata'] ?? null);
        $meta[self::KEY_WEB_VIEW_LOCALE] = EmailAlternateLocalePolicy::localeKeyFromRank($localeRank);
        $meta[self::KEY_BODY_SOURCE]     = self::BODY_SOURCE_WEB_VIEW;
        $row['metadata'] = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }
        if (!is_string($metadata) || trim($metadata) === '') {
            return [];
        }
        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $decoded : [];
    }
}
