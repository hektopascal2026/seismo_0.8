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
}
