<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Mail\EmailDigestSplitterService;

final class EmailDigestSplitterServiceTest extends TestCase
{
    public function testSplitHtmlSelector(): void
    {
        $html = '
            <html>
                <body>
                    <div class="article">
                        <h2>Story A</h2>
                        <a href="https://example.com/a">Read A</a>
                        <div class="content">Content of A</div>
                    </div>
                    <div class="article">
                        <h2>Story B</h2>
                        <a href="https://example.com/b">Read B</a>
                        <div class="content">Content of B</div>
                    </div>
                </body>
            </html>
        ';

        $config = [
            'split_rules' => [
                'split_method' => 'html_selector',
                'story_selector' => '.article',
                'title_selector' => 'h2',
                'link_selector' => 'a',
                'body_selector' => '.content',
            ]
        ];

        $service = new EmailDigestSplitterService();
        $stories = $service->split($html, '', $config);

        self::assertCount(2, $stories);
        self::assertSame('Story A', $stories[0]['title']);
        self::assertSame('Content of A', $stories[0]['text_body']);
        self::assertSame('https://example.com/a', $stories[0]['link']);

        self::assertSame('Story B', $stories[1]['title']);
        self::assertSame('Content of B', $stories[1]['text_body']);
        self::assertSame('https://example.com/b', $stories[1]['link']);
    }

    public function testSplitRegex(): void
    {
        $text = "
            --- STORY 1 ---
            Title: First Article
            Link: https://example.com/1
            Body: Body 1
            --- STORY 2 ---
            Title: Second Article
            Link: https://example.com/2
            Body: Body 2
        ";

        $config = [
            'split_rules' => [
                'split_method' => 'regex_split',
                'split_pattern' => '/---\s*STORY\s+\d+\s*---/i',
                'title_pattern' => '/Title:\s*(.+)/i',
                'link_pattern' => '/Link:\s*(.+)/i',
            ]
        ];

        $service = new EmailDigestSplitterService();
        $stories = $service->split('', $text, $config);

        // Splitting on STORY header splits into:
        // Index 0: empty space before the first STORY separator
        // Index 1: First Article
        // Index 2: Second Article
        self::assertCount(2, $stories);
        self::assertSame('First Article', $stories[0]['title']);
        self::assertSame('https://example.com/1', $stories[0]['link']);

        self::assertSame('Second Article', $stories[1]['title']);
        self::assertSame('https://example.com/2', $stories[1]['link']);
    }

    public function testExcludeSelectorsSkipNoiseNodes(): void
    {
        $html = '
            <html><body>
                <div class="article"><h2>Story A</h2><div class="content">A</div></div>
                <div class="article masthead"><h2>Newsletter Header</h2></div>
                <div class="article"><h2>Story B</h2><div class="content">B</div></div>
            </body></html>
        ';

        $config = [
            'split_rules' => [
                'split_method' => 'html_selector',
                'story_selector' => '.article',
                'title_selector' => 'h2',
                'body_selector' => '.content',
                'exclude_selectors' => ['.masthead'],
            ],
        ];

        $service = new EmailDigestSplitterService();
        $stories = $service->split($html, '', $config);

        self::assertCount(2, $stories);
        self::assertSame('Story A', $stories[0]['title']);
        self::assertSame('Story B', $stories[1]['title']);
    }

    public function testSplitAttributeSelector(): void
    {
        $html = '
            <html><body>
                <div class="item">
                    <a style="font-size:14px;font-weight:bold;" href="https://example.com/one">First headline here</a>
                    <p style="color:#262626;font-size:12px;">First body text with enough words to qualify.</p>
                </div>
            </body></html>
        ';

        $config = [
            'split_rules' => [
                'split_method' => 'html_selector',
                'story_selector' => 'div.item',
                'title_selector' => 'a[style*="font-weight:bold"]',
                'link_selector' => 'a[style*="font-weight:bold"]',
                'body_selector' => 'p[style*="color:#262626"]',
            ],
        ];

        $service = new EmailDigestSplitterService();
        $stories = $service->split($html, '', $config);

        self::assertCount(1, $stories);
        self::assertSame('First headline here', $stories[0]['title']);
        self::assertStringContainsString('First body text', $stories[0]['text_body']);
    }

    public function testMergesPunkt4HeadlineAndBodyCells(): void
    {
        $html = '
            <html><body>
            <table><tbody><tr><td>
            <table><tbody>
            <tr><td><a style="font-weight:bold" href="https://example.com/ai">Künstliche Intelligenz verändert den Welthandel grundlegend</a></td></tr>
            <tr><td style="font-size:12px">Wallisellen ZH - Laut einer Studie von Allianz Trade verschiebt das globale Wachstum der Künstlichen Intelligenz die Machtverhältnisse im Welthandel. <a href="https://example.com/ai">Mehr</a></td></tr>
            </tbody></table>
            </td></tr></tbody></table>
            </body></html>
        ';

        $config = [
            'split_rules' => [
                'split_method' => 'html_selector',
                'story_selector' => 'table table table table td',
                'title_selector' => 'a',
                'link_selector' => 'a',
                'body_selector' => 'td',
            ],
        ];

        $service = new EmailDigestSplitterService();
        $stories = $service->split($html, '', $config);

        self::assertCount(1, $stories);
        self::assertStringContainsString('Künstliche Intelligenz', $stories[0]['title']);
        self::assertStringContainsString('Allianz Trade', $stories[0]['text_body']);
        self::assertSame('https://example.com/ai', $stories[0]['link']);
        self::assertStringNotContainsString('Mehr', $stories[0]['title']);
    }

    public function testSplitTypo3Punkt4Template(): void
    {
        $html = file_get_contents(__DIR__ . '/fixtures/zhk_digest_sample.html');
        self::assertNotFalse($html);

        $config = [
            'split_rules' => [
                'split_method' => 'html_selector',
                'story_selector' => 'div.csc-frame-default, table table table table td',
                'title_selector' => 'h1.csc-firstHeader, a',
                'link_selector' => 'a',
                'body_selector' => 'p.bodytext, td',
            ],
        ];

        $service = new EmailDigestSplitterService();
        $stories = $service->split($html, '', $config);

        self::assertCount(3, $stories);
        self::assertStringContainsString('AMAG Group', $stories[0]['title']);
        self::assertStringContainsString('Zürich Tourismus', $stories[1]['title']);
        self::assertStringContainsString('SMC Launch Event', $stories[2]['title']);
    }
}
