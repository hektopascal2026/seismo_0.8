<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

/**
 * Canonical digest_split_config shape for EmailDigestSplitterService + ingest gate (is_digest).
 */
final class DigestSplitConfigNormalizer
{
    /**
     * @param array<string, mixed> $raw
     * @return ?array{is_digest: true, split_rules: array<string, mixed>}
     */
    public static function normalize(array $raw): ?array
    {
        if ($raw === []) {
            return null;
        }

        $isDigest = !empty($raw['is_digest']);
        $rules = $raw['split_rules'] ?? null;
        if (!is_array($rules)) {
            $rules = $raw;
        }

        $method = trim((string)($rules['split_method'] ?? $rules['type'] ?? ''));
        if ($method === 'html_css' || $method === 'html_selector') {
            $method = 'html_selector';
        } elseif ($method === 'regex' || $method === 'regex_split') {
            $method = 'regex_split';
        }

        if ($method === '') {
            return $isDigest ? null : null;
        }

        $isDigest = $isDigest || $method !== '';

        if ($method === 'html_selector') {
            $normalized = [
                'split_method' => 'html_selector',
                'story_selector' => trim((string)($rules['story_selector'] ?? $rules['selector_story'] ?? '')),
                'title_selector' => trim((string)($rules['title_selector'] ?? $rules['selector_title'] ?? '')),
                'link_selector' => trim((string)($rules['link_selector'] ?? $rules['selector_link'] ?? '')),
                'body_selector' => trim((string)($rules['body_selector'] ?? $rules['selector_body'] ?? '')),
            ];
            if ($normalized['story_selector'] === '') {
                return null;
            }

            $excludes = self::normalizeStringList($rules['exclude_selectors'] ?? []);
            if ($excludes !== []) {
                $normalized['exclude_selectors'] = $excludes;
            }

            return ['is_digest' => true, 'split_rules' => $normalized];
        }

        if ($method === 'regex_split') {
            $normalized = [
                'split_method' => 'regex_split',
                'split_pattern' => trim((string)($rules['split_pattern'] ?? $rules['pattern_split'] ?? '')),
                'title_pattern' => trim((string)($rules['title_pattern'] ?? $rules['pattern_title'] ?? '')),
                'link_pattern' => trim((string)($rules['link_pattern'] ?? $rules['pattern_link'] ?? '')),
                'body_pattern' => trim((string)($rules['body_pattern'] ?? $rules['pattern_body'] ?? '')),
            ];
            if ($normalized['split_pattern'] === '') {
                return null;
            }

            return ['is_digest' => true, 'split_rules' => $normalized];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<int>
     */
    public static function expectedCountsFromAnalysis(array $config): array
    {
        $analysis = $config['analysis'] ?? null;
        if (!is_array($analysis)) {
            return [];
        }

        $samples = $analysis['samples'] ?? null;
        if (!is_array($samples)) {
            return [];
        }

        $counts = [];
        foreach ($samples as $sample) {
            if (!is_array($sample)) {
                continue;
            }
            $count = $sample['expected_card_count'] ?? null;
            if (is_int($count)) {
                $counts[] = $count;
            } elseif (is_numeric($count)) {
                $counts[] = (int)$count;
            }
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    private static function normalizeStringList(mixed $val): array
    {
        if (!is_array($val)) {
            return [];
        }

        $out = [];
        foreach ($val as $item) {
            $str = trim((string)$item);
            if ($str !== '') {
                $out[] = $str;
            }
        }

        return $out;
    }
}
