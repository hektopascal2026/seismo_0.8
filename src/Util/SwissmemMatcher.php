<?php

declare(strict_types=1);

namespace Seismo\Util;

final class SwissmemMatcher
{
    /** @var array<int, string>|null */
    private static ?array $terms = null;
    
    private static ?string $regexPattern = null;

    /**
     * Parse swissmem-list.html and compile the matching terms.
     * Caches in memory for the lifetime of the request.
     *
     * @return array<int, string>
     */
    public static function getTerms(): array
    {
        if (self::$terms !== null) {
            return self::$terms;
        }

        $filePath = SEISMO_ROOT . '/swissmem-list.html';
        if (!file_exists($filePath)) {
            self::$terms = [];
            return [];
        }

        $html = file_get_contents($filePath);
        if ($html === false) {
            self::$terms = [];
            return [];
        }

        if (!preg_match_all('/<pre\b[^>]*>(.*?)<\/pre>/is', $html, $matches)) {
            self::$terms = [];
            return [];
        }

        $rawLines = [];
        foreach ($matches[1] as $preBlock) {
            $lines = explode("\n", $preBlock);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $rawLines[] = $line;
                }
            }
        }

        $terms = [];
        foreach ($rawLines as $line) {
            if (str_contains($line, '|')) {
                $parts = explode('|', $line);
                foreach ($parts as $part) {
                    $subparts = explode(';', $part);
                    foreach ($subparts as $sub) {
                        $term = trim($sub);
                        if ($term !== '') {
                            $normalized = self::normalizeTerm($term);
                            if ($normalized !== '') {
                                $terms[$normalized] = true;
                            }
                        }
                    }
                }
            } else {
                $normalized = self::normalizeTerm($line);
                if ($normalized !== '') {
                    $terms[$normalized] = true;
                }
            }
        }

        $manual = ['Swissmem', 'ABB', 'SFS', 'Bühler', 'Stadler Rail', 'Schindler', 'Siemens', 'Kuhn Rikon', 'RUAG', 'CSEM', 'VAT Group'];
        foreach ($manual as $m) {
            $terms[self::normalizeTerm($m)] = true;
        }

        $result = array_keys($terms);
        usort($result, static fn($a, $b) => strlen($b) <=> strlen($a));

        self::$terms = $result;
        return self::$terms;
    }

    private static function normalizeTerm(string $term): string
    {
        $term = html_entity_decode($term, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        $term = preg_replace('/^(Dr\.\s*sc\.\s*techn\.|Dr\.sc\.|Dr\.|Prof\.|P\.|Michael\s+K\.)\s+/ui', '', $term);
        $term = preg_replace('/\s+(AG|SA|GmbH|Ltd\.?|Limited|SNC|S\.A\.|A\.G\.|Group|Group\s+SA|Holding|Holding\s+SA|Holding\s+AG|Management\s+AG|Management|Switzerland|Schweiz)\b/ui', '', $term);

        $term = trim($term);
        if (strlen($term) < 3) {
            return '';
        }

        return $term;
    }

    public static function getRegexPattern(): string
    {
        if (self::$regexPattern !== null) {
            return self::$regexPattern;
        }

        $terms = self::getTerms();
        if ($terms === []) {
            self::$regexPattern = '/$foo/';
            return self::$regexPattern;
        }

        $quoted = array_map(static fn($t) => preg_quote($t, '/'), $terms);
        self::$regexPattern = '/\b(' . implode('|', $quoted) . ')\b/ui';

        return self::$regexPattern;
    }

    public static function matches(string $text): bool
    {
        if (trim($text) === '') {
            return false;
        }

        $pattern = self::getRegexPattern();
        return (bool)preg_match($pattern, $text);
    }

    /**
     * Checks if a timeline entry contains a Swissmem mention.
     *
     * @param array<string, mixed> $item
     */
    public static function matchesTimelineItem(array $item): bool
    {
        $title = (string)($item['data']['title'] ?? $item['data']['subject'] ?? $item['data']['derived_title'] ?? '');
        if (self::matches($title)) {
            return true;
        }

        $desc = (string)($item['data']['description'] ?? $item['data']['content_excerpt'] ?? '');
        if (self::matches($desc)) {
            return true;
        }

        $content = (string)($item['data']['content'] ?? $item['data']['text_body'] ?? '');
        if (self::matches($content)) {
            return true;
        }

        return false;
    }

    /**
     * Checks if a shaped entry contains a Swissmem mention.
     *
     * @param array<string, mixed> $entry
     */
    public static function matchesShapedEntry(array $entry): bool
    {
        $title = (string)($entry['title'] ?? '');
        if (self::matches($title)) {
            return true;
        }
        $desc = (string)($entry['description'] ?? '');
        if (self::matches($desc)) {
            return true;
        }
        $content = (string)($entry['content'] ?? '');
        if (self::matches($content)) {
            return true;
        }
        return false;
    }
}
