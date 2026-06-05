<?php

declare(strict_types=1);

namespace Seismo\Service;

/**
 * Resolved pass-1 selection behaviour for one generate run.
 */
final readonly class GeminiResearcherSelectionProfile
{
    public const STANDARD            = 'standard';
    public const TOURNAMENT          = 'tournament';
    public const RELATIONAL          = 'relational';
    public const VERIFICATION_HEAVY  = 'verification_heavy';

    public function __construct(
        public string $id,
        public string $requestedMode,
        public bool $verificationAutoDetected = false,
        public bool $useGlobalFingerprint = false,
        public bool $useTournament = false,
        public bool $useNegativeSpaceContract = false,
        public bool $keysOnlyJson = false,
        public bool $includeSelectionReasoning = true,
        public bool $capSelectionReasoning = false,
    ) {
    }

    public function usesTournamentPipeline(): bool
    {
        return $this->useTournament;
    }
}
