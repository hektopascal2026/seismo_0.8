<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Mail\EmailListingBoilerplatePolicy;

final class EmailListingBoilerplatePolicyTest extends TestCase
{
    public function testSubscriptionFlagEnablesStrip(): void
    {
        self::assertTrue(EmailListingBoilerplatePolicy::shouldStrip(
            ['strip_listing_boilerplate' => true],
            false
        ));
    }

    public function testGlobalDefaultEnablesStripWithoutSubscription(): void
    {
        self::assertTrue(EmailListingBoilerplatePolicy::shouldStrip(
            ['strip_listing_boilerplate' => false],
            true
        ));
    }

    public function testNeitherFlagDisablesStrip(): void
    {
        self::assertFalse(EmailListingBoilerplatePolicy::shouldStrip(
            ['strip_listing_boilerplate' => false],
            false
        ));
    }
}
