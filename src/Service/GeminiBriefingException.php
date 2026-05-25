<?php

declare(strict_types=1);

namespace Seismo\Service;

/**
 * User-safe failures from {@see GeminiBriefingService}. Message may be shown in the UI.
 */
final class GeminiBriefingException extends \RuntimeException
{
    public static function missingApiKey(): self
    {
        return new self('Gemini API key is not configured. Add it under Settings → General.');
    }

    public static function invalidInput(string $message): self
    {
        return new self($message);
    }

    public static function transportFailed(): self
    {
        return new self('Could not reach the Gemini API. Check network connectivity and try again.');
    }

    public static function fromHttpStatus(int $status): self
    {
        return match (true) {
            $status === 429 => new self('Gemini rate limit exceeded. Try again in a few minutes.'),
            $status >= 500 => new self('Gemini service is temporarily unavailable. Try again later.'),
            default => new self('Gemini request failed. Check the server error log.'),
        };
    }

    public static function badResponse(): self
    {
        return new self('Gemini returned an unexpected response. Check the server error log.');
    }

    public static function emptyResponse(): self
    {
        return new self('Gemini returned no summary text. Try different filters or a shorter window.');
    }

    public static function blocked(string $finishReason): self
    {
        return new self('Gemini did not return a summary (finish reason: ' . $finishReason . ').');
    }
}
