<?php

declare(strict_types=1);

namespace Seismo\Service\Seismogramm\Pipeline\Engine;

use Seismo\Service\Seismogramm\Pipeline\ResilientGeminiClient;
use Seismo\Service\Seismogramm\Pipeline\SelectionPipelineContext;
use Seismo\Service\Seismogramm\Pipeline\SelectionResponseParser;

final class RelationalSelectionEngine
{
    private readonly TournamentSelectionEngine $tournamentEngine;

    public function __construct(
        ResilientGeminiClient $client,
        SelectionResponseParser $parser,
        StandardSelectionEngine $standardEngine,
    ) {
        $this->tournamentEngine = new TournamentSelectionEngine($client, $parser, $standardEngine);
    }

    /**
     * @param list<array<string, mixed>> $poolEntries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $researcherMeta
     * @return list<string>
     */
    public function select(
        string $model,
        string $apiKey,
        string $userSystemPrompt,
        array $poolEntries,
        array $scoresByKey,
        array $researcherMeta,
        int $itemCount,
        int $configuredMaxTokens,
        SelectionPipelineContext $pipelineContext,
    ): array {
        $relationalContext = new SelectionPipelineContext(
            globalFingerprintXml: $pipelineContext->globalFingerprintXml,
            useNegativeSpace: true,
            contextCacheName: $pipelineContext->contextCacheName,
            useContextCache: $pipelineContext->useContextCache,
        );

        return $this->tournamentEngine->select(
            $model,
            $apiKey,
            $userSystemPrompt,
            $poolEntries,
            $scoresByKey,
            $researcherMeta,
            $itemCount,
            $configuredMaxTokens,
            $relationalContext,
        );
    }
}
