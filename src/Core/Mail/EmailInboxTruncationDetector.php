<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

/**
 * Detect newsletter bodies that are intentionally shortened in the inbox (teaser / cliff).
 */
final class EmailInboxTruncationDetector
{
    public static function looksTruncated(string $plain): bool
    {
        $lower = EmailWebViewPhraseLexicon::normalizeForMatch($plain);
        if ($lower === '') {
            return false;
        }

        foreach (self::markers() as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string> Normalized substrings (after {@see EmailWebViewPhraseLexicon::normalizeForMatch()}).
     */
    private static function markers(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $raw = [
            'read on web not shown with this email',
            'read on web not shown',
            'read more on the web',
            'continue reading on the web',
            'read the full article on the web',
            'im web nicht enthalten',
            'vollstandige version im web',
            'vollstaendige version im web',
        ];
        $cache = [];
        foreach ($raw as $phrase) {
            $n = EmailWebViewPhraseLexicon::normalizeForMatch($phrase);
            if ($n !== '') {
                $cache[] = $n;
            }
        }

        return $cache;
    }
}
