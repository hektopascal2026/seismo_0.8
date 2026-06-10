<?php

declare(strict_types=1);

namespace Seismo\Service\Seismogramm\Pipeline;

/**
 * Shared pass-1 context: global fingerprint, context cache handle, relational flags.
 */
final class SelectionPipelineContext
{
    public function __construct(
        public readonly string $globalFingerprintXml = '',
        public readonly bool $useNegativeSpace = false,
        public ?string $contextCacheName = null,
    ) {
    }

    public function contextCacheActive(): bool
    {
        return $this->contextCacheName !== null && $this->contextCacheName !== '';
    }
}
