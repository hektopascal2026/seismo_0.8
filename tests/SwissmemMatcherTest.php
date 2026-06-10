<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Util\SwissmemMatcher;
use Seismo\Util\WatchlistMatcher;

final class SwissmemMatcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Set up the static root constant if not defined in bootstrap
        if (!defined('SEISMO_ROOT')) {
            define('SEISMO_ROOT', dirname(__DIR__));
        }
    }

    public function testNormalizationCompanySuffixes(): void
    {
        $method = new ReflectionMethod(WatchlistMatcher::class, 'normalizeTerm');
        $method->setAccessible(true);

        self::assertSame('ABB', $method->invoke(null, 'ABB Schweiz AG'));
        self::assertSame('Bühler', $method->invoke(null, 'Bühler AG'));
        self::assertSame('Autoneum', $method->invoke(null, 'Autoneum Management AG'));
        self::assertSame('Schindler', $method->invoke(null, 'Schindler Holding SA'));
        self::assertSame('Stadler Rail', $method->invoke(null, 'Stadler Rail AG'));
        self::assertSame('Stephan SA', $method->invoke(null, 'Stephan SA'));
        self::assertSame('Stefan AG', $method->invoke(null, 'Stefan AG'));
    }

    public function testNormalizationExecutivePrefixes(): void
    {
        $method = new ReflectionMethod(WatchlistMatcher::class, 'normalizeTerm');
        $method->setAccessible(true);

        self::assertSame('Stefan Brupbacher', $method->invoke(null, 'Dr. Stefan Brupbacher'));
        self::assertSame('Ulisse Gendotti', $method->invoke(null, 'Dr. sc. techn. Ulisse Gendotti'));
    }

    public function testPrecisionRegexMatching(): void
    {
        // "Swissmem", "ABB", "Stadler Rail" are manually seeded high-profile terms
        self::assertTrue(SwissmemMatcher::matches('Wir trafen uns bei Swissmem.'));
        self::assertTrue(SwissmemMatcher::matches('Die ABB plant eine neue Fabrik.'));
        self::assertTrue(SwissmemMatcher::matches('Stadler Rail gewinnt Ausschreibung.'));
        self::assertFalse(SwissmemMatcher::matches('Dieses Wort hat nichts mit dem Verband zu tun.'));
    }

    public function testPrecisionRegexSensitivityAndBlacklisting(): void
    {
        // Blacklisted terms should never match (case-insensitive)
        self::assertFalse(SwissmemMatcher::matches('Wir verwenden verschiedene libs.'));
        self::assertFalse(SwissmemMatcher::matches('Das ist aero dynamisch.'));
        self::assertFalse(SwissmemMatcher::matches('Testing on the dyno.'));

        // Case-sensitive terms should only match when strictly capitalized
        self::assertTrue(SwissmemMatcher::matches('Neue Vakuumsysteme von VAT.'));
        self::assertFalse(SwissmemMatcher::matches('Die Mehrwertsteuer wird auch als vat bezeichnet.'));
        self::assertFalse(SwissmemMatcher::matches('The next step is crucial.'));
        self::assertTrue(SwissmemMatcher::matches('Software von NEXT SA.'));
        self::assertFalse(SwissmemMatcher::matches('Eine num. Variable.'));
        self::assertTrue(SwissmemMatcher::matches('Testing the NUM controller.'));
    }

    public function testMatchesTimelineItem(): void
    {
        $matchingItem = [
            'data' => [
                'title' => 'Grosser Erfolg für ABB Schweiz',
                'description' => 'Der Technologiekonzern wächst weiter.',
            ]
        ];

        $nonMatchingItem = [
            'data' => [
                'title' => 'Etwas ganz anderes',
                'description' => 'Keine Erwähnung hier.',
            ]
        ];

        self::assertTrue(SwissmemMatcher::matchesTimelineItem($matchingItem));
        self::assertFalse(SwissmemMatcher::matchesTimelineItem($nonMatchingItem));
    }

    public function testMatchesShapedEntry(): void
    {
        $matchingEntry = [
            'title' => 'Interview mit Stefan Brupbacher',
            'description' => 'Der Verbandsdirektor äussert sich zu den Exporten.',
            'content' => '',
        ];

        $nonMatchingEntry = [
            'title' => 'Wettervorhersage',
            'description' => 'Sonnig im Tessin.',
            'content' => '',
        ];

        self::assertTrue(SwissmemMatcher::matchesShapedEntry($matchingEntry));
        self::assertFalse(SwissmemMatcher::matchesShapedEntry($nonMatchingEntry));
    }
}
