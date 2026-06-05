<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

/**
 * Dry-run digest split config against sample emails using structural checks (not editorial card counts).
 */
final class DigestSplitVerifier
{
    private const MIN_TITLE_LEN = 12;

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
        $storiesBySample = [];

        foreach ($samples as $index => $sample) {
            $html = (string)($sample['html_body'] ?? '');
            $text = (string)($sample['text_body'] ?? $sample['body'] ?? '');
            $stories = $splitter->split($html, $text, $config);
            $storiesBySample[$index] = $stories;
            $actualCounts[] = count($stories);
            $titlesBySample[$index] = array_map(
                static fn (array $s): string => trim((string)($s['title'] ?? '')),
                $stories
            );
        }

        return $this->verifyStructurally($actualCounts, $titlesBySample, $storiesBySample);
    }

    /**
     * @param list<int> $actualCounts
     * @param array<int, list<string>> $titlesBySample
     * @param array<int, list<array{title: string, html_body: string, text_body: string, link: ?string}>> $storiesBySample
     * @return array{
     *     verified: bool,
     *     expected_counts: list<int>,
     *     actual_counts: list<int>,
     *     message: string,
     *     mismatches: list<array{sample_index: int, expected: int, actual: int, titles: list<string>}>
     * }
     */
    private function verifyStructurally(array $actualCounts, array $titlesBySample, array $storiesBySample): array
    {
        $mismatches = [];
        $verified = true;

        foreach ($actualCounts as $index => $actual) {
            $stories = $storiesBySample[$index] ?? [];
            $validStories = array_filter($stories, fn (array $s): bool => $this->storyLooksValid($s));

            if ($actual === 0) {
                $verified = false;
                $mismatches[] = [
                    'sample_index' => $index + 1,
                    'expected' => 0,
                    'actual' => 0,
                    'titles' => [],
                ];
                continue;
            }

            if ($validStories === []) {
                $verified = false;
                $mismatches[] = [
                    'sample_index' => $index + 1,
                    'expected' => 0,
                    'actual' => $actual,
                    'titles' => array_slice($titlesBySample[$index] ?? [], 0, 5),
                ];
            }
        }

        $firstActual = $actualCounts[0] ?? 0;
        $firstValid = count(array_filter($storiesBySample[0] ?? [], fn (array $s): bool => $this->storyLooksValid($s)));

        if ($verified) {
            $message = 'Verified: split produced ' . $firstActual . ' card(s) on sample 1 ('
                . $firstValid . ' with title, link, and body).';
        } elseif ($firstActual === 0) {
            $message = 'Split produced zero cards — selectors likely wrong.';
        } else {
            $message = 'Split produced cards but none passed title/link/body checks — selectors likely wrong.';
        }

        return [
            'verified' => $verified,
            'expected_counts' => [],
            'actual_counts' => $actualCounts,
            'message' => $message,
            'mismatches' => $mismatches,
        ];
    }

    /**
     * @param array{title: string, html_body: string, text_body: string, link: ?string} $story
     */
    private function storyLooksValid(array $story): bool
    {
        $title = trim((string)($story['title'] ?? ''));
        $link = trim((string)($story['link'] ?? ''));
        $body = trim((string)($story['text_body'] ?? ''));

        if ($title === '' || mb_strlen($title) < self::MIN_TITLE_LEN) {
            return false;
        }
        if (strcasecmp($title, 'Mehr') === 0) {
            return false;
        }
        if ($link === '') {
            return false;
        }
        if ($body === '' || mb_strlen($body) < 20) {
            return false;
        }

        return true;
    }
}
