<?php

declare(strict_types=1);

namespace Seismo\Service;

/**
 * Maps UI selection mode + system prompt heuristics to a pass-1 profile.
 */
final class GeminiResearcherSelectionProfileResolver
{
    public function resolve(
        GeminiResearcherGenerationOptions $options,
        string $systemPrompt,
    ): GeminiResearcherSelectionProfile {
        $requested      = $options->selectionMode();
        $verification   = $this->detectVerificationHeavyPrompt($systemPrompt);

        if ($requested === GeminiResearcherGenerationOptions::MODE_RELATIONAL) {
            return new GeminiResearcherSelectionProfile(
                id: GeminiResearcherSelectionProfile::RELATIONAL,
                requestedMode: $requested,
                verificationAutoDetected: $verification,
                useGlobalFingerprint: true,
                useTournament: true,
                useNegativeSpaceContract: true,
                keysOnlyJson: false,
                includeSelectionReasoning: true,
                capSelectionReasoning: false,
            );
        }

        if ($requested === GeminiResearcherGenerationOptions::MODE_TOURNAMENT) {
            return new GeminiResearcherSelectionProfile(
                id: GeminiResearcherSelectionProfile::TOURNAMENT,
                requestedMode: $requested,
                verificationAutoDetected: $verification,
                useGlobalFingerprint: true,
                useTournament: true,
                useNegativeSpaceContract: false,
                keysOnlyJson: false,
                includeSelectionReasoning: true,
                capSelectionReasoning: false,
            );
        }

        return new GeminiResearcherSelectionProfile(
            id: GeminiResearcherSelectionProfile::STANDARD,
            requestedMode: $requested,
            verificationAutoDetected: $verification,
            useGlobalFingerprint: false,
            useTournament: false,
            useNegativeSpaceContract: false,
            keysOnlyJson: false,
            includeSelectionReasoning: true,
            capSelectionReasoning: false,
        );
    }

    public function detectVerificationHeavyPrompt(string $systemPrompt): bool
    {
        $text = strtolower($systemPrompt);
        if ($text === '') {
            return false;
        }

        if (str_contains($text, 'verifikationskriterium')
            || str_contains($text, 'zwingende verifikation')
            || str_contains($text, 'zwei-stufiger filter')) {
            return true;
        }

        if (str_contains($text, 'unter keinen umständen')
            && (str_contains($text, 'im text erwähnt') || str_contains($text, 'im eintrag'))) {
            return true;
        }

        if (str_contains($text, 'swissmem monitor')
            && str_contains($text, 'unternehmen')) {
            return true;
        }

        if (str_contains($text, 'watchlist monitor')
            && str_contains($text, 'watchlist')) {
            return true;
        }

        return str_contains($text, 'verification')
            && (str_contains($text, 'must mention') || str_contains($text, 'in the text'));
    }
}
