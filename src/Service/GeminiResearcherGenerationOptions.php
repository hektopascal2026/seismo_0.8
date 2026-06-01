<?php

declare(strict_types=1);

namespace Seismo\Service;

/**
 * Per-generate AI Researcher options (from the researcher form).
 */
final readonly class GeminiResearcherGenerationOptions
{
    public function __construct(
        public bool $tournamentMode = false,
        public bool $proSelectionMode = false,
    ) {
    }

    public static function defaults(): self
    {
        return new self();
    }

    public static function fromPost(array $post): self
    {
        return new self(
            tournamentMode: (string)($post['tournament_mode'] ?? '0') === '1',
            proSelectionMode: (string)($post['pro_selection_mode'] ?? '0') === '1',
        );
    }
}
