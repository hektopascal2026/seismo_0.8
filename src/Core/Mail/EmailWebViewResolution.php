<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

/**
 * Outcome of resolving a newsletter web-view / locale edition link.
 */
final class EmailWebViewResolution
{
    public function __construct(
        public readonly ?string $url,
        public readonly ?int $localeRank,
        public readonly bool $hydrateBody,
        public readonly ?string $warning = null,
    ) {
    }
}
