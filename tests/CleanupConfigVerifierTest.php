<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Mail\CleanupConfigVerifier;

final class CleanupConfigVerifierTest extends TestCase
{
    public function testVerifyDetectsRemovedNoiseAndKeptContent(): void
    {
        $original = "View in browser\nHeadline: Big Story\nThe parliament voted today.\nUnsubscribe here";
        $samples = [
            ['subject' => 'News', 'text_body' => $original, 'body' => $original],
        ];
        $config = [
            'strip_regexes' => [
                '/^View in browser.*$/imu',
                '/Unsubscribe here.*/imu',
            ],
            'webview_keywords' => ['View in browser'],
        ];
        $raw = [
            'analysis' => [
                'samples' => [
                    [
                        'sample_index' => 1,
                        'content' => [
                            ['text_snippet' => 'The parliament voted', 'must_keep' => true],
                        ],
                        'noise' => [
                            ['text_snippet' => 'View in browser', 'must_remove' => true],
                            ['text_snippet' => 'Unsubscribe here', 'must_remove' => true],
                        ],
                    ],
                ],
            ],
        ];

        $verifier = new CleanupConfigVerifier();
        $result = $verifier->verify($samples, $config, $raw);

        self::assertTrue($result['verified']);
    }

    public function testVerifyDetectsNoiseStillPresent(): void
    {
        $original = "View in browser\nArticle body text here.";
        $samples = [
            ['subject' => 'News', 'text_body' => $original, 'body' => $original],
        ];
        $config = [
            'strip_regexes' => ['/Article body.*/imu'],
        ];
        $raw = [
            'analysis' => [
                'samples' => [
                    [
                        'sample_index' => 1,
                        'noise' => [
                            ['text_snippet' => 'View in browser', 'must_remove' => true],
                        ],
                    ],
                ],
            ],
        ];

        $verifier = new CleanupConfigVerifier();
        $result = $verifier->verify($samples, $config, $raw);

        self::assertFalse($result['verified']);
        self::assertNotEmpty($result['issues']);
        self::assertSame('noise_still_present', $result['issues'][0]['type']);
    }
}
