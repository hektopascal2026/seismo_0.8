<?php

declare(strict_types=1);

namespace Seismo\Service\Seismogramm\Pipeline\Engine;

use Seismo\Service\Seismogramm\Pipeline\ResilientGeminiClient;
use Seismo\Service\Seismogramm\Pipeline\SelectionResponseParser;
use Seismo\Service\Seismogramm\SeismogrammContracts;

final class RelationalSelectionEngine
{
    private readonly TournamentSelectionEngine $tournamentEngine;

    public function __construct(
        private readonly ResilientGeminiClient $client,
        private readonly SelectionResponseParser $parser,
        private readonly StandardSelectionEngine $standardEngine
    ) {
        $this->tournamentEngine = new TournamentSelectionEngine($this->client, $this->parser, $this->standardEngine);
    }

    /**
     * Executes relational selection (Blindspot analysis with negative-space protocols).
     */
    public function select(
        string $model,
        string $apiKey,
        string $userSystemPrompt,
        array $poolEntries,
        array $scoresByKey,
        array $researcherMeta,
        int $itemCount,
        int $configuredMaxTokens
    ): array {
        $protocolSystemPrompt = $userSystemPrompt . "\n\n" . SeismogrammContracts::RELATIONAL_NEGATIVE_SPACE_PROTOCOL;

        return $this->tournamentEngine->select(
            $model,
            $apiKey,
            $protocolSystemPrompt,
            $poolEntries,
            $scoresByKey,
            $researcherMeta,
            $itemCount,
            $configuredMaxTokens
        );
    }
}
