<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

/**
 * Dry-run known digest HTML templates against sample mail and pick the best split_rules.
 */
final class DigestSplitSelectorProber
{
    /** @var list<array{story_selector: string, title_selector: string, link_selector: string, body_selector: string, label: string}> */
    private const TYPO3_TEMPLATES = [
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
    ];

    /** @var list<array{story_selector: string, title_selector: string, link_selector: string, body_selector: string, label: string}> */
    private const MJML_TEMPLATES = [
        [
            'label' => 'mjml_column_table',
            'story_selector' => 'div.mj-column-per-100 table',
            'title_selector' => 'a',
            'link_selector' => 'a',
            'body_selector' => 'td, p',
        ],
        [
            'label' => 'mjml_column',
            'story_selector' => 'div.mj-column-per-100',
            'title_selector' => 'a',
            'link_selector' => 'a',
            'body_selector' => 'td, p',
        ],
        [
            'label' => 'mjml_outlook_group',
            'story_selector' => 'div.mj-outlook-group-fix table',
            'title_selector' => 'a',
            'link_selector' => 'a',
            'body_selector' => 'td, p',
        ],
        [
            'label' => 'mjml_bold_link',
            'story_selector' => 'a[style*="font-weight:bold"]',
            'title_selector' => 'a',
            'link_selector' => 'a',
            'body_selector' => 'td, p',
        ],
    ];

    /** @var list<array{story_selector: string, title_selector: string, link_selector: string, body_selector: string, label: string}> */
    private const GENERIC_TEMPLATES = [
        [
            'label' => 'nested_table_td',
            'story_selector' => 'table table table table td',
            'title_selector' => 'a, h1, h2, h3',
            'link_selector' => 'a',
            'body_selector' => 'td, p',
        ],
        [
            'label' => 'article_div',
            'story_selector' => 'div.article, div.story, div.story-card',
            'title_selector' => 'h1, h2, h3, a',
            'link_selector' => 'a',
            'body_selector' => 'p, td, div.content',
        ],
    ];

    public function __construct(
        private readonly DigestSplitStructureHint $structureHint = new DigestSplitStructureHint(),
    ) {
    }

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

        foreach ($this->templatesForHtml($html) as $template) {
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
     * @return list<array{story_selector: string, title_selector: string, link_selector: string, body_selector: string, label: string}>
     */
    private function templatesForHtml(string $html): array
    {
        $templates = [];
        $lower = strtolower($html);

        if (str_contains($lower, 'csc-frame-default')) {
            $templates = array_merge($templates, self::TYPO3_TEMPLATES);
        }
        if (preg_match('/mj-column|mj-section|mj-wrapper|mj-outlook-group/i', $html) === 1) {
            $templates = array_merge($templates, self::MJML_TEMPLATES);
        }
        if ($templates === []) {
            $templates = self::GENERIC_TEMPLATES;
        }

        return array_merge($templates, $this->dynamicTemplates($html));
    }

    /**
     * @return list<array{story_selector: string, title_selector: string, link_selector: string, body_selector: string, label: string}>
     */
    private function dynamicTemplates(string $html): array
    {
        $templates = [];
        foreach ($this->structureHint->candidatesFromHtml($html) as $row) {
            if ($row['count'] < 2) {
                continue;
            }
            $templates[] = [
                'label' => 'auto_' . preg_replace('/[^a-zA-Z0-9_]+/', '_', $row['selector']),
                'story_selector' => $row['selector'],
                'title_selector' => 'a, h1, h2, h3',
                'link_selector' => 'a',
                'body_selector' => 'td, p',
            ];
        }

        return array_slice($templates, 0, 6);
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
