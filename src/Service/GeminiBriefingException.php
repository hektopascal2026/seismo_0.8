<?php

declare(strict_types=1);

namespace Seismo\Service;

/**
 * User-safe failures from {@see GeminiBriefingService}. Message may be shown in the UI.
 */
final class GeminiBriefingException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly bool $retryWithBatchedSummary = false,
    ) {
        parent::__construct($message);
    }

    public function isRateLimitExceeded(): bool
    {
        return $this->httpStatus === 429;
    }

    public function isOutputTruncated(): bool
    {
        if ($this->retryWithBatchedSummary) {
            return true;
        }

        return str_contains($this->getMessage(), 'ran out of output space')
            && !str_contains($this->getMessage(), 'after splitting');
    }

    public function shouldRetryWithBatchedSummary(): bool
    {
        return $this->retryWithBatchedSummary;
    }

    public static function missingApiKey(): self
    {
        return new self('Gemini API key is not configured. Add it under Settings → General.');
    }

    public static function invalidApiKey(): self
    {
        return new self(
            'Gemini rejected the API key. Renew the key in Google AI Studio and update Settings → General.',
            403,
        );
    }

    public static function outputTruncated(): self
    {
        return new self(
            'Gemini ran out of output space for the full briefing (common with multi-section prompts or many items). '
            . 'Generate retries automatically in smaller parts when possible; if this persists, reduce “Number of items”, '
            . 'use a shorter template, or set system_config gemini:max_output_tokens higher.',
            null,
            true,
        );
    }

    public static function outputTruncatedAfterBatching(): self
    {
        return new self(
            'Gemini still ran out of output space after splitting the briefing (one item per request). '
            . 'Use 3 or fewer items, shorten multi-section blocks in your template, or set system_config gemini:max_output_tokens to 65536.',
            null,
            false,
        );
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
            $status === 403 => new self(
                'Gemini rejected the API key (403). Enable the Generative Language API for your Google Cloud project and check key restrictions.',
                403,
            ),
            $status === 429 => new self('Gemini rate limit exceeded. Try again in a few minutes.', 429),
            $status >= 500 => new self('Gemini service is temporarily unavailable. Try again later.', $status),
            default => new self(
                'Gemini request failed'
                . ($status > 0 ? ' (HTTP ' . $status . ').' : '.'),
                $status > 0 ? $status : null,
            ),
        };
    }

    public static function modelNotFound(string $model, string $apiMessage = ''): self
    {
        $msg = 'Gemini model "' . $model . '" is not available for this API key.';
        if ($apiMessage !== '') {
            $msg .= ' ' . self::truncateForUi($apiMessage);
        } else {
            $msg .= ' Try gemini-3.5-flash or set system_config gemini:model.';
        }

        return new self($msg, 404);
    }

    public static function fromApiMessage(int $status, string $apiMessage): self
    {
        if ($status === 403) {
            return self::fromHttpStatus(403);
        }
        if ($status === 429) {
            return self::fromHttpStatus(429);
        }

        return new self(self::truncateForUi($apiMessage), $status > 0 ? $status : null);
    }

    private static function truncateForUi(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
        if ($message === '') {
            return 'Gemini request failed.';
        }
        if (strlen($message) > 220) {
            return substr($message, 0, 217) . '…';
        }

        return $message;
    }

    public static function badResponse(string $detail = ''): self
    {
        $detail = trim(preg_replace('/\s+/', ' ', $detail) ?? $detail);
        if ($detail !== '') {
            return new self('Gemini returned an unexpected response: ' . self::truncateForUi($detail));
        }

        return new self('Gemini returned an unexpected response.');
    }

    public static function emptyResponse(string $detail = ''): self
    {
        $detail = trim(preg_replace('/\s+/', ' ', $detail) ?? $detail);
        if ($detail !== '') {
            return new self(self::truncateForUi($detail));
        }

        return new self('Gemini returned no summary text. Try different filters or a shorter window.');
    }

    public static function blocked(string $finishReason): self
    {
        return new self('Gemini did not return a summary (finish reason: ' . $finishReason . ').');
    }
}
