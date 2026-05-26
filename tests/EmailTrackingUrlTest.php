<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Core\Mail\EmailHtmlSanitizer;
use Seismo\Core\Mail\EmailTrackingUrl;

final class EmailTrackingUrlTest extends TestCase
{
    public function testUtmParamsAreNotTreatedAsRedirectTracker(): void
    {
        $url = 'https://example.com/article?utm_source=newsletter&utm_medium=email';

        self::assertFalse(EmailTrackingUrl::isRedirectTrackingUrl($url));
        self::assertFalse(EmailTrackingUrl::isTrackingOrAsset($url));
    }

    public function testCleanNewsletterHrefStripsUtm(): void
    {
        $raw = 'https://www.Example.com/story?utm_source=nl#section';
        $clean = EmailTrackingUrl::cleanNewsletterHref($raw);

        self::assertSame('https://example.com/story', $clean);
    }

    public function testMailchimpRedirectIsStillTracker(): void
    {
        $url = 'https://example.us1.list-manage.com/track/click?u=1&id=2';

        self::assertTrue(EmailTrackingUrl::isRedirectTrackingUrl($url));
    }

    public function testSanitizerKeepsArticleLinkWithoutUtm(): void
    {
        $html = '<p>Read <a href="https://news.example.org/story?utm_campaign=digest">here</a>.</p>';
        $out  = EmailHtmlSanitizer::sanitize($html);

        self::assertStringContainsString('href="https://news.example.org/story"', $out);
        self::assertStringNotContainsString('utm_campaign', $out);
        self::assertStringContainsString('>here</a>', $out);
    }

    public function testSanitizerUnwrapsListManageRedirect(): void
    {
        $html = '<a href="https://x.us1.list-manage.com/track/click?id=1">Click</a>';
        $out  = EmailHtmlSanitizer::sanitize($html);

        self::assertStringNotContainsString('<a ', $out);
        self::assertStringContainsString('Click', $out);
        self::assertStringNotContainsString('list-manage.com', $out);
    }
}
