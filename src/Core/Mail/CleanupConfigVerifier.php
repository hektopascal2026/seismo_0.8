<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

use Seismo\Core\Mail\Processor\DynamicRegexEmailProcessor;

/**
 * Dry-run cleanup_config strip_regexes against sample emails; check editor analysis snippets.
 */
final class CleanupConfigVerifier
{
    private const MIN_SNIPPET_LEN = 8;

    /**
     * @param list<array{subject: string, body?: string, text_body?: string, html_body?: string}> $samples
     * @param array{strip_regexes: list<string>, webview_keywords?: list<string>, title_extractor?: ?string} $config
     * @param array<string, mixed> $rawResponse
     * @return array{
     *     verified: bool,
     *     message: string,
     *     issues: list<array{sample_index: int, type: string, snippet: string, description?: string}>
     * }
     */
    public function verify(array $samples, array $config, array $rawResponse = []): array
    {
        $issues = [];

        foreach ($samples as $index => $sample) {
            $original = trim((string)($sample['text_body'] ?? $sample['body'] ?? ''));
            if ($original === '') {
                continue;
            }

            $cleaned = $this->applyStripRegexes($original, $config);
            if ($cleaned === '') {
                $issues[] = [
                    'sample_index' => $index + 1,
                    'type' => 'empty_after_cleanup',
                    'snippet' => mb_substr($original, 0, 80),
                    'description' => 'Cleanup removed all text',
                ];
                continue;
            }

            if (mb_strlen($cleaned) < (int)(mb_strlen($original) * 0.05)) {
                $issues[] = [
                    'sample_index' => $index + 1,
                    'type' => 'over_stripped',
                    'snippet' => mb_substr($original, 0, 80),
                    'description' => 'Less than 5% of original text remains',
                ];
            }

            foreach ($this->snippetsForSample($rawResponse, $index, 'noise', 'must_remove') as $item) {
                if ($this->snippetStillPresent($cleaned, $item['snippet'])) {
                    $issues[] = [
                        'sample_index' => $index + 1,
                        'type' => 'noise_still_present',
                        'snippet' => $item['snippet'],
                        'description' => $item['description'],
                    ];
                }
            }

            foreach ($this->snippetsForSample($rawResponse, $index, 'content', 'must_keep') as $item) {
                if (!$this->snippetStillPresent($cleaned, $item['snippet'])) {
                    $issues[] = [
                        'sample_index' => $index + 1,
                        'type' => 'content_removed',
                        'snippet' => $item['snippet'],
                        'description' => $item['description'],
                    ];
                }
            }
        }

        if ($issues === [] && !$this->hasAnalysisSnippets($rawResponse)) {
            $firstOriginal = trim((string)($samples[0]['text_body'] ?? $samples[0]['body'] ?? ''));
            $firstCleaned = $this->applyStripRegexes($firstOriginal, $config);
            if ($firstOriginal !== '' && $firstCleaned === $firstOriginal) {
                return [
                    'verified' => false,
                    'message' => 'No analysis snippets to verify — strip_regexes did not change sample 1.',
                    'issues' => [],
                ];
            }

            return [
                'verified' => true,
                'message' => 'Cleanup applied on samples (no snippet-level analysis to verify).',
                'issues' => [],
            ];
        }

        $verified = $issues === [];

        return [
            'verified' => $verified,
            'message' => $verified
                ? 'Verified: noise snippets removed and content snippets preserved.'
                : 'Cleanup issues on ' . count($issues) . ' check(s) — rules may need refinement.',
            'issues' => $issues,
        ];
    }

    /**
     * @param array{strip_regexes: list<string>, webview_keywords?: list<string>, title_extractor?: ?string} $config
     */
    public function applyStripRegexes(string $text, array $config): string
    {
        $processor = new DynamicRegexEmailProcessor($config);
        $row = $processor->process([
            'subject' => '',
            'text_body' => $text,
            'body_text' => $text,
        ]);

        return trim((string)($row['text_body'] ?? $row['body_text'] ?? ''));
    }

    /**
     * @param array<string, mixed> $rawResponse
     * @return list<array{snippet: string, description: string}>
     */
    private function snippetsForSample(array $rawResponse, int $sampleIndex, string $listKey, string $flagKey): array
    {
        $analysis = $rawResponse['analysis'] ?? null;
        if (!is_array($analysis)) {
            return [];
        }

        $samples = $analysis['samples'] ?? null;
        if (!is_array($samples)) {
            return [];
        }

        foreach ($samples as $sample) {
            if (!is_array($sample)) {
                continue;
            }
            $idx = (int)($sample['sample_index'] ?? 0) - 1;
            if ($idx !== $sampleIndex) {
                continue;
            }

            $items = $sample[$listKey] ?? [];
            if (!is_array($items)) {
                return [];
            }

            $out = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (empty($item[$flagKey]) && $listKey === 'content') {
                    $item[$flagKey] = true;
                }
                if ($listKey === 'noise' && !isset($item[$flagKey])) {
                    $item[$flagKey] = true;
                }
                if (empty($item[$flagKey])) {
                    continue;
                }
                $snippet = trim((string)($item['text_snippet'] ?? $item['snippet'] ?? ''));
                if (mb_strlen($snippet) < self::MIN_SNIPPET_LEN) {
                    continue;
                }
                $out[] = [
                    'snippet' => $snippet,
                    'description' => trim((string)($item['description'] ?? '')),
                ];
            }

            return $out;
        }

        return [];
    }

    /**
     * @param array<string, mixed> $rawResponse
     */
    private function hasAnalysisSnippets(array $rawResponse): bool
    {
        $analysis = $rawResponse['analysis'] ?? null;
        if (!is_array($analysis)) {
            return false;
        }

        $samples = $analysis['samples'] ?? null;
        if (!is_array($samples)) {
            return false;
        }

        foreach ($samples as $sample) {
            if (!is_array($sample)) {
                continue;
            }
            foreach (['content', 'noise'] as $key) {
                $items = $sample[$key] ?? [];
                if (!is_array($items)) {
                    continue;
                }
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $snippet = trim((string)($item['text_snippet'] ?? $item['snippet'] ?? ''));
                    if (mb_strlen($snippet) >= self::MIN_SNIPPET_LEN) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function snippetStillPresent(string $haystack, string $snippet): bool
    {
        $normHay = $this->normalizeForMatch($haystack);
        $normNeedle = $this->normalizeForMatch($snippet);
        if ($normNeedle === '' || mb_strlen($normNeedle) < self::MIN_SNIPPET_LEN) {
            return false;
        }

        return str_contains($normHay, $normNeedle);
    }

    private function normalizeForMatch(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return mb_strtolower(trim($text));
    }
}
