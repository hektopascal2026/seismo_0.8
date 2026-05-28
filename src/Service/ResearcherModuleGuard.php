<?php

declare(strict_types=1);

namespace Seismo\Service;

use Seismo\Formatter\MarkdownResearcherFormatter;

/**
 * Hard module boundary for AI Researcher → Gemini.
 *
 * Every path that builds ENTRIES_DATA must pass through {@see sealGeminiContext()}.
 */
final class ResearcherModuleGuard
{
    public function __construct(
        private readonly ResearcherEntryGatherer $gatherer = new ResearcherEntryGatherer(),
    ) {
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    public function filter(array $entries, ResearcherSourceSelection $selection): array
    {
        return $this->gatherer->filterByModuleSelection($entries, $selection);
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    public function findLeaks(array $entries, ResearcherSourceSelection $selection): array
    {
        $leaks = [];
        foreach ($entries as $entry) {
            if (!$this->gatherer->entryMatchesModuleSelection($entry, $selection)) {
                $leaks[] = $entry;
            }
        }

        return $leaks;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @throws \RuntimeException
     */
    public function assertNoLeaks(array $entries, ResearcherSourceSelection $selection): void
    {
        $leaks = $this->findLeaks($entries, $selection);
        if ($leaks === []) {
            return;
        }

        $samples = [];
        foreach (array_slice($leaks, 0, 5) as $row) {
            $type = (string)($row['entry_type'] ?? '?');
            $id   = (string)($row['entry_id'] ?? '?');
            $bucket = $this->gatherer->moduleBucketForEntry(
                $row,
                ResearcherSourceSelection::forModules(true, true, true, true, true, true),
            );
            $samples[] = $type . ':' . $id . ' (full bucket=' . ($bucket ?? 'none') . ')';
        }

        error_log(
            'ResearcherModuleGuard: blocked ' . count($leaks)
            . ' deselected-module row(s): ' . implode(', ', $samples)
        );

        throw new \RuntimeException(
            'Internal module filter leak: ' . count($leaks)
            . ' row(s) from deselected source modules would have reached Gemini.'
        );
    }

    /**
     * Final gate before any Gemini call: filter rows, assert, rebuild XML from rows only,
     * assert XML ids match filtered rows exactly.
     *
     * @param list<array<string, mixed>> $entries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $meta
     * @return array{entries: list<array<string, mixed>>, markdown: string, markdownChars: int}
     */
    public function sealGeminiContext(
        array $entries,
        array $scoresByKey,
        array $meta,
        ResearcherSourceSelection $selection,
    ): array {
        $entries = $this->filter($entries, $selection);
        $this->assertNoLeaks($entries, $selection);

        $meta['total'] = count($entries);
        $markdown = MarkdownResearcherFormatter::format(
            $entries,
            $scoresByKey,
            $meta,
            true,
            MarkdownResearcherFormatter::FORMAT_XML,
        );

        $this->assertXmlMatchesEntries($markdown, $entries, $selection);

        return [
            'entries'       => $entries,
            'markdown'      => $markdown,
            'markdownChars' => strlen($markdown),
        ];
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @throws \RuntimeException
     */
    public function assertXmlMatchesEntries(
        string $xml,
        array $entries,
        ResearcherSourceSelection $selection,
    ): void {
        $this->assertNoLeaks($entries, $selection);

        $expected = [];
        foreach ($entries as $entry) {
            $type = (string)($entry['entry_type'] ?? '');
            $id   = (string)($entry['entry_id'] ?? '');
            if ($type !== '' && $id !== '' && ctype_digit($id)) {
                $expected[strtolower($type . ':' . $id)] = true;
            }
        }

        $xmlIds = $this->extractXmlEntryIds($xml);
        $extra  = array_diff($xmlIds, array_keys($expected));
        $missing = array_diff(array_keys($expected), $xmlIds);

        if ($extra === [] && $missing === []) {
            return;
        }

        if ($extra !== []) {
            error_log('ResearcherModuleGuard: XML contains unexpected ids: ' . implode(', ', $extra));
        }
        if ($missing !== []) {
            error_log('ResearcherModuleGuard: XML missing expected ids: ' . implode(', ', array_slice($missing, 0, 5)));
        }

        throw new \RuntimeException(
            'Gemini context XML does not match filtered module selection.'
        );
    }

    /**
     * @return list<string> Lowercase entry_type:entry_id values from XML <id> tags.
     */
    public function extractXmlEntryIds(string $xml): array
    {
        if ($xml === '' || !preg_match_all('/<id>([^<]+)<\/id>/', $xml, $matches)) {
            return [];
        }

        $ids = [];
        foreach ($matches[1] as $raw) {
            $id = strtolower(trim((string)$raw));
            if ($id !== '' && preg_match('/^[a-z][a-z0-9_]*:\d+$/', $id)) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
