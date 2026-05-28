<?php

declare(strict_types=1);

namespace Seismo\Service;

/** Parsed Gemini researcher response for the AI Researcher. */
final class GeminiResearcherResult
{
    /**
     * @param list<string> $usedEntryKeys entry_type:entry_id values cited for the top developments
     * @param bool $attributionParsed When false, used_entry_keys were not trusted (show researcher only).
     */
    public function __construct(
        public readonly string $markdown,
        public readonly array $usedEntryKeys = [],
        public readonly bool $attributionParsed = true,
    ) {
    }
}
