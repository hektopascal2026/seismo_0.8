<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Service\GeminiBriefingException;

final class GeminiBriefingExceptionTest extends TestCase
{
    public function testRateLimitExceptionIsDetectable(): void
    {
        $e = GeminiBriefingException::fromHttpStatus(429);

        self::assertTrue($e->isRateLimitExceeded());
        self::assertSame(429, $e->httpStatus);
    }

    public function testTransportFailureIsNotRateLimit(): void
    {
        $e = GeminiBriefingException::transportFailed();

        self::assertFalse($e->isRateLimitExceeded());
    }

    public function testOutputTruncatedRequestsBatchedRetry(): void
    {
        $e = GeminiBriefingException::outputTruncated();

        self::assertTrue($e->shouldRetryWithBatchedSummary());
        self::assertTrue($e->isOutputTruncated());
    }

    public function testOutputTruncatedAfterBatchingDoesNotRequestAnotherRetry(): void
    {
        $e = GeminiBriefingException::outputTruncatedAfterBatching();

        self::assertFalse($e->shouldRetryWithBatchedSummary());
        self::assertFalse($e->isOutputTruncated());
    }
}
