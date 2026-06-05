<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Repository\EmailSubscriptionRepository;

final class EmailSubscriptionRepositoryTest extends TestCase
{
    public function testDomainMatchesSubaddress(): void
    {
        self::assertTrue(EmailSubscriptionRepository::matchesAddress(
            'alice@example.com',
            'domain',
            'example.com'
        ));
        self::assertTrue(EmailSubscriptionRepository::matchesAddress(
            'alice@example.com',
            'domain',
            '@example.com'
        ));
    }

    public function testDomainMatchesSubdomainHost(): void
    {
        self::assertTrue(EmailSubscriptionRepository::matchesAddress(
            'news@wirtschaftsnewsletter.blick.ch',
            'domain',
            'blick.ch'
        ));
        self::assertTrue(EmailSubscriptionRepository::matchesAddress(
            'list@mail.example.com',
            'domain',
            'example.com'
        ));
        self::assertTrue(EmailSubscriptionRepository::matchesAddress(
            'info@newsletter.example.com',
            'domain',
            'example.com'
        ));
    }

    public function testSqlDomainHostMatchUsesHostPartNotLooseSuffix(): void
    {
        $sql = EmailSubscriptionRepository::sqlDomainHostMatch('from_email');
        self::assertStringContainsString("SUBSTRING_INDEX(from_email, '@', -1)", $sql);
        self::assertStringNotContainsString("LIKE '%@'", $sql);
    }

    public function testDomainDoesNotMatchUnrelatedHostSharingSuffix(): void
    {
        self::assertFalse(EmailSubscriptionRepository::matchesAddress(
            'a@notblick.ch',
            'domain',
            'blick.ch'
        ));
    }

    public function testDomainDoesNotMatchOtherDomain(): void
    {
        self::assertFalse(EmailSubscriptionRepository::matchesAddress(
            'alice@other.com',
            'domain',
            'example.com'
        ));
    }

    public function testEmailExactMatchIsCaseInsensitive(): void
    {
        self::assertTrue(EmailSubscriptionRepository::matchesAddress(
            'Alice@Example.COM',
            'email',
            'alice@example.com'
        ));
    }

    public function testEmailDoesNotMatchPartial(): void
    {
        self::assertFalse(EmailSubscriptionRepository::matchesAddress(
            'alice@example.com',
            'email',
            'bob@example.com'
        ));
    }

    public function testResolveDisplayNamePrefersEmailRuleOverDomain(): void
    {
        $rows = [
            [
                'match_type'    => 'domain',
                'match_value'   => 'example.com',
                'display_name'  => 'Example domain',
                'disabled'      => 0,
            ],
            [
                'match_type'    => 'email',
                'match_value'   => 'alice@example.com',
                'display_name'  => 'Alice only',
                'disabled'      => 0,
            ],
        ];
        self::assertSame(
            'Alice only',
            EmailSubscriptionRepository::resolveDisplayNameForFromEmail('alice@example.com', $rows)
        );
    }

    public function testResolveDisplayNameSkipsDisabled(): void
    {
        $rows = [
            [
                'match_type'    => 'domain',
                'match_value'   => 'example.com',
                'display_name'  => 'Newsletter',
                'disabled'      => 1,
            ],
        ];
        self::assertNull(EmailSubscriptionRepository::resolveDisplayNameForFromEmail('a@example.com', $rows));
    }

    public function testProposeDisplayNamePrefersFromName(): void
    {
        self::assertSame(
            'News Service Bund',
            EmailSubscriptionRepository::proposeDisplayName('News Service Bund', 'news.admin.ch')
        );
    }

    public function testProposeDisplayNameFromDomainLabel(): void
    {
        self::assertSame(
            'Test',
            EmailSubscriptionRepository::proposeDisplayName('', 'test.ch')
        );
    }

    public function testResolveSubscriptionUiSkipsPendingAutoDetected(): void
    {
        $rows = [
            [
                'match_type'                 => 'domain',
                'match_value'                => 'example.com',
                'display_name'               => 'Should not apply',
                'disabled'                   => 0,
                'auto_detected'              => 1,
                'strip_listing_boilerplate'  => 1,
            ],
        ];
        $ui = EmailSubscriptionRepository::resolveSubscriptionUiForFromEmail('a@example.com', $rows);
        self::assertNull($ui['display_name']);
        self::assertFalse($ui['strip_listing_boilerplate']);
    }

    public function testResolveSubscriptionUiCarriesStripBoilerplateFromWinningRow(): void
    {
        $rows = [
            [
                'match_type'                 => 'domain',
                'match_value'                => 'news.admin.ch',
                'display_name'               => 'News Service Bund',
                'disabled'                   => 0,
                'strip_listing_boilerplate'  => 1,
            ],
        ];
        $ui = EmailSubscriptionRepository::resolveSubscriptionUiForFromEmail('no-reply@news.admin.ch', $rows);
        self::assertSame('News Service Bund', $ui['display_name']);
        self::assertTrue($ui['strip_listing_boilerplate']);
    }

    public function testSubjectBasedRouting(): void
    {
        $rows = [
            [
                'match_type'    => 'domain',
                'match_value'   => 'nzz.ch',
                'display_name'  => 'NZZ am Morgen',
                'subject_filter'=> 'am Morgen',
                'disabled'      => 0,
            ],
            [
                'match_type'    => 'domain',
                'match_value'   => 'nzz.ch',
                'display_name'  => 'NZZ Pro',
                'subject_filter'=> '/\bpro\b/i',
                'disabled'      => 0,
            ],
            [
                'match_type'    => 'domain',
                'match_value'   => 'nzz.ch',
                'display_name'  => 'NZZ General',
                'subject_filter'=> '',
                'disabled'      => 0,
            ],
        ];

        // 1. Matches "am Morgen"
        $match = EmailSubscriptionRepository::findBestMatchingSubscription('newsletter@nzz.ch', 'NZZ am Morgen: Today\'s news', $rows);
        self::assertNotNull($match);
        self::assertSame('NZZ am Morgen', $match['display_name']);

        // 2. Matches "Pro" via regex
        $match2 = EmailSubscriptionRepository::findBestMatchingSubscription('newsletter@nzz.ch', 'NZZ Pro: Industry updates', $rows);
        self::assertNotNull($match2);
        self::assertSame('NZZ Pro', $match2['display_name']);

        // 3. Falls back to "NZZ General" since it has no subject filter
        $match3 = EmailSubscriptionRepository::findBestMatchingSubscription('newsletter@nzz.ch', 'NZZ Feuilleton: Books of the week', $rows);
        self::assertNotNull($match3);
        self::assertSame('NZZ General', $match3['display_name']);
    }
}
