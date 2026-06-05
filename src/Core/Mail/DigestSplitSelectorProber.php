<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

/**
 * Dry-run known digest HTML templates against sample mail and pick the best split_rules.
 */
final class DigestSplitSelectorProber
{
    /** @var list<array{story_selector: string, title_selector: string, link_selector: string, body_selector: string, label: string}> */
    private const TEMPLATES = [
        [
            'label' => 'typo3_punkt4_combined',
            'story_selector' => 'div.csc-frame-default, table table table table td',
            'title_selector' => 'h1.csc-firstHeader, a',
            'link_selector' => 'a',
            'body_selector' => 'p.bodytext, td',
        ],
        [
            'label' => 'typo3_events_plus_bold_links',
            'story_selector' => 'div.csc-frame-default, a[style*="font-weight:bold"]',
            'title_selector' => 'h1, a',
            'link_selector' => 'a',
            'body_selector' => 'p.bodytext',
        ],
        [
            'label' => 'csc_frame_only',
            'story_selector' => 'div.csc-frame-default',
            'title_selector' => 'h1.csc-firstHeader',
            'link_selector' => 'a',
            'body_selector' => 'p.bodytext',
        ],
        [
            'label' => 'nested_table_td',
            'story_selector' => 'table table table table td',
            'title_selector' => 'a',
            'link_selector' => 'a',
            'body_selector' => 'td',
        ],
    ];

    /**
     * @return ?array{is_digest: true, split_rules: array<string, string>, score: int, label: string}
     */
    public function probeBest(string $html): ?array
    {
        $html = trim($html);
        if ($html === '') {
            return null;
        }

        $splitter = new EmailDigestSplitterService();
        $best = null;
        $bestScore = 0;

        foreach (self::TEMPLATES as $template) {
            $rules = [
                'split_method' => 'html_selector',
                'story_selector' => $template['story_selector'],
                'title_selector' => $template['title_selector'],
                'link_selector' => $template['link_selector'],
                'body_selector' => $template['body_selector'],
            ];
            $stories = $splitter->split($html, '', ['is_digest' => true, 'split_rules' => $rules]);
            $score = $this->scoreStories($stories);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [
                    'is_digest' => true,
                    'split_rules' => $rules,
                    'score' => $score,
                    'label' => $template['label'],
                ];
            }
        }

        if ($best === null || $bestScore < 2) {
            return null;
        }

        return $best;
    }

    /**
     * @param list<array{title: string, html_body: string, text_body: string, link: ?string}> $stories
     */
    private function scoreStories(array $stories): int
    {
        $score = 0;
        foreach ($stories as $story) {
            $title = trim((string)($story['title'] ?? ''));
            $link = trim((string)($story['link'] ?? ''));
            $body = trim((string)($story['text_body'] ?? ''));

            if ($title === '' || $link === '') {
                continue;
            }
            if (mb_strlen($title) < 12) {
                continue;
            }
            if (strcasecmp($title, 'Mehr') === 0) {
                continue;
            }
            if ($body === '' || mb_strlen($body) < 20) {
                continue;
            }
            if (!str_contains($body, ' ') && mb_strlen($body) < 30) {
                continue;
            }

            ++$score;
        }

        return $score;
    }
}
