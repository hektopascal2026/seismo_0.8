<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

/**
 * Lightweight inbox-language guess for newsletter locale link / hydration policy.
 */
final class EmailLocaleGuesser
{
    public const PROFILE_GERMAN  = 'german';
    public const PROFILE_ENGLISH = 'english';
    public const PROFILE_OTHER   = 'other';

    public static function profileForEmail(string $subject, string $plain): string
    {
        $sample = mb_substr(trim($subject) . "\n" . trim($plain), 0, 6000, 'UTF-8');
        if ($sample === '') {
            return self::PROFILE_OTHER;
        }

        if (self::containsCyrillic($sample)) {
            return self::PROFILE_OTHER;
        }

        if (self::looksGerman($sample)) {
            return self::PROFILE_GERMAN;
        }

        if (self::looksEnglish($sample)) {
            return self::PROFILE_ENGLISH;
        }

        return self::PROFILE_OTHER;
    }

    private static function containsCyrillic(string $text): bool
    {
        return preg_match('/[\p{Cyrillic}]/u', $text) === 1;
    }

    private static function looksGerman(string $text): bool
    {
        if (preg_match('/[äöüß]/iu', $text) === 1) {
            return true;
        }

        $norm = EmailWebViewPhraseLexicon::normalizeForMatch($text);
        $hits = 0;
        foreach (
            [
                ' und ',
                ' der ',
                ' die ',
                ' das ',
                ' den ',
                ' dem ',
                ' des ',
                ' mit ',
                ' fur ',
                ' nicht ',
                ' werden ',
                ' newsletter ',
                ' mitteilung ',
                ' bundesrat ',
                ' medienmitteilung ',
            ] as $needle
        ) {
            if (str_contains($norm, $needle)) {
                ++$hits;
            }
        }

        return $hits >= 3;
    }

    private static function looksEnglish(string $text): bool
    {
        $norm = EmailWebViewPhraseLexicon::normalizeForMatch($text);
        $hits = 0;
        foreach (
            [
                ' the ',
                ' and ',
                ' of ',
                ' for ',
                ' with ',
                ' this ',
                ' that ',
                ' newsletter ',
                ' read more ',
                ' press release ',
            ] as $needle
        ) {
            if (str_contains($norm, $needle)) {
                ++$hits;
            }
        }

        return $hits >= 3;
    }
}
