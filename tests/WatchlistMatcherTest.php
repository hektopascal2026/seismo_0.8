<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Util\WatchlistMatcher;

final class WatchlistMatcherTest extends TestCase
{
    public function testParsesPipeSeparatedLines(): void
    {
        $matcher = WatchlistMatcher::fromContent("Dorothee Auwärter | Kuhn Rikon AG\nABB\nVAT Group");
        self::assertTrue($matcher->matches('Die ABB plant eine neue Fabrik.'));
        self::assertTrue($matcher->matches('Neue Vakuumsysteme von VAT Group.'));
        self::assertFalse($matcher->matches('Dieses Wort hat nichts mit dem Verband zu tun.'));
    }

    public function testCaseSensitiveAcronymsAvoidFalsePositives(): void
    {
        $matcher = WatchlistMatcher::fromContent("VAT Group\nNEXT SA\nNUM");
        self::assertTrue($matcher->matches('Neue Vakuumsysteme von VAT Group.'));
        self::assertFalse($matcher->matches('Die Mehrwertsteuer wird auch als vat bezeichnet.'));
        self::assertTrue($matcher->matches('Software von NEXT SA.'));
        self::assertTrue($matcher->matches('Testing the NUM controller.'));
    }

    public function testBuiltInSwissmemIncludesManualHighProfileTerms(): void
    {
        $matcher = WatchlistMatcher::fromBuiltInSwissmemFile();
        self::assertTrue($matcher->matches('Wir trafen uns bei Swissmem.'));
        self::assertTrue($matcher->matches('Stadler Rail gewinnt Ausschreibung.'));
    }

    public function testMatchedTermsInShapedEntry(): void
    {
        $matcher = WatchlistMatcher::fromContent("ABB\nSchindler");
        $entry = [
            'title' => 'ABB und Partner',
            'description' => 'Schindler bestätigt Investition.',
            'content' => '',
        ];
        $hits = $matcher->matchedTermsInShapedEntry($entry);
        self::assertContains('ABB', $hits);
        self::assertContains('Schindler', $hits);
    }
}
