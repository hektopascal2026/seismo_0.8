<?php

declare(strict_types=1);

namespace Seismo\Service;

/**
 * Score-based inclusion for AI Researcher (aligned with Highlights + badge bands).
 */
final class ResearcherScoreFilter
{
    public function __construct(
        public readonly float $alertThreshold,
        public readonly bool $includeImportantBelowThreshold,
        /** Skip Magnitu score pool; include all in-window module entries (experimental). */
        public readonly bool $disregardMagnitu = false,
    ) {
    }
}
