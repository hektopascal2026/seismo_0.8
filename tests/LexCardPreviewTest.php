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

    public function testFrSummaryCombinesDescriptionAndExcerpt(): void
    {
        $synopsis = "Publié le : 27.05.2026\n\nTravaux préparatoires : loi n° 2026-404.\nAssemblée nationale : Proposition de loi n° 1102";
        $excerpt = "LOI n° 2026-404 du 26 mai 2026\n\nArticle 1er\nLe livre III du code de l'action sociale et des familles est ainsi modifié...";

        $preview = LexCardPreview::previewText([
            'source' => 'fr',
            'description' => $synopsis,
            'content_excerpt' => $excerpt,
        ]);

        self::assertStringContainsString('Publié le : 27.05.2026', $preview);
        self::assertStringContainsString('Proposition de loi n° 1102', $preview);
        self::assertStringContainsString('Le livre III du code', $preview);
        // Excerpt JORF header block should be stripped, but body article kept
        self::assertStringNotContainsString('LOI n° 2026-404 du 26 mai 2026', $preview);
    }

    public function testFrBriefingTextCombinesDescriptionAndExcerpt(): void
    {
        $row = [
            'source' => 'fr',
            'description' => "Publié le : 27.05.2026\n\nNotice : ce décret a pour objet...",
            'content' => "DÉCRET n° 2026-500\n\nArticle 1er\nLe présent décret entre en vigueur le lendemain...",
        ];

        $briefing = LexCardPreview::briefingText($row);

        self::assertStringContainsString('Publié le : 27.05.2026', $briefing);
        self::assertStringContainsString('Notice : ce décret a pour objet', $briefing);
        self::assertStringContainsString('Le présent décret entre en vigueur', $briefing);
    }

    public function testExtractDeliberationBrief(): void
    {
        $raw = "(1) Loi n° 2026-403.Travaux préparatoires :Sénat :Projet de loi n° 550 (2023-2024) ;Rapport de Mme Catherine Di Folco et M. Yves Bleunven, au nom de la commission spéciale, n° 634 (2023-2024) ;Texte de la commission n° 635 (2023-2024) ;Discussion les 3, 4 et 5 juin et le 22 octobre 2024 et adoption, après engagement de la procédure accélérée, le 22 octobre 2024 (TA n° 8, 2024-2025).Assemblée nationale :Projet de loi, adopté par le Sénat, n° 481 rect. ;Rapport de M. Christophe Naegelen et M. Stéphane Travert, au nom de la commission spéciale, n° 1191 rect. ;Discussion les 9, 10, 11...";
        $brief = \Seismo\Core\Lex\LexLegifranceContentFetcher::extractDeliberationBrief($raw);

        self::assertSame('Sénat : Projet de loi n° 550 • Assemblée nationale : Projet de loi n° 481', $brief);
    }
}

