<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Repository\LexItemRepository;

final class LexItemContentUnavailableTest extends TestCase
{
    public function testIsContentUnavailableDetectsTombstone(): void
    {
        self::assertTrue(LexItemRepository::isContentUnavailable(LexItemRepository::CONTENT_FETCH_UNAVAILABLE));
        self::assertFalse(LexItemRepository::isContentUnavailable('Full legal text body.'));
        self::assertFalse(LexItemRepository::isContentUnavailable(null));
    }
}
