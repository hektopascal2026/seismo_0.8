<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Formatter\MarkdownResearcherFormatter;
use Seismo\Service\ResearcherModuleGuard;
use Seismo\Service\ResearcherSourceSelection;

final class ResearcherModuleGuardTest extends TestCase
{
    /**
     * @return list<array{0: ResearcherSourceSelection, 1: array<string, mixed>, 2: string}>
     */
    public static function leakFixtures(): array
    {
        return [
            'media off' => [
                ResearcherSourceSelection::forModules(true, false, false, false, false, false, false),
                [
                    'entry_type'      => 'feed_item',
                    'entry_id'        => 1,
                    'source_type'     => 'rss',
                    'source_category' => 'media',
                ],
                'media',
            ],
            'feeds off' => [
                ResearcherSourceSelection::forModules(false, true, false, false, false, false, false),
                [
                    'entry_type'      => 'feed_item',
                    'entry_id'        => 2,
                    'source_type'     => 'rss',
                    'source_category' => 'news',
                ],
                'feeds',
            ],
            'scraper off' => [
                ResearcherSourceSelection::forModules(true, false, false, false, false, false, false),
                [
                    'entry_type'      => 'feed_item',
                    'entry_id'        => 3,
                    'source_type'     => 'scraper',
                    'source_category' => 'scraper',
                ],
                'scraper',
            ],
            'mail off' => [
                ResearcherSourceSelection::forModules(false, false, false, false, false, true, false),
                [
                    'entry_type' => 'email',
                    'entry_id'   => 4,
                    'source_type' => 'email',
                ],
                'email',
            ],
            'lex off' => [
                ResearcherSourceSelection::forModules(false, false, false, false, false, false, true),
                [
                    'entry_type'  => 'lex_item',
                    'entry_id'    => 5,
                    'source_type' => 'lex_de',
                ],
                'lex',
            ],
            'leg off' => [
                ResearcherSourceSelection::forModules(false, false, false, false, false, true, false),
                [
                    'entry_type'  => 'calendar_event',
                    'entry_id'    => 6,
                    'source_type' => 'parliament_ch',
                ],
                'leg',
            ],
        ];
    }

    /**
     * @dataProvider leakFixtures
     */
    public function testSealGeminiContextStripsDeselectedModuleRows(
        ResearcherSourceSelection $selection,
        array $leakRow,
        string $disabledBucket,
    ): void {
        $allowed = $this->allowedRowForSelection($selection);

        $guard = new ResearcherModuleGuard();
        $sealed = $guard->sealGeminiContext(
            [$allowed, $leakRow],
            [],
            [],
            $selection,
        );

        self::assertCount(1, $sealed['entries']);
        self::assertSame($allowed['entry_id'], $sealed['entries'][0]['entry_id']);
        $leakKey = strtolower(($leakRow['entry_type'] ?? '') . ':' . ($leakRow['entry_id'] ?? ''));
        self::assertNotContains($leakKey, $guard->extractXmlEntryIds($sealed['markdown']));
        self::assertSame(
            [strtolower($allowed['entry_type'] . ':' . $allowed['entry_id'])],
            $guard->extractXmlEntryIds($sealed['markdown']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function allowedRowForSelection(ResearcherSourceSelection $selection): array
    {
        if ($selection->moduleFeeds()) {
            return [
                'entry_type'      => 'feed_item',
                'entry_id'        => 99,
                'source_type'     => 'rss',
                'source_category' => 'news',
                'title'           => 'Allowed feeds',
            ];
        }
        if ($selection->moduleMedia()) {
            return [
                'entry_type'      => 'feed_item',
                'entry_id'        => 99,
                'source_type'     => 'rss',
                'source_category' => 'media',
                'title'           => 'Allowed media',
            ];
        }
        if ($selection->moduleScraper()) {
            return [
                'entry_type'      => 'feed_item',
                'entry_id'        => 99,
                'source_type'     => 'scraper',
                'source_category' => 'scraper',
                'title'           => 'Allowed scraper',
            ];
        }
        if ($selection->moduleEmail()) {
            return [
                'entry_type'   => 'email',
                'entry_id'     => 99,
                'source_type'  => 'email',
                'module_scope' => 'mail',
                'title'        => 'Allowed mail',
            ];
        }
        if ($selection->moduleNewsletter()) {
            return [
                'entry_type'   => 'email',
                'entry_id'     => 99,
                'source_type'  => 'email',
                'module_scope' => 'newsletter',
                'title'        => 'Allowed newsletter',
            ];
        }
        if ($selection->moduleLex()) {
            return [
                'entry_type'  => 'lex_item',
                'entry_id'    => 99,
                'source_type' => 'lex_de',
                'title'       => 'Allowed lex',
            ];
        }

        return [
            'entry_type'  => 'calendar_event',
            'entry_id'    => 99,
            'source_type' => 'parliament_ch',
            'title'       => 'Allowed leg',
        ];
    }

    public function testAssertNoLeaksThrowsWhenDeselectedRowPresent(): void
    {
        $selection = ResearcherSourceSelection::forModules(true, false, false, false, false, false, false);
        $guard     = new ResearcherModuleGuard();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Internal module filter leak');

        $guard->assertNoLeaks([[
            'entry_type'      => 'feed_item',
            'entry_id'        => 7,
            'source_type'     => 'rss',
            'source_category' => 'media',
        ]], $selection);
    }

    public function testAssertXmlMatchesEntriesRejectsTamperedMarkdown(): void
    {
        $selection = ResearcherSourceSelection::forModules(true, false, false, false, false, false, false);
        $entries   = [[
            'entry_type'      => 'feed_item',
            'entry_id'        => 8,
            'source_type'     => 'rss',
            'source_category' => 'news',
            'title'           => 'Allowed',
        ]];
        $leakedXml = MarkdownResearcherFormatter::format(
            array_merge($entries, [[
                'entry_type'      => 'feed_item',
                'entry_id'        => 9,
                'source_type'     => 'rss',
                'source_category' => 'media',
                'title'           => 'Leaked',
            ]]),
            [],
            [],
            true,
            MarkdownResearcherFormatter::FORMAT_XML,
        );

        $guard = new ResearcherModuleGuard();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Gemini context XML does not match');

        $guard->assertXmlMatchesEntries($leakedXml, $entries, $selection);
    }

    public function testSealGeminiContextXmlIdsMatchFilteredEntriesExactly(): void
    {
        $selection = ResearcherSourceSelection::forModules(false, false, false, false, false, true, false);
        $entries   = [
            ['entry_type' => 'lex_item', 'entry_id' => 10, 'source_type' => 'lex_de', 'title' => 'A'],
            ['entry_type' => 'lex_item', 'entry_id' => 11, 'source_type' => 'lex_fr', 'title' => 'B'],
        ];

        $guard  = new ResearcherModuleGuard();
        $sealed = $guard->sealGeminiContext($entries, [], [], $selection);
        $ids    = $guard->extractXmlEntryIds($sealed['markdown']);

        sort($ids);
        self::assertSame(['lex_item:10', 'lex_item:11'], $ids);
    }
}
