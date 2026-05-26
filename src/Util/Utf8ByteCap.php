<?php

declare(strict_types=1);

namespace Seismo\Util;

/**
 * Truncate UTF-8 text to a maximum byte length without splitting multibyte characters.
 */
final class Utf8ByteCap
{
    public static function truncate(string $text, int $maxBytes, string $suffix = ''): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        return mb_strcut($text, 0, $maxBytes, 'UTF-8') . $suffix;
    }
}
