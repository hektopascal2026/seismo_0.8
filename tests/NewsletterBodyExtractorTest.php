<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Mail\EmailHtmlSanitizer;
use Seismo\Core\Mail\NewsletterBodyExtractor;

final class NewsletterBodyExtractorTest extends TestCase
{
    public function testEcowasFixtureExtractsHeadlineNotOnlyBoilerplate(): void
    {
        $html = file_get_contents(__DIR__ . '/fixtures/mail/ecowas_boilerplate.html');
        self::assertIsString($html);

        $text = NewsletterBodyExtractor::fromHtml($html);
        self::assertStringContainsString('Arrival in Abidjan', $text);
        self::assertStringContainsString('ECOWAS', $text);
        self::assertStringNotContainsString('requires a modern e-mail reader', $text);
    }

    public function testEftaMailchimpFixtureStripsTrackingAndKeepsCopy(): void
    {
        $html = file_get_contents(__DIR__ . '/fixtures/mail/efta_mailchimp.html');
        self::assertIsString($html);

        $text = NewsletterBodyExtractor::fromHtml($html);
        self::assertStringContainsString('EFTA Parliamentary Committee visits Viet Nam', $text);
        self::assertStringContainsString('Highlights', $text);
        self::assertStringContainsString('Significant headway made', $text);
        self::assertStringContainsString('full report', $text);
        self::assertStringNotContainsString('list-manage.com', $text);
        self::assertStringNotContainsString('mcusercontent.com', $text);
        self::assertStringNotContainsString('![](', $text);
    }

    public function testNewsServiceBundPreservesMultipleItems(): void
    {
        $html = file_get_contents(__DIR__ . '/fixtures/mail/news_service_bund.html');
        self::assertIsString($html);

        $text = NewsletterBodyExtractor::fromHtml($html);
        self::assertStringContainsString('First press item headline', $text);
        self::assertStringContainsString('Second press item headline', $text);
        self::assertStringNotContainsString('view this email in your browser', mb_strtolower($text));
    }

    public function testSanitizerDropsScriptContent(): void
    {
        $html = '<html><body><script>alert("x")</script><p>Safe text</p></body></html>';
        $safe = EmailHtmlSanitizer::sanitize($html);
        self::assertStringNotContainsString('alert', $safe);
        self::assertStringContainsString('Safe text', NewsletterBodyExtractor::fromHtml($html));
    }

    public function testEmptyHtmlReturnsEmpty(): void
    {
        self::assertSame('', NewsletterBodyExtractor::fromHtml(''));
    }
}
