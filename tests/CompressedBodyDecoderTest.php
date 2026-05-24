<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Service\Http\CompressedBodyDecoder;

final class CompressedBodyDecoderTest extends TestCase
{
    public function testDecodesGzipByMagicBytes(): void
    {
        $plain = '<?xml version="1.0"?><rss version="2.0"><channel></channel></rss>';
        $gzip  = gzencode($plain);

        self::assertSame($plain, CompressedBodyDecoder::decode($gzip));
    }

    public function testDecodesGzipWhenHeaderSaysGzip(): void
    {
        $plain = '<feed></feed>';
        $gzip  = gzencode($plain);

        self::assertSame(
            $plain,
            CompressedBodyDecoder::decode($gzip, ['content-encoding' => 'gzip'])
        );
    }

    public function testLeavesPlainXmlUntouched(): void
    {
        $plain = '<rss><channel></channel></rss>';

        self::assertSame($plain, CompressedBodyDecoder::decode($plain));
    }
}
