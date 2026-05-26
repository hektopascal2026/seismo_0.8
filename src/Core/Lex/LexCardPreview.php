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

    /** Max plain-text body sent to the AI Briefing Builder for DE/FR/EU lex items. */
    public const BRIEFING_BODY_CHARS = 6000;

    /**
     * Plain-text legal body for AI briefing context (prefers operative text over synopsis).
     *
     * @param array<string, mixed> $row Row with `source`, `description`, and/or `content`
     */
    public static function briefingText(array $row, int $maxChars = self::BRIEFING_BODY_CHARS): string
    {
        $source = strtolower(trim((string)($row['source'] ?? '')));
        $description = trim((string)($row['description'] ?? ''));
        $excerpt = self::excerptFromRow($row);

        $body = match ($source) {
            'eu' => self::briefingEuBody($description, $excerpt),
            'fr' => self::briefingFrBody($description, $excerpt),
            'de' => self::briefingDeBody($description, $excerpt),
            'ch' => self::briefingChBody($description, $excerpt),
            default => $description !== ''
                ? self::plainExcerpt($description)
                : self::plainExcerpt($excerpt),
        };

        $body = trim($body);

        return $body === '' ? '' : self::lead($body, $maxChars);
    }

    private static function briefingEuBody(string $description, string $excerpt): string
    {
        if ($excerpt !== '') {
            $fromExcerpt = self::euPreamble($description, $excerpt);
            if ($fromExcerpt !== '' && mb_strlen($fromExcerpt) >= 120) {
                return $fromExcerpt;
            }
            $body = self::euBodyFromExcerpt(self::plainExcerpt($excerpt));
            if ($body !== '' && mb_strlen($body) >= 80) {
                return $body;
            }
        }

        return $description !== '' ? self::plainExcerpt($description) : self::plainExcerpt($excerpt);
    }

    private static function briefingFrBody(string $description, string $excerpt): string
    {
        if ($excerpt !== '') {
            $body = self::frBodyFromExcerpt($excerpt);
            if ($body !== '' && mb_strlen($body) >= 40) {
                return $body;
            }
        }

        $description = self::frTrimTravauxPreparatoires(self::plainExcerpt($description));
        if ($description !== '' && !self::frIsBoilerplateOnly($description)) {
            return $description;
        }

        return self::plainExcerpt($excerpt);
    }

    private static function briefingDeBody(string $description, string $excerpt): string
    {
        if ($excerpt !== '') {
            $body = self::deBodyFromExcerpt(self::plainExcerpt($excerpt));
            if ($body !== '' && mb_strlen($body) >= 80) {
                return $body;
            }
        }

        if ($description !== '') {
            return self::plainExcerpt($description);
        }

        return self::plainExcerpt($excerpt);
    }

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
            'ch' => self::chSummary($description, $excerpt),
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
                return self::plainExcerpt($raw);
            }
        }

        return '';
    }

    private static function plainExcerpt(string $raw): string
    {
        if (str_contains($raw, '<') && preg_match('/<[a-z][\s\S]*>/i', $raw)) {
            return LexPlainText::fromHtml($raw);
        }

        return LexPlainText::normalize($raw);
    }

    private static function euPreamble(string $description, string $excerpt): string
    {
        if ($excerpt === '') {
            return $description;
        }

        $excerpt = self::euBodyFromExcerpt($excerpt);

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

    /**
     * Drop EUR-Lex instrument title block (e.g. "COMMISSION … REGULATION (EU) … of …") before preamble.
     */
    private static function euBodyFromExcerpt(string $excerpt): string
    {
        if (!self::looksLikeEuInstrumentText($excerpt)) {
            return $excerpt;
        }

        $start = self::euTitleBlockOffset($excerpt);
        if ($start <= 0) {
            return $excerpt;
        }

        $body = trim(substr($excerpt, $start));

        return ($body !== '' && mb_strlen($body) >= 40) ? $body : $excerpt;
    }

    private static function looksLikeEuInstrumentText(string $excerpt): bool
    {
        $head = substr($excerpt, 0, 800);

        return (bool) preg_match(
            '/^(?:COMMISSION|COUNCIL|EUROPEAN PARLIAMENT|REGULATION|DIRECTIVE|DECISION)\b/ui',
            $excerpt,
        ) || (bool) preg_match(
            '/\b(?:REGULATION|DIRECTIVE|DECISION)\s*\((?:EU|EC|EEC)\)\s+\d{4}\/\d+/ui',
            $head,
        );
    }

    private static function euTitleBlockOffset(string $excerpt): int
    {
        $offset = strlen($excerpt);
        $patterns = [
            '/\nTHE EUROPEAN COMMISSION[,]?\s*\n/ui',
            '/\nTHE COUNCIL(?: OF THE EUROPEAN UNION)?[,]?\s*\n/ui',
            '/\nTHE EUROPEAN PARLIAMENT(?: AND OF THE COUNCIL)?[,]?\s*\n/ui',
            '/\nTHE EUROPEAN PARLIAMENT AND THE COUNCIL[,]?\s*\n/ui',
            '/\nHaving regard to\b/ui',
            '/\nWhereas:\s*\n/ui',
            '/\nWhereas\s*\n/ui',
            '/\nDie Europäische Kommission[,]?\s*\n/ui',
            '/\nLa Commission européenne[,]?\s*\n/ui',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $excerpt, $match, PREG_OFFSET_CAPTURE)) {
                $offset = min($offset, (int)$match[0][1]);
            }
        }

        if ($offset === strlen($excerpt)
            && preg_match('/\n(?:of|du|vom)\s+\d{1,2}\s+\p{L}+\s+\d{4}\s*\n/ui', $excerpt, $match, PREG_OFFSET_CAPTURE)
            && (int)$match[0][1] < 1500
        ) {
            $offset = min($offset, (int)$match[0][1] + strlen($match[0][0]));
        }

        return $offset === strlen($excerpt) ? 0 : $offset;
    }

    private static function frSummary(string $description, string $excerpt): string
    {
        $description = self::frTrimTravauxPreparatoires(self::plainExcerpt($description));
        if ($description !== '' && !self::frIsBoilerplateOnly($description)) {
            return $description;
        }

        return self::lead(self::frBodyFromExcerpt($excerpt), 600);
    }

    /**
     * Drop JORF promulgation block, instrument title line, and travaux préparatoires footnotes.
     */
    private static function frBodyFromExcerpt(string $excerpt): string
    {
        $excerpt = self::frTrimTravauxPreparatoires(self::plainExcerpt($excerpt));
        if ($excerpt === '') {
            return '';
        }

        if (!self::looksLikeFrenchJorfText($excerpt)) {
            return $excerpt;
        }

        $start = self::frBodyOffset($excerpt);
        if ($start > 0) {
            $body = trim(substr($excerpt, $start));
        } else {
            $body = self::frStripKnownJorfHeaders($excerpt);
        }

        $body = self::frTrimTravauxPreparatoires($body);
        if ($body === '' || self::frIsBoilerplateOnly($body)) {
            return '';
        }

        if (mb_strlen($body) < 40 && !preg_match('/\b(?:Article|Art\.)\s/ui', $body)) {
            return '';
        }

        return $body;
    }

    private static function frStripKnownJorfHeaders(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $text = preg_replace(
            '/^.*?(?:promulgue la loi dont la teneur suit|promulgue l[\x{2019}\']ordonnance dont la teneur suit)\s*:\s*\n/ius',
            '',
            $text,
        ) ?? $text;

        do {
            $before = $text;
            $text = preg_replace(
                '/^(?:LOI|DÉCRET|ORDONNANCE|ARRÊTÉ)\s+n[°o]\s+\d{4}-\d+[^\n]*\n+/iu',
                '',
                trim($text),
            ) ?? trim($text);
        } while ($text !== $before && $text !== '');

        return trim($text);
    }

    private static function frIsBoilerplateOnly(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return true;
        }

        if (preg_match(
            '/\b(?:promulgue la loi dont la teneur suit|Assemblée nationale et le Sénat ont adopté)\b/ui',
            $text,
        ) && !preg_match('/\b(?:Article\s|Art\.\s|Exposé des motifs)\b/ui', $text)) {
            return true;
        }

        if (preg_match('/^(?:LOI|DÉCRET|ORDONNANCE|ARRÊTÉ)\s+n[°o]\s+\d{4}-\d+\b/iu', $text)) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $text))));
            if ($lines === [] || (count($lines) <= 2 && !preg_match('/\b(?:Article\s|Art\.\s)\b/ui', $text))) {
                return true;
            }
        }

        return (bool) preg_match(
            '/\b(?:promulgue la loi dont la teneur suit|Assemblée nationale et le Sénat)\b/ui',
            $text,
        ) && mb_strlen($text) < 220;
    }

    private static function looksLikeFrenchJorfText(string $excerpt): bool
    {
        $head = substr($excerpt, 0, 4000);

        return (bool) preg_match(
            '/\b(?:Assemblée nationale|promulgue la loi|Travaux préparatoires)\b/ui',
            $head,
        ) || (bool) preg_match(
            '/\b(?:LOI|DÉCRET|ORDONNANCE|ARRÊTÉ)\s+n[°o]\s+\d+/ui',
            $head,
        );
    }

    private static function frBodyOffset(string $excerpt): int
    {
        $offset = strlen($excerpt);
        $patterns = [
            '/\n\s*Article\s+(?:1er|premier|1\s+er|1(?:[\s\.\—\-]|$))/ui',
            '/\n\s*Art\.?\s+(?:1er|premier|1(?:[\s\.\—\-]|$))/ui',
            '/\n\s*(?:Exposé des motifs|EXPOSÉ DES MOTIFS)\b/ui',
            '/\n\s*Chapitre\s+(?:I|1\b|premier)/ui',
            '/\n\s*Titre\s+(?:I|1\b|premier)/ui',
            '/\n\s*Section\s+(?:I|1\b|première)/ui',
            '/\n\s*Partie\s+(?:I|1\b|première)/ui',
            '/\n\s*Livre\s+(?:I|1\b|premier)/ui',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $excerpt, $match, PREG_OFFSET_CAPTURE)) {
                $offset = min($offset, (int)$match[0][1]);
            }
        }

        if ($offset === strlen($excerpt)
            && preg_match(
                '/(?:promulgue la loi dont la teneur suit|promulgue l[\x{2019}\']ordonnance dont la teneur suit)\s*:\s*\n/iu',
                $excerpt,
                $match,
                PREG_OFFSET_CAPTURE,
            )
        ) {
            $after = (int)$match[0][1] + strlen($match[0][0]);
            $rest = substr($excerpt, $after);
            if (preg_match(
                '/^(?:LOI|DÉCRET|ORDONNANCE|ARRÊTÉ)\s+n[°o]\s+\d{4}-\d+[^\n]*\n/iu',
                $rest,
                $titleMatch,
            )) {
                $after += strlen($titleMatch[0]);
            }
            $offset = min($offset, $after);
        }

        if ($offset === strlen($excerpt)
            && preg_match(
                '/\n(?:LOI|DÉCRET|ORDONNANCE|ARRÊTÉ)\s+n[°o]\s+\d{4}-\d+[^\n]*\n/iu',
                $excerpt,
                $match,
                PREG_OFFSET_CAPTURE,
            )
            && (int)$match[0][1] < 4000
        ) {
            $offset = min($offset, (int)$match[0][1] + strlen($match[0][0]));
        }

        return $offset === strlen($excerpt) ? 0 : $offset;
    }

    private static function frTrimTravauxPreparatoires(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        if (preg_match('/\n\s*\(\d+\)\s*Travaux préparatoires\b/ui', $text, $match, PREG_OFFSET_CAPTURE)) {
            $text = trim(substr($text, 0, (int)$match[0][1]));
        } elseif (preg_match('/\n\s*Travaux préparatoires\s*:\s*\n/ui', $text, $match, PREG_OFFSET_CAPTURE)) {
            $text = trim(substr($text, 0, (int)$match[0][1]));
        }

        return $text;
    }

    private static function deLead(string $description, string $excerpt): string
    {
        if ($description !== '') {
            return $description;
        }

        return self::lead(self::deBodyFromExcerpt($excerpt), 450);
    }

    private static function briefingChBody(string $description, string $excerpt): string
    {
        if ($excerpt !== '') {
            $body = self::chBodyFromExcerpt($excerpt);
            if ($body !== '' && mb_strlen($body) >= 80) {
                return $body;
            }
        }

        return $description !== '' ? self::plainExcerpt($description) : self::plainExcerpt($excerpt);
    }

    private static function chSummary(string $description, string $excerpt): string
    {
        $body = self::chBodyFromExcerpt($excerpt);

        if ($description === '') {
            return self::lead($body, 500);
        }

        if ($body === '' || mb_strlen($body) < 40) {
            return $description;
        }

        $descPlain = self::plainExcerpt($description);
        if ($body === $descPlain || str_starts_with($body, $descPlain)) {
            return $description;
        }

        return $description . "\n\n" . self::lead($body, 450);
    }

    /**
     * Akoma Ntoso AS/RO: preface/preamble plus amendment levels (I., II., …).
     */
    private static function chBodyFromExcerpt(string $excerpt): string
    {
        $excerpt = self::plainExcerpt($excerpt);
        if ($excerpt === '') {
            return '';
        }

        $chunks = [];
        if (preg_match('/^(.*?\n\n)(?=I[\n\s]|I\.)/us', $excerpt, $m)) {
            $head = trim($m[1]);
            if ($head !== '') {
                $chunks[] = $head;
            }
            $rest = trim(substr($excerpt, strlen($m[0])));
            if ($rest !== '') {
                $chunks[] = $rest;
            }
        } else {
            $chunks[] = $excerpt;
        }

        $plain = trim(implode("\n\n", $chunks));

        return ($plain !== '' && mb_strlen($plain) >= 40) ? $plain : '';
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
