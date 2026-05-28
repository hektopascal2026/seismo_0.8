<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Service\GeminiResearcherException;

final class GeminiResearcherExceptionTest extends TestCase
{
    public function testRateLimitExceptionIsDetectable(): void
    {
        $e = GeminiResearcherException::fromHttpStatus(429);

        self::assertTrue($e->isRateLimitExceeded());
        self::assertSame(429, $e->httpStatus);
    }

    public function testTransportFailureIsNotRateLimit(): void
    {
        $e = GeminiResearcherException::transportFailed();

        self::assertFalse($e->isRateLimitExceeded());
    }

    public function testOutputTruncatedRequestsBatchedRetry(): void
    {
        $e = GeminiResearcherException::outputTruncated();

        self::assertTrue($e->shouldRetryWithBatchedSummary());
        self::assertTrue($e->isOutputTruncated());
    }

    public function testOutputTruncatedAfterBatchingDoesNotRequestAnotherRetry(): void
    {
        $e = GeminiResearcherException::outputTruncatedAfterBatching();

        self::assertFalse($e->shouldRetryWithBatchedSummary());
        self::assertFalse($e->isOutputTruncated());
    }
}
