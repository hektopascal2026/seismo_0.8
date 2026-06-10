<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Core\Magnitu\MagnituEntryContract;
use Seismo\Core\Scoring\RecipeScorer;

final class MagnituEntryContractTest extends TestCase
{
    public function testLegSourceTypeMatchesExportShape(): void
    {
        $this->assertSame('leg_parliament_ch', MagnituEntryContract::legSourceType('parliament_ch'));
        $this->assertSame('leg_parliament', MagnituEntryContract::legSourceType(''));
    }

    public function testFeedSourceTypePassesParlPressThrough(): void
    {
        $this->assertSame('parl_press', MagnituEntryContract::feedSourceType('parl_press'));
        $this->assertSame('substack', MagnituEntryContract::feedSourceType('substack'));
        $this->assertSame('scraper', MagnituEntryContract::feedSourceType('scraper'));
        $this->assertSame('rss', MagnituEntryContract::feedSourceType('rss'));
        $this->assertSame('rss', MagnituEntryContract::feedSourceType('unknown_feed'));
    }

    public function testLegSourceWeightAppliesWithAlignedKey(): void
    {
        $recipe = [
            'classes' => ['investigation_lead', 'important', 'background', 'noise'],
            'class_weights' => [1.0, 0.66, 0.33, 0.0],
            'keywords' => ['__no_match__' => ['noise' => 1.0]],
            'source_weights' => [
                'leg_parliament_ch' => ['investigation_lead' => 2.0],
            ],
        ];

        $result = RecipeScorer::score(
            $recipe,
            'Motion title',
            'Synopsis only.',
            MagnituEntryContract::legSourceType('parliament_ch'),
        );

        $this->assertNotNull($result);
        $this->assertSame('investigation_lead', $result['predicted_label']);
    }

    public function testParlPressSourceWeightAppliesWithAlignedKey(): void
    {
        $recipe = [
            'classes' => ['investigation_lead', 'important', 'background', 'noise'],
            'class_weights' => [1.0, 0.66, 0.33, 0.0],
            'keywords' => ['__no_match__' => ['noise' => 1.0]],
            'source_weights' => [
                'parl_press' => ['important' => 2.0],
            ],
        ];

        $result = RecipeScorer::score(
            $recipe,
            'Press release',
            'Body text.',
            MagnituEntryContract::feedSourceType('parl_press'),
        );

        $this->assertNotNull($result);
        $this->assertSame('important', $result['predicted_label']);
    }
}
