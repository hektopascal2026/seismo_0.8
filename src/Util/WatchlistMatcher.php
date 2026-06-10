<?php

declare(strict_types=1);

namespace Seismo\Util;

/**
 * Cross-reference helper: match entry text against a user-supplied person/entity list.
 */
final class WatchlistMatcher
{
    /** @var list<string> */
    private array $terms;

    private string $regexPattern;

    /**
     * @param list<string> $terms Normalized search terms, longest first.
     */
    private function __construct(array $terms)
    {
        $this->terms = $terms;
        $this->regexPattern = self::compileRegexPattern($terms);
    }

    public static function fromContent(string $content): self
    {
        return new self(self::parseTermsFromContent($content));
    }

    /** @var list<string> */
    private const SWISSMEM_MANUAL_TERMS = [
        'Swissmem',
        'ABB',
        'SFS',
        'Bühler',
        'Stadler Rail',
        'Schindler',
        'Siemens',
        'Kuhn Rikon',
        'RUAG',
        'CSEM',
        'VAT Group',
    ];

    public static function fromBuiltInSwissmemFile(): self
    {
        $terms = self::parseTermsFromContent(self::builtInSwissmemPlaintext());
        $seen = array_fill_keys($terms, true);
        foreach (self::SWISSMEM_MANUAL_TERMS as $manual) {
            $normalized = self::normalizeTerm($manual);
            if ($normalized !== '' && !isset($seen[$normalized])) {
                $terms[] = $normalized;
                $seen[$normalized] = true;
            }
        }
        usort($terms, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        return new self($terms);
    }

    public static function builtInSwissmemPlaintext(): string
    {
        $filePath = SEISMO_ROOT . '/swissmem-list.html';
        if (!is_file($filePath)) {
            return '';
        }

        $html = file_get_contents($filePath);
        if ($html === false) {
            return '';
        }

        if (!preg_match_all('/<pre\b[^>]*>(.*?)<\/pre>/is', $html, $matches)) {
            return '';
        }

        $lines = [];
        foreach ($matches[1] as $preBlock) {
            foreach (explode("\n", $preBlock) as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    public function terms(): array
    {
        return $this->terms;
    }

    public function termCount(): int
    {
        return count($this->terms);
    }

    public function regexPattern(): string
    {
        return $this->regexPattern;
    }

    public function matches(string $text): bool
    {
        if (trim($text) === '') {
            return false;
        }

        return (bool)preg_match($this->regexPattern, $text);
    }

    /**
     * @param array<string, mixed> $entry Shaped Magnitu export row.
     */
    public function matchesShapedEntry(array $entry): bool
    {
        foreach (self::textFieldsFromShapedEntry($entry) as $text) {
            if ($this->matches($text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $item Timeline row.
     */
    public function matchesTimelineItem(array $item): bool
    {
        foreach (self::textFieldsFromTimelineItem($item) as $text) {
            if ($this->matches($text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string> Distinct matched terms (longest first).
     */
    public function matchedTermsInText(string $text): array
    {
        if (trim($text) === '' || $this->terms === []) {
            return [];
        }

        $found = [];
        foreach ($this->terms as $term) {
            $pattern = self::termPattern($term);
            if ($pattern !== null && preg_match($pattern, $text)) {
                $found[$term] = true;
            }
        }

        $result = array_keys($found);
        usort($result, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        return $result;
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    public function matchedTermsInShapedEntry(array $entry): array
    {
        $found = [];
        foreach (self::textFieldsFromShapedEntry($entry) as $text) {
            foreach ($this->matchedTermsInText($text) as $term) {
                $found[$term] = true;
            }
        }

        $result = array_keys($found);
        usort($result, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        return $result;
    }

    public static function truncateForPrompt(string $content, int $maxChars = 20_000): string
    {
        $content = trim($content);
        if ($content === '' || strlen($content) <= $maxChars) {
            return $content;
        }

        return substr($content, 0, $maxChars)
            . "\n\n[Hinweis: Watchlist gekürzt für den Prompt. Verifikation gilt weiterhin für alle gelisteten Entitäten im vollständigen Monitor-Input.]";
    }

    /**
     * @return list<string>
     */
    public static function parseTermsFromContent(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        if (str_contains($content, '<pre')) {
            if (preg_match_all('/<pre\b[^>]*>(.*?)<\/pre>/is', $content, $matches)) {
                $lines = [];
                foreach ($matches[1] as $preBlock) {
                    foreach (explode("\n", $preBlock) as $line) {
                        $line = trim($line);
                        if ($line !== '') {
                            $lines[] = $line;
                        }
                    }
                }
                $content = implode("\n", $lines);
            }
        }

        $terms = [];
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (str_contains($line, '|')) {
                foreach (explode('|', $line) as $part) {
                    foreach (explode(';', $part) as $sub) {
                        $normalized = self::normalizeTerm(trim($sub));
                        if ($normalized !== '') {
                            $terms[$normalized] = true;
                        }
                    }
                }
                continue;
            }

            $normalized = self::normalizeTerm($line);
            if ($normalized !== '') {
                $terms[$normalized] = true;
            }
        }

        $result = array_keys($terms);
        usort($result, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        return $result;
    }

    private static function normalizeTerm(string $term): string
    {
        $term = html_entity_decode($term, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $term = preg_replace(
            '/^(Dr\.\s*sc\.\s*techn\.|Dr\.sc\.|Dr\.|Prof\.|P\.|Michael\s+K\.)\s+/ui',
            '',
            $term,
        );
        
        $stripped = preg_replace(
            '/\s+(AG|SA|GmbH|Ltd\.?|Limited|SNC|S\.A\.|A\.G\.|Group|Group\s+SA|Holding|Holding\s+SA|Holding\s+AG|Management\s+AG|Management|Switzerland|Schweiz)\b/ui',
            '',
            $term,
        );

        $trimmedStripped = trim($stripped);
        $protectedNames = [
            'stefan', 'stephan', 'peter', 'michael', 'thomas', 'markus',
            'andreas', 'christian', 'martin', 'rolf', 'daniel', 'walter',
            'hans', 'beat', 'urs',
        ];

        if (in_array(strtolower($trimmedStripped), $protectedNames, true)) {
            // Keep the original term to avoid over-broad single first name matches
        } else {
            $term = $stripped;
        }

        $term = trim($term);
        if (strlen($term) < 3) {
            return '';
        }

        return $term;
    }

    /** @var list<string> */
    private const BLACKLIST_TERMS = [
        'libs',
        'aero',
        'dyno',
    ];

    /** @var list<string> */
    private const CASE_SENSITIVE_ACRONYMS = [
        'VAT',
        'NEXT',
        'NUM',
        'ASS',
        'ABB',
        'SFS',
        'LEM',
        'SPM',
        'GIS',
        'IAR',
        'PWB',
        'RWM',
        'SQS',
    ];

    /**
     * @param list<string> $terms
     */
    private static function compileRegexPattern(array $terms): string
    {
        if ($terms === []) {
            return '/$foo/';
        }

        $caseSensitive = [];
        $caseInsensitive = [];

        foreach ($terms as $t) {
            $lower = strtolower($t);
            if (in_array($lower, self::BLACKLIST_TERMS, true)) {
                continue;
            }

            if (in_array($t, self::CASE_SENSITIVE_ACRONYMS, true)) {
                $caseSensitive[] = preg_quote($t, '/');
            } else {
                $caseInsensitive[] = preg_quote($t, '/');
            }
        }

        $patterns = [];
        if ($caseInsensitive !== []) {
            $patterns[] = '(?i)\b(' . implode('|', $caseInsensitive) . ')\b(?-i)';
        }
        if ($caseSensitive !== []) {
            $patterns[] = '\b(' . implode('|', $caseSensitive) . ')\b';
        }

        if ($patterns === []) {
            return '/$foo/';
        }

        return '/' . implode('|', $patterns) . '/u';
    }

    private static function termPattern(string $term): ?string
    {
        $lower = strtolower($term);
        if (in_array($lower, self::BLACKLIST_TERMS, true)) {
            return null;
        }

        $quoted = preg_quote($term, '/');
        if (in_array($term, self::CASE_SENSITIVE_ACRONYMS, true)) {
            return '/\b(' . $quoted . ')\b/u';
        }

        return '/(?i)\b(' . $quoted . ')\b(?-i)/u';
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    private static function textFieldsFromShapedEntry(array $entry): array
    {
        return [
            (string)($entry['title'] ?? ''),
            (string)($entry['description'] ?? ''),
            (string)($entry['content'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return list<string>
     */
    private static function textFieldsFromTimelineItem(array $item): array
    {
        $data = is_array($item['data'] ?? null) ? $item['data'] : [];

        return [
            (string)($data['title'] ?? $data['subject'] ?? $data['derived_title'] ?? ''),
            (string)($data['description'] ?? $data['content_excerpt'] ?? ''),
            (string)($data['content'] ?? $data['text_body'] ?? ''),
        ];
    }
}
