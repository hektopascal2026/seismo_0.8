<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

/**
 * Plain-text shaping for storage and UI (collapse table-layout newline runs).
 */
final class EmailBodyDisplay
{
    public static function collapseForStorage(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }
        $body = (string) preg_replace("/\r\n|\r/", "\n", $body);
        $body = (string) preg_replace("/\n{3,}/", "\n\n", $body);
        $lines = preg_split("/\n/", $body) ?: [];
        $out   = [];
        foreach ($lines as $line) {
            $t = trim(preg_replace('/\s+/u', ' ', (string)$line) ?? '');
            if ($t === '') {
                if ($out !== [] && end($out) !== '') {
                    $out[] = '';
                }
                continue;
            }
            $out[] = $t;
        }
        while ($out !== [] && $out[0] === '') {
            array_shift($out);
        }
        while ($out !== [] && end($out) === '') {
            array_pop($out);
        }

        return implode("\n", $out);
    }

    /**
     * Readable expanded card text: preserves paragraph breaks, removes huge blank runs.
     */
    public static function formatForDisplay(string $body): string
    {
        return self::collapseForStorage($body);
    }
}
