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

    public function testStripsOutlookCidMarkers(): void
    {
        $body = '[cid:image001.png@01DC5EBE.09143A30][cid:image002.jpg@01DC230A.302CF4D0]' . "\n"
            . 'Policy update on energy markets.';

        $out = EmailListingBoilerplateStripper::strip($body, null);

        self::assertSame('Policy update on energy markets.', $out);
    }

    public function testStripsBmwkSchlaglichterShellAndDuplicateSubject(): void
    {
        $subject = 'Schlaglichter der Wirtschaftspolitik';
        $body = $subject . "\n"
            . '[cid:image001.png@01DC5EBE.09143A30][cid:image002.jpg@01DC230A.302CF4D0]' . "\n"
            . 'Ausgabe Juni 2026 (Dokument ist nicht barrierefrei)' . "\n"
            . 'Download (PDF, 2 MB) (https://bundeswirtschaftsministerium.de/example.pdf)' . "\n"
            . $subject . "\n"
            . 'Die deutsche Wirtschaft bleibt auf Wachstumskurs.';

        $out = EmailListingBoilerplateStripper::strip($body, $subject);

        self::assertSame('Die deutsche Wirtschaft bleibt auf Wachstumskurs.', $out);
    }
}
