<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Plugin\ParlCh\ParlChPlugin;

final class ParlChBusinessContentTest extends TestCase
{
    public function testComposeBusinessContentKeepsAllSectionsWhenBrResponds(): void
    {
        $content = ParlChPlugin::composeBusinessContent(
            'Der Bundesrat empfiehlt die Ablehnung.',
            'Die Motion verlangt mehr Transparenz.',
            'Wir beantragen eine Offenlegungspflicht.',
        );

        self::assertStringContainsString('Antwort des Bundesrates:', $content);
        self::assertStringContainsString('Der Bundesrat empfiehlt die Ablehnung.', $content);
        self::assertStringContainsString('Begründung:', $content);
        self::assertStringContainsString('Die Motion verlangt mehr Transparenz.', $content);
        self::assertStringContainsString('Eingereichter Text:', $content);
        self::assertStringContainsString('Wir beantragen eine Offenlegungspflicht.', $content);
    }

    public function testComposeBusinessContentFallsBackToDescription(): void
    {
        $content = ParlChPlugin::composeBusinessContent('', '', '', 'Initial situation text.');

        self::assertSame('Initial situation text.', $content);
    }
}
