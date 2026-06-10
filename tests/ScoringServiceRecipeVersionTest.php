<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Core\Scoring\ScoringService;

final class ScoringServiceRecipeVersionTest extends TestCase
{
    public function testResolveRecipeVersionPrefersEmbeddedJsonVersion(): void
    {
        $this->assertSame(
            18,
            ScoringService::resolveRecipeVersion(['version' => 18], 17),
        );
    }

    public function testResolveRecipeVersionFallsBackToConfigWhenJsonMissing(): void
    {
        $this->assertSame(
            17,
            ScoringService::resolveRecipeVersion(['keywords' => ['x' => []]], 17),
        );
    }

    public function testResolveRecipeVersionReturnsZeroWhenNeitherSet(): void
    {
        $this->assertSame(0, ScoringService::resolveRecipeVersion([], 0));
    }
}
