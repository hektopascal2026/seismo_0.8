<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Repository\EmailIngestRepository;

final class EmailIngestTruncateTest extends TestCase
{
    public function testTruncateDoesNotSplitUtf8MultibyteCharacter(): void
    {
        $repo   = new EmailIngestRepository(new PDO('sqlite::memory:'));
        $method = new ReflectionMethod(EmailIngestRepository::class, 'truncate');
        $method->setAccessible(true);

        $emoji = '🙂';
        $s     = str_repeat('a', 498) . $emoji;
        $out   = $method->invoke($repo, $s, 500);

        self::assertIsString($out);
        self::assertLessThanOrEqual(500, strlen($out));
        self::assertTrue(mb_check_encoding($out, 'UTF-8'));
        self::assertStringEndsWith('a', $out);
        self::assertStringNotContainsString('🙂', $out);
    }
}
