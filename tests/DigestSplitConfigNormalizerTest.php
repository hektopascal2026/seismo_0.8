<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Mail\DigestSplitConfigNormalizer;

final class DigestSplitConfigNormalizerTest extends TestCase
{
    public function testNormalizesCanonicalHtmlConfig(): void
    {
        $raw = [
            'is_digest' => true,
            'split_rules' => [
                'split_method' => 'html_selector',
                'story_selector' => '.article',
                'title_selector' => 'h2',
                'link_selector' => 'a',
                'body_selector' => '.content',
            ],
        ];

        $normalized = DigestSplitConfigNormalizer::normalize($raw);

        self::assertNotNull($normalized);
        self::assertTrue($normalized['is_digest']);
        self::assertSame('html_selector', $normalized['split_rules']['split_method']);
        self::assertSame('.article', $normalized['split_rules']['story_selector']);
    }

    public function testNormalizesLegacyHtmlCssKeys(): void
    {
        $raw = [
            'type' => 'html_css',
            'selector_story' => 'tr.story',
            'selector_title' => 'h2 a',
            'selector_link' => 'h2 a',
            'selector_body' => 'td.body',
        ];

        $normalized = DigestSplitConfigNormalizer::normalize($raw);

        self::assertNotNull($normalized);
        self::assertSame('tr.story', $normalized['split_rules']['story_selector']);
        self::assertSame('h2 a', $normalized['split_rules']['title_selector']);
    }

    public function testNormalizesLegacyRegexKeys(): void
    {
        $raw = [
            'is_digest' => true,
            'type' => 'regex',
            'pattern_split' => '/---/i',
            'pattern_title' => '/Title:\s*(.+)/i',
        ];

        $normalized = DigestSplitConfigNormalizer::normalize($raw);

        self::assertNotNull($normalized);
        self::assertSame('regex_split', $normalized['split_rules']['split_method']);
        self::assertSame('/---/i', $normalized['split_rules']['split_pattern']);
    }

    public function testReturnsNullWithoutStorySelector(): void
    {
        self::assertNull(DigestSplitConfigNormalizer::normalize([
            'is_digest' => true,
            'split_rules' => ['split_method' => 'html_selector'],
        ]));
    }

    public function testRejectsInvalidRegexPattern(): void
    {
        self::assertNull(DigestSplitConfigNormalizer::normalize([
            'is_digest' => true,
            'split_rules' => [
                'split_method' => 'regex_split',
                'split_pattern' => '/(?=[unclosed',
            ],
        ]));
    }

    public function testExpectedCountsFromAnalysis(): void
    {
        $counts = DigestSplitConfigNormalizer::expectedCountsFromAnalysis([
            'analysis' => [
                'samples' => [
                    ['sample_index' => 1, 'expected_card_count' => 7],
                    ['sample_index' => 2, 'expected_card_count' => 6],
                ],
            ],
        ]);

        self::assertSame([7, 6], $counts);
    }
}
