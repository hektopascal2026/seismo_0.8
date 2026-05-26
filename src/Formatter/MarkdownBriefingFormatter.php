<?php
/**
 * Markdown briefing formatter.
 *
 * Produces a human / LLM-readable plain-text digest from a list of already-
 * shaped entries. Output is designed to be fed to an LLM (per the
 * "machine-readable briefings" goal in `docs/consolidation-plan.md` Slice 5)
 * or read directly by a human consuming the automation.
 *
 * No HTML — emphatically. Repositories return raw bytes, views escape for
 * HTML; this formatter returns Markdown so neither of those boundaries is
 * crossed. Consumers can pipe the body straight into an LLM or save it to
 * disk.
 */

declare(strict_types=1);

namespace Seismo\Formatter;

use DateTimeImmutable;
use DateTimeZone;
use Seismo\Core\Lex\LexCardPreview;
use Seismo\Core\Lex\LexPlainText;

final class MarkdownBriefingFormatter
{
    public const CONTENT_TYPE = 'text/markdown; charset=utf-8';

    /** Human/LLM markdown list (export downloads, legacy). */
    public const FORMAT_MARKDOWN = 'markdown';

    /** Tagged entries for AI Briefing Builder — clearer boundaries for extraction. */
    public const FORMAT_XML = 'xml';

    /** Max characters of entry body text included per item (content, else description). */
    public const ENTRY_BODY_MAX_CHARS = 1000;

    /** Lex sources that receive {@see LexCardPreview::briefingText()} in AI briefing context. */
    private const LEX_BRIEFING_BODY_SOURCES = ['de', 'fr', 'eu'];

