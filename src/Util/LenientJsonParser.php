<?php

declare(strict_types=1);

namespace Seismo\Util;

/**
 * Multi-layer lenient JSON object parser for LLM output.
 *
 * Ported from tourdesuisse v3/gemini.py (parse_json_lenient) — same repair
 * stages without the Python json-repair dependency.
 */
final class LenientJsonParser
{
    /**
     * @return array<string, mixed>|null Root object, or null if nothing could be parsed.
     */
    public static function parseObject(string $text): ?array
    {
        $base = self::extractMarkdownJson($text);

        $candidates = [
            $base,
            self::extractBalancedJsonObject($base),
            self::removeTrailingCommas($base),
            self::insertMissingCommas($base),
        ];

        $balanced = self::extractBalancedJsonObject($base);
        $candidates[] = self::removeTrailingCommas($balanced);
        $candidates[] = self::insertMissingCommas($balanced);
        $candidates[] = self::insertMissingCommas(self::extractBalancedJsonObject($base));

        $seen = [];
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '' || isset($seen[$candidate])) {
                continue;
            }
            $seen[$candidate] = true;

            try {
                $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (\JsonException) {
                continue;
            }
        }

        return null;
    }

    public static function extractMarkdownJson(string $text): string
    {
        $stripped = trim($text);
        if (str_starts_with($stripped, '```')) {
            $stripped = (string)preg_replace('/^```(?:json)?\s*/i', '', $stripped);
            $stripped = (string)preg_replace('/\s*```$/', '', $stripped);
        }

        $start = strpos($stripped, '{');
        $end   = strrpos($stripped, '}');
        if ($start !== false && $end !== false && $end > $start) {
            return substr($stripped, $start, $end - $start + 1);
        }

        return $stripped;
    }

    public static function removeTrailingCommas(string $text): string
    {
        return (string)preg_replace('/,\s*([}\]])/', '$1', $text);
    }

    public static function insertMissingCommas(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $repaired = (string)preg_replace('/}\s*{/', '},{', $text);
        $repaired = (string)preg_replace('/]\s*\[/', '],[', $repaired);

        $lines = preg_split("/\r\n|\n|\r/", $repaired);
        if ($lines === false || count($lines) < 2) {
            return $repaired;
        }

        $out = [];
        $last = count($lines) - 1;
        foreach ($lines as $idx => $line) {
            $out[] = $line;
            if ($idx >= $last) {
                continue;
            }

            $curr = rtrim($line);
            $nxt  = ltrim($lines[$idx + 1]);
            $currS = trim($curr);
            if ($currS === '' || $nxt === '') {
                continue;
            }
            if (str_ends_with($currS, ',')) {
                continue;
            }
            if (str_starts_with($nxt, '}') || str_starts_with($nxt, ']')) {
                continue;
            }
            if (str_ends_with($currS, '{') || str_ends_with($currS, '[') || str_ends_with($currS, ':')) {
                continue;
            }
            if (str_contains($currS, ':') && str_starts_with($nxt, '"')) {
                $out[$idx] = $curr . ',';
                continue;
            }
            if (
                (str_ends_with($currS, '}') || str_ends_with($currS, ']') || str_ends_with($currS, '"'))
                && (str_starts_with($nxt, '{') || str_starts_with($nxt, '[') || str_starts_with($nxt, '"'))
            ) {
                $out[$idx] = $curr . ',';
            }
        }

        return implode("\n", $out);
    }

    public static function extractBalancedJsonObject(string $text): string
    {
        $s = trim($text);
        $start = strpos($s, '{');
        if ($start === false) {
            return $s;
        }

        /** @var list<string> $stack */
        $stack     = [];
        $inString  = false;
        $escaped   = false;
        $len       = strlen($s);

        for ($i = $start; $i < $len; $i++) {
            $ch = $s[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($ch === '\\') {
                    $escaped = true;
                } elseif ($ch === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($ch === '"') {
                $inString = true;
            } elseif ($ch === '{' || $ch === '[') {
                $stack[] = $ch;
            } elseif ($ch === '}') {
                if ($stack !== [] && $stack[count($stack) - 1] === '{') {
                    array_pop($stack);
                }
                if ($stack === []) {
                    return substr($s, $start, $i - $start + 1);
                }
            } elseif ($ch === ']') {
                if ($stack !== [] && $stack[count($stack) - 1] === '[') {
                    array_pop($stack);
                }
            }
        }

        $closing = '';
        for ($j = count($stack) - 1; $j >= 0; $j--) {
            $closing .= $stack[$j] === '[' ? ']' : '}';
        }

        return substr($s, $start) . $closing;
    }
}
