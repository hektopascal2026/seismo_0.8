<?php

declare(strict_types=1);

namespace Seismo\Service;

/**
 * Per-generate AI Researcher options (from the researcher form).
 */
final readonly class GeminiResearcherGenerationOptions
{
    public const MODE_STANDARD   = 'standard';
    public const MODE_TOURNAMENT = 'tournament';
    public const MODE_RELATIONAL = 'relational';

    public function __construct(
        public string $selectionMode = self::MODE_STANDARD,
        public bool $proSelectionMode = false,
    ) {
    }

    public static function defaults(): self
    {
        return new self();
    }

    public function selectionMode(): string
    {
        return $this->selectionMode;
    }

    /** @deprecated Use selectionMode === MODE_TOURNAMENT || MODE_RELATIONAL */
    public function tournamentMode(): bool
    {
        return $this->selectionMode === self::MODE_TOURNAMENT
            || $this->selectionMode === self::MODE_RELATIONAL;
    }

    public static function fromPost(array $post): self
    {
        $mode = trim((string)($post['selection_mode'] ?? ''));
        if ($mode === '') {
            // Backward compatibility with legacy tournament checkbox.
            $mode = (string)($post['tournament_mode'] ?? '0') === '1'
                ? self::MODE_TOURNAMENT
                : self::MODE_STANDARD;
        }

        if (!in_array($mode, [self::MODE_STANDARD, self::MODE_TOURNAMENT, self::MODE_RELATIONAL], true)) {
            $mode = self::MODE_STANDARD;
        }

        return new self(
            selectionMode: $mode,
            proSelectionMode: (string)($post['pro_selection_mode'] ?? '0') === '1',
        );
    }
}