    /**
     * @param array<int, array<string, mixed>> $entries Shaped Magnitu-contract rows.
     * @param array<string, array<string, mixed>> $scoresByKey "type:id" → score row.
     * @param array<string, mixed> $meta               Printed in the preamble (since, total, etc.).
     * @param bool $includeEntryIds                    When true, each item is tagged for LLM attribution.
     * @param string $format                           {@see FORMAT_MARKDOWN} or {@see FORMAT_XML}.
     */
    public static function format(
        array $entries,
        array $scoresByKey,
        array $meta,
        bool $includeEntryIds = false,
        string $format = self::FORMAT_MARKDOWN,
    ): string {
        if ($format === self::FORMAT_XML) {
            return self::formatXml($entries, $scoresByKey, $meta, $includeEntryIds);
        }

        return self::formatMarkdown($entries, $scoresByKey, $meta, $includeEntryIds);
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $meta
     */
    private static function formatMarkdown(array $entries, array $scoresByKey, array $meta, bool $includeEntryIds): string
    {
        $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s\Z');

        $lines = [];
        $lines[] = '# Seismo briefing';
        $lines[] = '';
        $lines[] = '- Generated: ' . $generatedAt;
        if (isset($meta['since']) && $meta['since'] !== null && $meta['since'] !== '') {
            $lines[] = '- Since: ' . (string)$meta['since'];
        }
        $lines[] = '- Total entries: ' . count($entries);
        if (isset($meta['score_selection']) && $meta['score_selection'] !== '') {
            $lines[] = '- Score selection: ' . (string)$meta['score_selection'];
        } elseif (isset($meta['label_filter']) && $meta['label_filter'] !== null) {
            $labels = is_array($meta['label_filter']) ? implode(', ', $meta['label_filter']) : (string)$meta['label_filter'];
            $lines[] = '- Label filter: ' . $labels;
        }
        $lines[] = '';

        if ($entries === []) {
            $lines[] = '_No entries matched the requested window._';
            return implode("\n", $lines) . "\n";
        }

        // Group by source_type for readability. Preserves the order each group
        // first appeared, so the caller's ordering (usually score-descending)
        // still shows in the output.
        $groups = [];
        foreach ($entries as $e) {
            $bucket = (string)($e['source_type'] ?? 'other');
            $groups[$bucket][] = $e;
        }

        foreach ($groups as $bucket => $rows) {
            $lines[] = '## ' . strtoupper($bucket) . ' (' . count($rows) . ')';
            $lines[] = '';
            foreach ($rows as $e) {
                $key   = ($e['entry_type'] ?? '') . ':' . ($e['entry_id'] ?? '');
                $score = $scoresByKey[$key] ?? null;

                $title = self::sanitizeLinkText((string)($e['title'] ?? '(untitled)'));
                if ($title === '') {
                    $title = '(untitled)';
                }
                $link  = self::sanitizeLinkUrl((string)($e['link'] ?? ''));
                $header = $link !== '' ? "- [{$title}]({$link})" : "- {$title}";
                $lines[] = $header;

                if ($includeEntryIds && $key !== '' && $key !== ':') {
                    $lines[] = '  - [ID: ' . $key . ']';
                }

                $bits = [];
                if (!empty($e['published_date'])) {
                    $bits[] = 'Date: ' . (string)$e['published_date'];
                }
                if (!empty($e['source_name'])) {
                    $bits[] = 'Source: ' . (string)$e['source_name'];
                }
                if (!empty($e['source_category'])) {
                    $bits[] = 'Category: ' . (string)$e['source_category'];
                }
                if ($score !== null) {
                    $rel = (float)($score['relevance_score'] ?? 0);
                    $lbl = (string)($score['predicted_label'] ?? '');
                    $src = (string)($score['score_source']    ?? '');
                    $bits[] = sprintf(
                        'Score: %.2f (%s, %s)',
                        $rel,
                        $lbl !== '' ? $lbl : 'unscored',
                        $src !== '' ? $src : 'n/a'
                    );
                }
                if ($bits !== []) {
                    $lines[] = '  - ' . implode(' · ', $bits);
                }

            $body = self::formatEntryBody($e, self::resolveEntryBodyMaxChars($meta));
            if ($body !== '') {
                $lines[] = '  - ' . $body;
                }
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @param array<string, array<string, mixed>> $scoresByKey
     * @param array<string, mixed> $meta
     */
    private static function formatXml(array $entries, array $scoresByKey, array $meta, bool $includeEntryIds): string
    {
        $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s\Z');

        $parts = ['<seismo_briefing'];
        $parts[] = ' generated="' . self::escapeXmlAttribute($generatedAt) . '"';
        $parts[] = ' total_entries="' . count($entries) . '"';
        if (isset($meta['since']) && $meta['since'] !== null && $meta['since'] !== '') {
            $parts[] = ' since="' . self::escapeXmlAttribute((string)$meta['since']) . '"';
        }
        if (isset($meta['score_selection']) && $meta['score_selection'] !== '') {
            $parts[] = ' score_selection="' . self::escapeXmlAttribute((string)$meta['score_selection']) . '"';
        } elseif (isset($meta['label_filter']) && $meta['label_filter'] !== null) {
            $labels = is_array($meta['label_filter'])
                ? implode(', ', $meta['label_filter'])
                : (string)$meta['label_filter'];
            $parts[] = ' label_filter="' . self::escapeXmlAttribute($labels) . '"';
        }
        $parts[] = '>';
        $xml = implode('', $parts);

        if ($entries === []) {
            return $xml . '<empty>No entries matched the requested window.</empty></seismo_briefing>';
        }

        $xml .= '<entries>';
        foreach ($entries as $e) {
            $key   = ($e['entry_type'] ?? '') . ':' . ($e['entry_id'] ?? '');
            $score = $scoresByKey[$key] ?? null;

            $xml .= '<entry>';
            if ($includeEntryIds && $key !== '' && $key !== ':') {
                $xml .= '<id>' . self::escapeXmlText($key) . '</id>';
            }

            $title = self::sanitizeLinkText((string)($e['title'] ?? '(untitled)'));
            if ($title === '') {
                $title = '(untitled)';
            }
            $xml .= '<title>' . self::escapeXmlText($title) . '</title>';

            $link = self::sanitizeLinkUrl((string)($e['link'] ?? ''));
            if ($link !== '') {
                $xml .= '<link>' . self::escapeXmlText($link) . '</link>';
            }

            if (!empty($e['published_date'])) {
                $xml .= '<published_date>' . self::escapeXmlText((string)$e['published_date']) . '</published_date>';
            }
            if (!empty($e['source_name'])) {
                $xml .= '<source_name>' . self::escapeXmlText((string)$e['source_name']) . '</source_name>';
            }
            if (!empty($e['source_type'])) {
                $xml .= '<source_type>' . self::escapeXmlText((string)$e['source_type']) . '</source_type>';
            }
            $jurisdiction = self::jurisdictionLabel($e);
            if ($jurisdiction !== '') {
                $xml .= '<jurisdiction>' . self::escapeXmlText($jurisdiction) . '</jurisdiction>';
            }
            if (!empty($e['source_category'])) {
                $xml .= '<category>' . self::escapeXmlText((string)$e['source_category']) . '</category>';
            }
            if ($score !== null) {
                $rel = (float)($score['relevance_score'] ?? 0);
                $lbl = (string)($score['predicted_label'] ?? '');
                $src = (string)($score['score_source'] ?? '');
                $xml .= '<relevance_score>' . self::escapeXmlText(sprintf('%.2f', $rel)) . '</relevance_score>';
                if ($lbl !== '') {
                    $xml .= '<predicted_label>' . self::escapeXmlText($lbl) . '</predicted_label>';
                }
                if ($src !== '') {
                    $xml .= '<score_source>' . self::escapeXmlText($src) . '</score_source>';
                }
            }

            $body = self::formatEntryBody($e, self::resolveEntryBodyMaxChars($meta));
            if ($body !== '') {
                $xml .= '<content>' . self::escapeXmlText($body) . '</content>';
            }

            $xml .= '</entry>';
        }

        return $xml . '</entries></seismo_briefing>';
    }

    private static function escapeXmlAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function escapeXmlText(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $meta Briefing gather meta; optional `entry_body_max_chars`.
     */
    private static function resolveEntryBodyMaxChars(array $meta): int
    {
        $raw = $meta['entry_body_max_chars'] ?? null;
        if (is_int($raw)) {
            return max(64, min(self::ENTRY_BODY_MAX_CHARS, $raw));
        }
        $s = trim((string)($raw ?? ''));
        if ($s !== '' && ctype_digit($s)) {
            return max(64, min(self::ENTRY_BODY_MAX_CHARS, (int)$s));
        }

        return self::ENTRY_BODY_MAX_CHARS;
    }

    /**
     * @param array<string, mixed> $entry Shaped Magnitu-contract row.
     */
    private static function formatEntryBody(array $entry, int $maxChars = self::ENTRY_BODY_MAX_CHARS): string
    {
        $lexSource = self::lexSourceFromEntry($entry);
        if ($lexSource !== null && in_array($lexSource, self::LEX_BRIEFING_BODY_SOURCES, true)) {
            $legal = LexCardPreview::briefingText(self::entryAsLexRow($entry, $lexSource));
            if ($legal !== '') {
                return LexPlainText::truncate($legal, $maxChars) ?? '';
            }
        }

        $body = trim((string)($entry['content'] ?? ''));
        if ($body === '') {
            $body = trim((string)($entry['description'] ?? ''));
        }
        if ($body === '') {
            return '';
        }

        $body = LexPlainText::normalize($body);

        return LexPlainText::truncate($body, $maxChars) ?? '';
    }

    /**
     * @param array<string, mixed> $entry
     * @return array{source: string, description: string, content: string}
     */
    private static function entryAsLexRow(array $entry, string $lexSource): array
    {
        return [
            'source'      => $lexSource,
            'description' => (string)($entry['description'] ?? ''),
            'content'     => (string)($entry['content'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function lexSourceFromEntry(array $entry): ?string
    {
        if ((string)($entry['entry_type'] ?? '') !== 'lex_item') {
            return null;
        }
        $sourceType = (string)($entry['source_type'] ?? '');
        if (!str_starts_with($sourceType, 'lex_')) {
            return null;
        }

        $source = substr($sourceType, 4);

        return $source !== '' ? $source : null;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function jurisdictionLabel(array $entry): string
    {
        $lexSource = self::lexSourceFromEntry($entry);
        if ($lexSource !== null) {
            return match ($lexSource) {
                'de' => 'DE',
                'fr' => 'FR',
                'eu' => 'EU',
                'ch', 'ch_bger', 'ch_bge', 'ch_bvger' => 'CH',
                default => strtoupper($lexSource),
            };
        }

        if ((string)($entry['entry_type'] ?? '') === 'calendar_event') {
            $sourceType = strtolower((string)($entry['source_type'] ?? ''));
            if (str_contains($sourceType, 'parliament_ch') || str_contains($sourceType, 'ch')) {
                return 'CH';
            }
        }

        return '';
    }

    /**
     * Strip characters that would break `[text](url)` syntax or smuggle
     * additional Markdown. Newlines collapse to spaces.
     */
    private static function sanitizeLinkText(string $raw): string
    {
        $t = str_replace(['[', ']', "\r", "\n"], [' ', ' ', ' ', ' '], $raw);
        $t = (string)preg_replace('/\s+/', ' ', $t);

        return trim($t);
    }

    /**
     * Drop characters that would terminate `(url)` early or inject newlines.
     * Not a full URL validator — upstream already restricts to http(s).
     */
    private static function sanitizeLinkUrl(string $raw): string
    {
        $u = str_replace([')', '(', "\r", "\n", ' '], ['', '', '', '', '%20'], $raw);

        return trim($u);
    }
}
