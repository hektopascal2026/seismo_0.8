<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

/**
 * Which labelled newsletter locale links to prefer, and when to fetch hosted HTML into {@see EmailWebViewBodyHydrator}.
 */
final class EmailAlternateLocalePolicy
{
    /**
     * German inbox → German web edition first; non-English inbox → German then English; English inbox → English then German.
     *
     * @return list<int> {@see EmailWebViewPhraseLexicon::RANK_LOCALE_*} ascending priority
     */
    public static function preferredLocaleRanks(string $profile): array
    {
        $tail = [
            EmailWebViewPhraseLexicon::RANK_LOCALE_FRENCH,
            EmailWebViewPhraseLexicon::RANK_LOCALE_SPANISH,
            EmailWebViewPhraseLexicon::RANK_LOCALE_ITALIAN,
            EmailWebViewPhraseLexicon::RANK_LOCALE_DUTCH,
            EmailWebViewPhraseLexicon::RANK_LOCALE_OTHER,
        ];

        return match ($profile) {
            EmailLocaleGuesser::PROFILE_GERMAN => array_merge(
                [EmailWebViewPhraseLexicon::RANK_LOCALE_GERMAN, EmailWebViewPhraseLexicon::RANK_LOCALE_ENGLISH],
                $tail
            ),
            EmailLocaleGuesser::PROFILE_ENGLISH => array_merge(
                [EmailWebViewPhraseLexicon::RANK_LOCALE_ENGLISH, EmailWebViewPhraseLexicon::RANK_LOCALE_GERMAN],
                $tail
            ),
            default => array_merge(
                [EmailWebViewPhraseLexicon::RANK_LOCALE_GERMAN, EmailWebViewPhraseLexicon::RANK_LOCALE_ENGLISH],
                $tail
            ),
        };
    }

    /** @return list<int> */
    public static function englishFirstRanks(): array
    {
        return self::preferredLocaleRanks(EmailLocaleGuesser::PROFILE_ENGLISH);
    }

    public static function shouldHydrateBodyFromWebView(?int $localeRank): bool
    {
        return $localeRank === EmailWebViewPhraseLexicon::RANK_LOCALE_ENGLISH
            || $localeRank === EmailWebViewPhraseLexicon::RANK_LOCALE_GERMAN;
    }

    public static function localeKeyFromRank(int $rank): string
    {
        return match ($rank) {
            EmailWebViewPhraseLexicon::RANK_LOCALE_GERMAN  => 'de',
            EmailWebViewPhraseLexicon::RANK_LOCALE_ENGLISH => 'en',
            EmailWebViewPhraseLexicon::RANK_LOCALE_FRENCH  => 'fr',
            EmailWebViewPhraseLexicon::RANK_LOCALE_SPANISH => 'es',
            EmailWebViewPhraseLexicon::RANK_LOCALE_ITALIAN => 'it',
            EmailWebViewPhraseLexicon::RANK_LOCALE_DUTCH   => 'nl',
            default => 'other',
        };
    }
}
