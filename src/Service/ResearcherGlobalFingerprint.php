<?php

declare(strict_types=1);

namespace Seismo\Service;

/**
 * Compact id/title/module index for cross-batch relational selection.
 */
final class ResearcherGlobalFingerprint
{
    public const MAX_TITLE_CHARS = 160;

    /**
     * @param list<array<string, mixed>> $entries
     */
    public static function buildXml(
        array $entries,
        ?ResearcherEntryGatherer $gatherer = null,
        ?ResearcherSourceSelection $selection = null,
    ): string {
        if ($entries === []) {
            return '';
        }

        $gatherer ??= new ResearcherEntryGatherer();
        $lines    = ['<global_fingerprint>'];

        foreach ($entries as $entry) {
            $type = (string)($entry['entry_type'] ?? '');
            $id   = (string)($entry['entry_id'] ?? '');
            if ($type === '' || $id === '' || !ctype_digit($id)) {
                continue;
            }

            $key = strtolower($type . ':' . $id);
            $title = self::truncateTitle((string)($entry['title'] ?? '(untitled)'));
            $module = self::moduleLabel($entry, $gatherer, $selection);

            $lines[] = '  <item>'
                . '<id>' . self::escape($key) . '</id>'
                . '<title>' . self::escape($title) . '</title>'
                . '<module>' . self::escape($module) . '</module>'
                . '</item>';
        }

        $lines[] = '</global_fingerprint>';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function moduleLabel(
        array $entry,
        ResearcherEntryGatherer $gatherer,
        ?ResearcherSourceSelection $selection,
    ): string {
        if ($selection !== null) {
            $bucket = $gatherer->moduleBucketForEntry($entry, $selection);
            if ($bucket !== null && $bucket !== '') {
                return $bucket;
            }
        }

        return match ((string)($entry['entry_type'] ?? '')) {
            'lex_item'        => 'lex',
            'calendar_event'  => 'leg',
            'email'           => 'mail',
            default           => 'feeds',
        };
    }

    private static function truncateTitle(string $title): string
    {
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? $title);
        if ($title === '') {
            return '(untitled)';
        }
        if (strlen($title) <= self::MAX_TITLE_CHARS) {
            return $title;
        }

        return substr($title, 0, self::MAX_TITLE_CHARS - 1) . '…';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
