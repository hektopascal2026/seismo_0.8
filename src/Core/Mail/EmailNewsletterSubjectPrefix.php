<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

/**
 * Propose a newsletter product label / subject_filter from an inbox subject line.
 */
final class EmailNewsletterSubjectPrefix
{
    private const MIN_LEN = 3;

    private const MAX_LEN = 80;

    /** @var list<string> */
    private const GENERIC_TOKENS = [
        'newsletter',
        'news',
        'weekly',
        'daily',
        'update',
        'updates',
        'digest',
        'edition',
        'bulletin',
        'mitteilung',
        'mitteilungen',
    ];

    public static function propose(?string $subject): ?string
    {
        $subject = trim((string)($subject ?? ''));
        if ($subject === '') {
            return null;
        }

        $subject = (string)preg_replace('/^(re|fwd|fw|aw|wg):\s*/iu', '', $subject);
        $subject = trim($subject);
        if ($subject === '') {
            return null;
        }

        foreach ([':', ' — ', ' – ', ' | ', ' - '] as $separator) {
            if (!str_contains($subject, $separator)) {
                continue;
            }
            $part = trim(explode($separator, $subject, 2)[0]);
            if (self::isValidProductLabel($part)) {
                return $part;
            }
        }

        if (self::isValidProductLabel($subject) && mb_strlen($subject) <= 60) {
            return $subject;
        }

        return null;
    }

    private static function isValidProductLabel(string $label): bool
    {
        $label = trim($label);
        if ($label === '') {
            return false;
        }
        $len = mb_strlen($label);
        if ($len < self::MIN_LEN || $len > self::MAX_LEN) {
            return false;
        }
        if (preg_match('/^\d+$/', $label)) {
            return false;
        }

        return !in_array(mb_strtolower($label), self::GENERIC_TOKENS, true);
    }
}
