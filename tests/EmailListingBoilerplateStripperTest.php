<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Mail\EmailListingBoilerplateStripper;

final class EmailListingBoilerplateStripperTest extends TestCase
{
    public function testDoesNotStripSubjectPrefixFromUnrelatedWord(): void
    {
        $body = 'Reported findings indicate a market shift.';

        $out = EmailListingBoilerplateStripper::strip($body, 'Report');

        self::assertSame($body, $out);
    }

    public function testStripsSubjectWhenFollowedByNewline(): void
    {
        $body = "Quarterly update\nRevenue grew 12% year over year.";

        $out = EmailListingBoilerplateStripper::strip($body, 'Quarterly update');

        self::assertSame('Revenue grew 12% year over year.', $out);
    }

    public function testStripsSubjectWhenFollowedByWindowsNewline(): void
    {
        $body = "Quarterly update\r\nRevenue grew 12% year over year.";

        $out = EmailListingBoilerplateStripper::strip($body, 'Quarterly update');

        self::assertSame('Revenue grew 12% year over year.', $out);
    }
}
