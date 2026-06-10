<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Service\Seismogramm\Pipeline\SelectionResponseParser;

final class SeismogrammSelectionResponseParserTest extends TestCase
{
    public function testParsesUsedEntryKeysFromJsonObject(): void
    {
        $parser = new SelectionResponseParser();
        $entries = [
            ['entry_type' => 'feed_item', 'entry_id' => '42'],
            ['entry_type' => 'lex_item', 'entry_id' => '7'],
        ];

        $raw = '{"used_entry_keys":["feed_item:42","lex_item:7"]}';
        $keys = $parser->parseSelectionResponse($raw, $entries, 5);

        self::assertSame(['feed_item:42', 'lex_item:7'], $keys);
    }

    public function testNormalizesKeysToLowercase(): void
    {
        $parser = new SelectionResponseParser();
        $entries = [
            ['entry_type' => 'feed_item', 'entry_id' => '42'],
        ];

        $raw = '{"used_entry_keys":["Feed_Item:42"]}';
        $keys = $parser->parseSelectionResponse($raw, $entries, 5);

        self::assertSame(['feed_item:42'], $keys);
    }
}
