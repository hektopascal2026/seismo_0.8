<?php

declare(strict_types=1);

namespace Seismo\Service\Http;

/**
 * Decompress HTTP response bodies when the transport did not (streams fallback)
 * or when a feed endpoint returns gzip without the client negotiating decode.
 */
final class CompressedBodyDecoder
{
    /**
     * @param array<string, string> $headers Lower-cased response header names.
     */
    public static function decode(string $body, array $headers = []): string
    {
        if ($body === '') {
            return $body;
        }

        $encoding = strtolower($headers['content-encoding'] ?? '');

        if (str_contains($encoding, 'gzip') || str_starts_with($body, "\x1f\x8b")) {
            $decoded = @gzdecode($body);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        if (str_contains($encoding, 'deflate')) {
            // Raw deflate (zlib wrapper optional).
            $decoded = @gzinflate($body);
            if ($decoded !== false) {
                return $decoded;
            }
            $decoded = @gzuncompress($body);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $body;
    }
}
