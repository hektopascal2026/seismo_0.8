<?php

declare(strict_types=1);

namespace Seismo\Core\Lex;

/**
 * Dashboard / Lex page card body from synopsis + a lightweight content excerpt.
 *
 * Full corpus stays in lex_items.content; this only shapes what operators see.
 */
final class LexCardPreview
{
    /** Max {@code content} bytes loaded for preview heuristics (timeline SQL SUBSTRING). */
    public const TIMELINE_EXCERPT_CHARS = 8192;

    public const CARD_PREVIEW_CHARS = 300;

    /**
     * @param array<string, mixed> $row
     */
    public static function previewText(array $row): string
    {
        $source = strtolower(trim((string)($row['source'] ?? '')));
        $description = trim((string)($row['description'] ?? ''));
        $excerpt = self::excerptFromRow($row);

        return match ($source) {
            'eu' => self::euPreamble($description, $excerpt),
            'fr' => self::frSummary($description, $excerpt),
            'de' => self::deLead($description, $excerpt),
            default => self::defaultPreview($description, $excerpt),
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function excerptFromRow(array $row): string
    {
        foreach (['content_excerpt', 'content'] as $key) {
            $raw = trim((string)($row[$key] ?? ''));
            if ($raw !== '') {
                return LexPlainText::normalize($raw);
            }
        }

        return '';
    }

    private static function euPreamble(string $description, string $excerpt): string
    {
        if ($excerpt === '') {
            return $description;
        }

        $cut = strlen($excerpt);
        $patterns = [
            '/\n\s*Article\s+1\b/ui',
            '/\n\s*Artikel\s+1\b/ui',
            '/\n\s*Article\s+1[\.\—\-]/ui',
            '/\n\s*Artikel\s+1[\.\—\-]/ui',
            '/\n\s*HAVE ADOPTED\b/ui',
            '/\n\s*HABEN FOLGENDES\b/ui',
            '/\n\s*CHAPTER\s+I\b/ui',
            '/\n\s*KAPITEL\s+I\b/ui',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $excerpt, $match, PREG_OFFSET_CAPTURE)) {
                $cut = min($cut, (int)$match[0][1]);
            }
        }

        $preamble = trim(substr($excerpt, 0, $cut));
        if ($preamble !== '' && mb_strlen($preamble) >= 80) {
            return $preamble;
        }

        return $description !== '' ? $description : self::lead($excerpt, 600);
    }

    private static function frSummary(string $description, string $excerpt): string
    {
        if ($description !== '') {
            return $description;
        }

        return self::lead($excerpt, 600);
    }

    private static function deLead(string $description, string $excerpt): string
    {
        if ($description !== '') {
            return $description;
        }

        return self::lead(self::deBodyFromExcerpt($excerpt), 450);
    }

    /**
     * Drop BGBl PDF masthead (Bundesgesetzblatt / Teil / Nr. / title block) before preview.
     */
    private static function deBodyFromExcerpt(string $excerpt): string
    {
        if (!self::looksLikeBgblPdfText($excerpt)) {
            return $excerpt;
        }

        $start = self::deBgblBodyOffset($excerpt);
        if ($start <= 0) {
            return $excerpt;
        }

        $body = trim(substr($excerpt, $start));

        return ($body !== '' && mb_strlen($body) >= 40) ? $body : $excerpt;
    }

    private static function looksLikeBgblPdfText(string $excerpt): bool
    {
        $head = substr($excerpt, 0, 2000);

        return (bool) preg_match('/^(?:Bundesgesetzblatt\b|BGBl\.)/ui', $excerpt)
            || str_contains($head, 'Ausgegeben zu Bonn');
    }

    private static function deBgblBodyOffset(string $excerpt): int
    {
        $offset = strlen($excerpt);
        $patterns = [
            '/\n\s*(?:Auf Grund des|Aufgrund des)\b/ui',
            '/\n\s*Der Bundestag hat\b/ui',
            '/\n\s*Der Bundesrat hat\b/ui',
            '/\n\s*(?:Es verordnet|Es wird verordnet)\s*:/ui',
            '/\n\s*Die Bevollmächtigte der Bundesregierung\b/ui',
            '/\n\s*Die Bundesregierung verordnet\b/ui',
            '/\n\s*Art(?:ikel|\.)?\s*1(?:[\s\.]|$)/ui',
            '/\n\s*§\s*1(?:[\s\.]|$)/ui',
            '/\n\s*Diese Verordnung tritt\b/ui',
            '/\n\s*Anlage\b/ui',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $excerpt, $match, PREG_OFFSET_CAPTURE)) {
                $offset = min($offset, (int)$match[0][1]);
            }
        }

        if ($offset === strlen($excerpt)
            && preg_match('/\nVom \d{1,2}\. \p{L}+\s+\d{4}\s*\n/u', $excerpt, $match, PREG_OFFSET_CAPTURE)
            && (int)$match[0][1] < 3000
        ) {
            $offset = min($offset, (int)$match[0][1] + strlen($match[0][0]));
        }

        return $offset === strlen($excerpt) ? 0 : $offset;
    }

    private static function defaultPreview(string $description, string $excerpt): string
    {
        if ($description !== '') {
            return $description;
        }

        return self::lead($excerpt, 500);
    }

    private static function lead(string $excerpt, int $maxChars): string
    {
        $excerpt = trim($excerpt);
        if ($excerpt === '') {
            return '';
        }
        if (mb_strlen($excerpt) <= $maxChars) {
            return $excerpt;
        }

        return rtrim(mb_substr($excerpt, 0, $maxChars)) . '…';
    }
}
