<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Mail\EmailNewsletterSubjectPrefix;

final class EmailNewsletterSubjectPrefixTest extends TestCase
{
    public function testProposesPrefixBeforeColon(): void
    {
        self::assertSame(
            'Politpuls',
            EmailNewsletterSubjectPrefix::propose('Politpuls: Woche 12')
        );
    }

    public function testProposesPrefixBeforeEmDash(): void
    {
        self::assertSame(
            'Stimme der Wirtschaft',
            EmailNewsletterSubjectPrefix::propose('Stimme der Wirtschaft — Juni 2026')
        );
    }

    public function testStripsReplyPrefix(): void
    {
        self::assertSame(
            'Politpuls',
            EmailNewsletterSubjectPrefix::propose('Re: Politpuls: Update')
        );
    }

    public function testRejectsGenericWholeSubject(): void
    {
        self::assertNull(EmailNewsletterSubjectPrefix::propose('Newsletter'));
    }
}
