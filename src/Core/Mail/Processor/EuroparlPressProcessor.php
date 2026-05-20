<?php

declare(strict_types=1);

namespace Seismo\Core\Mail\Processor;

use Seismo\Core\Mail\EmailBodyDisplay;
use Seismo\Core\Mail\EmailBodyProcessorInterface;

/**
 * European Parliament "EP TODAY" plenary digests (ep.europa.eu).
 */
final class EuroparlPressProcessor implements EmailBodyProcessorInterface
{
    public const KEY = 'europarl_press';

    public function process(array $row): array
    {
        $subject = trim((string)($row['subject'] ?? ''));
        $body    = trim((string)($row['text_body'] ?? $row['body_text'] ?? ''));
        if ($body === '') {
            return $row;
        }

        $body = self::stripInlineMarkers($body);
        $lines = self::lines($body);
        $lines = self::dropNoiseLines($lines, $subject);
        $headline = self::pickHeadline($lines, $subject);
        if ($headline !== null) {
            $row['derived_title'] = self::truncate($headline, 500);
            $lines = self::trimLinesBeforeHeadline($lines, $headline);
        }
        $body = EmailBodyDisplay::collapseForStorage(implode("\n", $lines));
        if ($body !== '') {
            $row['text_body'] = $body;
            $row['body_text'] = $body;
        }

        return $row;
    }

    private static function stripInlineMarkers(string $body): string
    {
        $body = (string) preg_replace('/\[\d+\]/u', '', $body);
        $body = (string) preg_replace('/\s{2,}/u', ' ', $body);

        return trim($body);
    }

    /**
     * @return list<string>
     */
    private static function lines(string $body): array
    {
        $parts = preg_split("/\r\n|\n|\r/", $body) ?: [];
        $out   = [];
        foreach ($parts as $line) {
            $t = trim(preg_replace('/\s+/u', ' ', (string)$line) ?? '');
            if ($t !== '') {
                $out[] = $t;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function dropNoiseLines(array $lines, string $subject): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (self::isNoiseLine($line, $subject)) {
                continue;
            }
            $out[] = $line;
        }

        return $out;
    }

    private static function isNoiseLine(string $line, string $subject): bool
    {
        $lower = mb_strtolower($line, 'UTF-8');
        if (str_contains($lower, 'scribo-webmail')) {
            return true;
        }
        if ($subject !== '' && $line === $subject) {
            return true;
        }
        if (preg_match('/^ep today\b/i', $line) === 1) {
            return true;
        }
        if (preg_match('/^(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\s+\d{1,2}\s+\w+/i', $line) === 1) {
            return true;
        }
        if (preg_match('/^\d{1,2}-\d{1,2}-\d{2,4}\b/', $line) === 1) {
            return true;
        }
        if (preg_match('/^press service\b/i', $lower) === 1) {
            return true;
        }
        if (preg_match('/^european parliament\b/i', $lower) === 1) {
            return true;
        }
        if (preg_match('/^plenary session\b/i', $lower) === 1 && mb_strlen($line, 'UTF-8') < 80) {
            return true;
        }
        if (preg_match('/^view (this )?e?-?mail in (your )?browser/i', $lower) === 1) {
            return true;
        }

        return mb_strlen($line, 'UTF-8') < 4;
    }

    /**
     * @param list<string> $lines
     */
    private static function pickHeadline(array $lines, string $subject): ?string
    {
        foreach ($lines as $line) {
            if (self::isNoiseLine($line, $subject)) {
                continue;
            }
            if (self::looksLikeHeadline($line, $subject)) {
                return $line;
            }
        }

        return null;
    }

    private static function looksLikeHeadline(string $line, string $subject): bool
    {
        if (self::isNoiseLine($line, $subject)) {
            return false;
        }
        $len = mb_strlen($line, 'UTF-8');
        if ($len < 18) {
            return false;
        }
        if (str_ends_with($line, '*') || str_ends_with($line, '…')) {
            return true;
        }
        if (preg_match('/\b(debate|session|vote|adopt|agreement|market|parliament)\b/i', $line) === 1) {
            return true;
        }

        return $len >= 40;
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function trimLinesBeforeHeadline(array $lines, string $headline): array
    {
        $found = false;
        $out   = [];
        foreach ($lines as $line) {
            if (!$found && $line === $headline) {
                $found = true;
            }
            if ($found) {
                $out[] = $line;
            }
        }

        return $out !== [] ? $out : $lines;
    }

    private static function truncate(string $value, int $max): string
    {
        if (mb_strlen($value, 'UTF-8') <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max, 'UTF-8');
    }
}
