<?php

declare(strict_types=1);

namespace Seismo\Core;

/**
 * Plain-text cleanup at ingest: horizontal whitespace and at most one consecutive blank line.
 */
final class PlainTextNormalizer
{
    public static function forIngest(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $text = str_replace("\xc2\xa0", ' ', $text);
        $text = preg_replace("/\r\n|\r/", "\n", $text) ?? $text;
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = self::collapseConsecutiveBlankLines($text);

        return trim($text);
    }

    /**
     * Whitespace-only lines count as blank; two or more blank lines in a row become one.
     */
    public static function collapseConsecutiveBlankLines(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $lines   = explode("\n", $text);
        $out     = [];
        $hadBlank = false;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                if (!$hadBlank) {
                    $out[] = '';
                    $hadBlank = true;
                }
                continue;
            }

            $out[]    = rtrim($line);
            $hadBlank = false;
        }

        return implode("\n", $out);
    }
}
