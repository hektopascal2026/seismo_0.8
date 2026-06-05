<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

/**
 * Dry-run digest split config against sample emails; compare to Gemini expected counts.
 */
final class DigestSplitVerifier
{
    /**
     * @param list<array{subject: string, body?: string, text_body?: string, html_body?: string}> $samples
     * @param array{is_digest: true, split_rules: array<string, string>} $config
     * @param array<string, mixed> $rawResponse
     * @return array{
     *     verified: bool,
     *     expected_counts: list<int>,
     *     actual_counts: list<int>,
     *     message: string,
     *     mismatches: list<array{sample_index: int, expected: int, actual: int, titles: list<string>}>
     * }
     */
    public function verify(array $samples, array $config, array $rawResponse = []): array
    {
        $splitter = new EmailDigestSplitterService();
        $actualCounts = [];
        $titlesBySample = [];
        $expectedByIndex = $this->expectedCountsBySampleIndex($rawResponse);

        foreach ($samples as $index => $sample) {
            $html = (string)($sample['html_body'] ?? '');
            $text = (string)($sample['text_body'] ?? $sample['body'] ?? '');
            $stories = $splitter->split($html, $text, $config);
            $actualCounts[] = count($stories);
            $titlesBySample[$index] = array_map(
                static fn (array $s): string => trim((string)($s['title'] ?? '')),
                $stories
            );
        }

        if ($expectedByIndex === []) {
            $firstActual = $actualCounts[0] ?? 0;
            $verified = $firstActual > 0;
            $message = $verified
                ? 'Split produced ' . $firstActual . ' card(s) on sample 1 (no expected counts from analysis).'
                : 'Split produced zero cards — selectors likely wrong.';

            return [
                'verified' => $verified,
                'expected_counts' => [],
                'actual_counts' => $actualCounts,
                'message' => $message,
                'mismatches' => $this->buildMismatches($expectedByIndex, $actualCounts, $titlesBySample),
            ];
        }

        $mismatches = $this->buildMismatches($expectedByIndex, $actualCounts, $titlesBySample);
        $verified = $mismatches === [];

        return [
            'verified' => $verified,
            'expected_counts' => array_values($expectedByIndex),
            'actual_counts' => $actualCounts,
            'message' => $verified
                ? 'Verified: card counts match on all analyzed samples.'
                : 'Count mismatch on ' . count($mismatches) . ' sample(s) — config may need manual tuning.',
            'mismatches' => $mismatches,
        ];
    }

    /**
     * @param array<string, mixed> $rawResponse
     * @return array<int, int>
     */
    private function expectedCountsBySampleIndex(array $rawResponse): array
    {
        $analysis = $rawResponse['analysis'] ?? null;
        if (!is_array($analysis)) {
            return [];
        }

        $samples = $analysis['samples'] ?? null;
        if (!is_array($samples)) {
            return [];
        }

        $byIndex = [];
        foreach ($samples as $sample) {
            if (!is_array($sample)) {
                continue;
            }
            $idx = (int)($sample['sample_index'] ?? 0);
            if ($idx <= 0) {
                continue;
            }
            $count = $sample['expected_card_count'] ?? null;
            if (is_int($count) || is_numeric($count)) {
                $byIndex[$idx - 1] = (int)$count;
            }
        }

        return $byIndex;
    }

    /**
     * @param array<int, int> $expectedByIndex
     * @param list<int> $actualCounts
     * @param array<int, list<string>> $titlesBySample
     * @return list<array{sample_index: int, expected: int, actual: int, titles: list<string>}>
     */
    private function buildMismatches(array $expectedByIndex, array $actualCounts, array $titlesBySample): array
    {
        $mismatches = [];
        foreach ($expectedByIndex as $index => $expected) {
            $actual = $actualCounts[$index] ?? 0;
            if ($actual !== $expected) {
                $mismatches[] = [
                    'sample_index' => $index + 1,
                    'expected' => $expected,
                    'actual' => $actual,
                    'titles' => array_slice($titlesBySample[$index] ?? [], 0, 5),
                ];
            }
        }

        return $mismatches;
    }
}
