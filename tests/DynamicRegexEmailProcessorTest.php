<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Mail\Processor\DynamicRegexEmailProcessor;
use Seismo\Core\Mail\EmailWebViewUrlExtractor;

final class DynamicRegexEmailProcessorTest extends TestCase
{
    public function testApplyStripRegexesAndTitleExtractor(): void
    {
        $config = [
            'strip_regexes' => [
                '/^Having trouble reading this email\?\s+View\s+online/ui',
                '/--- FOOTER ---.*/s'
            ],
            'webview_keywords' => [
                'View online'
            ],
            'title_extractor' => '/Headline:\s*(.+)/i'
        ];

        $processor = new DynamicRegexEmailProcessor($config);

        $row = [
            'subject' => 'Generic Newsletter',
            'text_body' => "Having trouble reading this email? View online\nHeadline: Custom Dynamic Headline\nThis is the valuable article text.\n--- FOOTER ---\nUnsubscribe links and copyright notices here."
        ];

        $processed = $processor->process($row);

        $this->assertArrayHasKey('derived_title', $processed);
        $this->assertEquals('Custom Dynamic Headline', $processed['derived_title']);

        $body = $processed['text_body'];
        $this->assertStringNotContainsString('Having trouble', $body);
        $this->assertStringNotContainsString('--- FOOTER ---', $body);
        $this->assertStringContainsString('This is the valuable', $body);
    }

    public function testWebViewExtractorWithCustomKeywords(): void
    {
        $html = '<div class="body"><p><a href="https://example.com/webview-link">Visualise online in web portal</a></p></div>';
        $plain = "If you cannot read this, open the Visualise online in web portal link";

        // Default resolve does not match "Visualise online in web portal" because it's not in the default lexicon
        $resDefault = EmailWebViewUrlExtractor::resolve($html, $plain, [0]);
        $this->assertNull($resDefault->url);

        // Resolve with custom keywords correctly extracts the webview url
        $resCustom = EmailWebViewUrlExtractor::resolve($html, $plain, [0], ['Visualise online in web portal']);
        $this->assertEquals('https://example.com/webview-link', $resCustom->url);
    }
}
