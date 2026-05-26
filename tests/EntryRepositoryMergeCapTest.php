<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Repository\EntryRepository;

final class EntryRepositoryMergeCapTest extends TestCase
{
    public function testMergePerSourceFetchCapGrowsWithOffset(): void
    {
        $repo   = new EntryRepository(new PDO('sqlite::memory:'));
        $method = new ReflectionMethod(EntryRepository::class, 'mergePerSourceFetchCap');
        $method->setAccessible(true);

        self::assertSame(200, $method->invoke($repo, 30, 0));
        self::assertSame(240, $method->invoke($repo, 30, 210));
        self::assertSame(
            EntryRepository::MERGE_PER_SOURCE_CAP,
            $method->invoke($repo, 200, EntryRepository::MERGE_PER_SOURCE_CAP),
        );
    }
}
