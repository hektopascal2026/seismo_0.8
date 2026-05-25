<?php

declare(strict_types=1);

namespace Seismo\Service;

/** Parsed Gemini briefing response for the AI Briefing Builder. */
final class GeminiBriefingResult
{
    /**
     * @param list<string> $usedEntryKeys entry_type:entry_id values cited for the top developments
     * @param bool $attributionParsed When false, used_entry_keys were not trusted (show briefing only).
     */
    public function __construct(
        public readonly string $markdown,
        public readonly array $usedEntryKeys = [],
        public readonly bool $attributionParsed = true,
    ) {
    }
}
