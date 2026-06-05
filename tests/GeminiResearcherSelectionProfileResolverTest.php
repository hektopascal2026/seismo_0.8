<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Service\GeminiResearcherGenerationOptions;
use Seismo\Service\GeminiResearcherSelectionProfile;
use Seismo\Service\GeminiResearcherSelectionProfileResolver;

final class GeminiResearcherSelectionProfileResolverTest extends TestCase
{
    private GeminiResearcherSelectionProfileResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new GeminiResearcherSelectionProfileResolver();
    }

    public function testStandardMode(): void
    {
        $profile = $this->resolver->resolve(
            GeminiResearcherGenerationOptions::defaults(),
            'Summarise the most important Economist-style stories.',
        );

        self::assertSame(GeminiResearcherSelectionProfile::STANDARD, $profile->id);
        self::assertFalse($profile->usesTournamentPipeline());
        self::assertFalse($profile->useGlobalFingerprint);
    }

    public function testTournamentMode(): void
    {
        $profile = $this->resolver->resolve(
            new GeminiResearcherGenerationOptions(selectionMode: GeminiResearcherGenerationOptions::MODE_TOURNAMENT),
            'Pick the top stories.',
        );

        self::assertSame(GeminiResearcherSelectionProfile::TOURNAMENT, $profile->id);
        self::assertTrue($profile->usesTournamentPipeline());
        self::assertTrue($profile->useGlobalFingerprint);
        self::assertTrue($profile->keysOnlyJson);
    }

    public function testRelationalMode(): void
    {
        $profile = $this->resolver->resolve(
            new GeminiResearcherGenerationOptions(selectionMode: GeminiResearcherGenerationOptions::MODE_RELATIONAL),
            'Find primary sources with no media echo.',
        );

        self::assertSame(GeminiResearcherSelectionProfile::RELATIONAL, $profile->id);
        self::assertTrue($profile->useNegativeSpaceContract);
        self::assertTrue($profile->keysOnlyJson);
    }

    public function testVerificationHeavyOverridesTournament(): void
    {
        $prompt = <<<'PROMPT'
        Swissmem Monitor — zwingende Verifikation: Unter keinen Umständen ein Unternehmen wählen,
        das nicht im Text erwähnt ist.
        PROMPT;

        $profile = $this->resolver->resolve(
            new GeminiResearcherGenerationOptions(selectionMode: GeminiResearcherGenerationOptions::MODE_TOURNAMENT),
            $prompt,
        );

        self::assertSame(GeminiResearcherSelectionProfile::VERIFICATION_HEAVY, $profile->id);
        self::assertTrue($profile->verificationAutoDetected);
        self::assertFalse($profile->usesTournamentPipeline());
        self::assertTrue($profile->capSelectionReasoning);
    }
}
