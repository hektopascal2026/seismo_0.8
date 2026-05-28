<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Util\LenientJsonParser;

final class LenientJsonParserTest extends TestCase
{
    public function testParsesTrailingComma(): void
    {
        $obj = LenientJsonParser::parseObject('{"researcher_markdown": "Hi", "used_entry_keys": [],}');
        self::assertIsArray($obj);
        self::assertSame('Hi', $obj['researcher_markdown']);
    }

    public function testParsesMarkdownFence(): void
    {
        $raw = <<<'JSON'
```json
{"researcher_markdown": "# Title", "used_entry_keys": ["feed_item:1"]}
```
JSON;
        $obj = LenientJsonParser::parseObject($raw);
        self::assertIsArray($obj);
        self::assertSame('# Title', $obj['researcher_markdown']);
        self::assertSame(['feed_item:1'], $obj['used_entry_keys']);
    }

    public function testClosesTruncatedObject(): void
    {
        $obj = LenientJsonParser::parseObject('{"researcher_markdown": "partial", "used_entry_keys": ["feed_item:9"');
        self::assertIsArray($obj);
        self::assertSame('partial', $obj['researcher_markdown']);
    }
}
