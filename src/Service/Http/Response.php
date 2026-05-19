<?php

declare(strict_types=1);

namespace Seismo\Service\Http;

/**
 * Outcome of a BaseClient call. Pure value object — no logic.
 */
final class Response
{
    /**
     * @param array<string, string> $headers Lower-cased header names mapped to last-value strings.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
        public readonly string $finalUrl,
    ) {
    }

    public function isOk(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * @return array<int|string, mixed>
     * @throws \JsonException on malformed JSON
     */
    public function json(): array
    {
        /** @var array<int|string, mixed> $decoded */
        $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
