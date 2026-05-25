<?php

declare(strict_types=1);

namespace Seismo\Service;

/** Parsed Gemini briefing response for the AI Briefing Builder. */
final class GeminiBriefingResult
{
    /**
     * @param list<string> $usedEntryKeys entry_type:entry_id values cited for the top developments
     */
    public function __construct(
        public readonly string $markdown,
        public readonly array $usedEntryKeys = [],
    ) {
    }
}
