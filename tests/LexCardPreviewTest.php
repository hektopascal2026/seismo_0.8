<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Core\Lex\LexCardPreview;

final class LexCardPreviewTest extends TestCase
{
    public function testChSummaryAppendsCorpusWhenDescriptionPresent(): void
    {
        $synopsis = "Änderung\nBeschlossen am: 06.05.2026 • Inkrafttreten: 01.07.2026\nBAKOM — Verordnung vom 1997";
        $corpus   = "Die Bundesversammlung der Schweizerischen Eidgenossenschaft,\n\nbeschliesst:\n\nI\nArt. 5 wird geändert.";

        $preview = LexCardPreview::previewText([
            'source' => 'ch',
            'description' => $synopsis,
            'content_excerpt' => $corpus,
        ]);

        self::assertStringContainsString('Änderung', $preview);
        self::assertStringContainsString('Die Bundesversammlung', $preview);
        self::assertStringContainsString('Art. 5 wird geändert', $preview);
    }

    public function testChSummaryDoesNotDuplicatePromotedSynopsis(): void
    {
        $line = 'Bundesamt — Verordnung vom 9. März 2007';

        $preview = LexCardPreview::previewText([
            'source' => 'ch',
            'description' => $line,
            'content_excerpt' => $line,
        ]);

        self::assertSame($line, $preview);
    }
}
