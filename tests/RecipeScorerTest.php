<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Core\Scoring\RecipeScorer;

final class RecipeScorerTest extends TestCase
{
    /** @return array<string, mixed> */
    private function minimalRecipe(): array
    {
        return [
            'classes' => ['investigation_lead', 'important', 'background', 'noise'],
            'class_weights' => [1.0, 0.66, 0.33, 0.0],
            'keywords' => [
                'eu' => ['investigation_lead' => 2.0],
                'regulation' => ['investigation_lead' => 1.5],
            ],
            'source_weights' => [],
        ];
    }

    public function testRepeatedKeywordDoesNotInflateRelevance(): void
    {
        $recipe = $this->minimalRecipe();
        $short  = RecipeScorer::score($recipe, 'EU regulation', 'Brief mention of EU once.');
        $long   = RecipeScorer::score(
            $recipe,
            'EU regulation',
            str_repeat('The EU regulation applies in member states. ', 200),
        );

        $this->assertNotNull($short);
        $this->assertNotNull($long);
        $this->assertLessThan(
            1.0,
            (float)$long['relevance_score'],
            'repeated tokens must not drive relevance to 1.0',
        );
        $this->assertEqualsWithDelta(
            (float)$short['relevance_score'],
            (float)$long['relevance_score'],
            0.001,
            'score should not grow with repetition once the token has matched',
        );
    }

    public function testNoKeywordMatchPredictsNoise(): void
    {
        $result = RecipeScorer::score(
            $this->minimalRecipe(),
            'Nothing relevant',
            'No recipe keywords here.',
        );

        $this->assertNotNull($result);
        $this->assertSame('noise', $result['predicted_label']);
        $this->assertSame('noise', $result['explanation']['prediction']);
    }

    public function testSourceWeightWithoutKeywordsStillClassifies(): void
    {
        $recipe = $this->minimalRecipe();
        $recipe['source_weights'] = [
            'rss' => ['investigation_lead' => 2.0],
        ];

        $result = RecipeScorer::score($recipe, 'Plain title', 'Plain body.', 'rss');

        $this->assertNotNull($result);
        $this->assertSame('investigation_lead', $result['predicted_label']);
    }

    public function testDistinctKeywordsStillAccumulate(): void
    {
        $recipe = $this->minimalRecipe();
        $one    = RecipeScorer::score($recipe, 'EU', 'Single anchor.');
        $two    = RecipeScorer::score($recipe, 'EU regulation', 'Both anchors in title.');

        $this->assertNotNull($one);
        $this->assertNotNull($two);
        $this->assertGreaterThan(
            (float)$one['relevance_score'],
            (float)$two['relevance_score'],
        );
    }

    public function testHyphenatedKeywordMatchesSplitTokens(): void
    {
        $recipe = $this->minimalRecipe();
        $recipe['keywords'] = [
            'kmu-förderung' => ['investigation_lead' => 3.0],
        ];

        $result = RecipeScorer::score($recipe, 'Policy', 'Die KMU-Förderung wird ausgebaut.');

        $this->assertNotNull($result);
        $this->assertSame('investigation_lead', $result['predicted_label']);
        $this->assertGreaterThan(0.5, (float)$result['relevance_score']);
    }
}
