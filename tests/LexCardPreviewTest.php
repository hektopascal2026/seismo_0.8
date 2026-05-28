<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Core\Lex\LexCardPreview;
use Seismo\Plugin\LexFedlex\LexFedlexPlugin;

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

        self::assertStringNotContainsString("Änderung\n", $preview);
        self::assertStringContainsString('Beschlossen am: 06.05.2026', $preview);
        self::assertStringContainsString('Art. 5 wird geändert', $preview);
    }

    public function testBriefingTextIncludesAmendmentPillForFedlex(): void
    {
        $row = [
            'source' => 'ch',
            'title' => 'Verordnung des BAKOM über Fernmeldedienste',
            'description' => "Beschlossen am: 06.05.2026 • Inkrafttreten: 01.07.2026\nBAKOM — Verordnung vom 1997",
            'document_type' => 'Amtsverordnung',
            'document_date' => '2026-05-26',
            'eurlex_url' => 'https://www.fedlex.admin.ch/eli/oc/2026/244/de',
            'content' => "Die Bundesversammlung der Schweizerischen Eidgenossenschaft,\n\nbeschliesst:\n\nI\nArt. 5 wird wie folgt geändert.",
        ];

        $briefing = LexCardPreview::briefingText($row);

        self::assertStringStartsWith('Verordnung / Änderung', $briefing);
        self::assertStringContainsString('Beschlossen am: 06.05.2026', $briefing);
        self::assertStringContainsString('Art. 5 wird wie folgt geändert', $briefing);
        self::assertSame('Verordnung / Änderung', LexFedlexPlugin::documentTypePillLabelFromLexRow($row));
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

    public function testChSummaryKeepsFullDescriptionWhenExcerptIsEmpty(): void
    {
        $synopsis = "Stellungnahmefrist bis 17.09.2026\n\nTeilrevision des Fernmeldegesetzes (FMG) im Bereich Sicherheit";
        $preview = LexCardPreview::previewText([
            'source' => 'ch',
            'description' => $synopsis,
            'content_excerpt' => '',
        ]);

        self::assertSame($synopsis, $preview);
    }
}

