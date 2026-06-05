<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Mail\DigestSplitVerifier;

final class DigestSplitVerifierTest extends TestCase
{
    public function testVerifySplitConfigDetectsMatchingCounts(): void
    {
        $html = '
            <html><body>
                <div class="article"><h2>Story A</h2><a href="https://example.com/a">Read</a><div class="content">A</div></div>
                <div class="article"><h2>Story B</h2><a href="https://example.com/b">Read</a><div class="content">B</div></div>
            </body></html>
        ';

        $samples = [
            [
                'subject' => 'Digest',
                'html_body' => $html,
                'text_body' => 'Story A Story B',
            ],
        ];

        $config = [
            'is_digest' => true,
            'split_rules' => [
                'split_method' => 'html_selector',
                'story_selector' => '.article',
                'title_selector' => 'h2',
                'link_selector' => 'a',
                'body_selector' => '.content',
            ],
        ];

        $raw = [
            'analysis' => [
                'samples' => [
                    ['sample_index' => 1, 'expected_card_count' => 2],
                ],
            ],
        ];

        $verifier = new DigestSplitVerifier();
        $result = $verifier->verify($samples, $config, $raw);

        self::assertTrue($result['verified']);
        self::assertSame([2], $result['expected_counts']);
        self::assertSame([2], $result['actual_counts']);
    }

    public function testVerifySplitConfigDetectsMismatch(): void
    {
        $html = '<html><body><div class="article"><h2>Only One</h2></div></body></html>';
        $samples = [
            ['subject' => 'Digest', 'html_body' => $html, 'text_body' => 'Only One'],
        ];
        $config = [
            'is_digest' => true,
            'split_rules' => [
                'split_method' => 'html_selector',
                'story_selector' => '.article',
                'title_selector' => 'h2',
            ],
        ];
        $raw = [
            'analysis' => [
                'samples' => [
                    ['sample_index' => 1, 'expected_card_count' => 3],
                ],
            ],
        ];

        $verifier = new DigestSplitVerifier();
        $result = $verifier->verify($samples, $config, $raw);

        self::assertFalse($result['verified']);
        self::assertSame([3], $result['expected_counts']);
        self::assertSame([1], $result['actual_counts']);
        self::assertCount(1, $result['mismatches']);
    }
}
