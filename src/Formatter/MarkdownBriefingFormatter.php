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

final class MarkdownBriefingFormatter
{
    public const CONTENT_TYPE = 'text/markdown; charset=utf-8';

    /**
     * @param array<int, array<string, mixed>> $entries Shaped Magnitu-contract rows.
     * @param array<string, array<string, mixed>> $scoresByKey "type:id" → score row.
     * @param array<string, mixed> $meta               Printed in the preamble (since, total, etc.).
     */
    public static function format(array $entries, array $scoresByKey, array $meta): string
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
        if (isset($meta['label_filter']) && $meta['label_filter'] !== null) {
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

                $desc = trim((string)($e['description'] ?? ''));
                if ($desc !== '') {
                    $desc = (string)preg_replace('/\s+/', ' ', $desc);
                    $desc = mb_substr($desc, 0, 600);
                    $lines[] = '  - ' . $desc;
                }
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
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
