<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

/**
 * Read/write JSON `emails.metadata` keys used by ingest and UI.
 */
final class EmailMetadata
{
    public const KEY_WEB_VIEW_URL = 'web_view_url';

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
