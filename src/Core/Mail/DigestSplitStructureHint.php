<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Lightweight HTML scan to suggest repeated story wrappers before Gemini proposes split_rules.
 */
final class DigestSplitStructureHint
{
    private const MIN_REPEAT = 2;
    private const MAX_REPEAT = 40;
    private const MAX_CANDIDATES = 8;

    /** @var list<string> */
    private const NOISE_CLASSES = [
        'footer', 'header', 'masthead', 'nav', 'menu', 'button', 'spacer', 'unsubscribe',
        'social', 'share', 'comment', 'widget', 'subscribe', 'tracking', 'pixel',
    ];

    /**
     * @return list<array{selector: string, count: int, sample_text: string}>
     */
    public function typo3CandidatesFromHtml(string $html): array
    {
        $dom = $this->loadDom($html);
        if ($dom === null) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        $candidates = [];
        foreach ([
            'div.csc-frame-default' => './/div[contains(concat(" ", normalize-space(@class), " "), " csc-frame-default ")]',
            'h1.csc-firstHeader' => './/h1[contains(concat(" ", normalize-space(@class), " "), " csc-firstHeader ")]',
            'p.bodytext' => './/p[contains(concat(" ", normalize-space(@class), " "), " bodytext ")]',
        ] as $selector => $query) {
            $nodes = $xpath->query($query);
            $count = $nodes !== false ? $nodes->length : 0;
            if ($count === 0) {
                continue;
            }
            $sampleText = '';
            if ($nodes !== false && $nodes->item(0) !== null) {
                $sampleText = trim(mb_substr($nodes->item(0)->textContent, 0, 120));
            }
            $candidates[] = [
                'selector' => $selector,
                'count' => $count,
                'sample_text' => $sampleText,
            ];
        }

        return $candidates;
    }

    public function candidatesFromHtml(string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            return [];
        }

        $typo3 = $this->typo3CandidatesFromHtml($html);
        if ($typo3 !== []) {
            return $typo3;
        }

        $mjml = $this->mjmlCandidatesFromHtml($html);
        if ($mjml !== []) {
            return $mjml;
        }

        $dom = $this->loadDom($html);
        if ($dom === null) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//*[@class]');
        if ($nodes === false) {
            return [];
        }

        /** @var array<string, list<DOMElement>> $bySelector */
        $bySelector = [];
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($node->tagName);
            $classes = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];
            foreach ($classes as $class) {
                $class = trim($class);
                if ($class === '' || $this->isNoiseClass($class)) {
                    continue;
                }
                $selector = $tag . '.' . $class;
                $bySelector[$selector][] = $node;
            }
        }

        $candidates = [];
        foreach ($bySelector as $selector => $elements) {
            $count = count($elements);
            if ($count < self::MIN_REPEAT || $count > self::MAX_REPEAT) {
                continue;
            }
            $sampleText = '';
            if ($elements[0] instanceof DOMElement) {
                $sampleText = trim(mb_substr($elements[0]->textContent, 0, 120));
            }
            $candidates[] = [
                'selector' => $selector,
                'count' => $count,
                'sample_text' => $sampleText,
            ];
        }

        usort(
            $candidates,
            static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strlen($b['sample_text']) <=> strlen($a['sample_text'])
        );

        return array_slice($candidates, 0, self::MAX_CANDIDATES);
    }

    /**
     * @param list<array{subject: string, body?: string, text_body?: string, html_body?: string}> $samples
     */
    public function formatForPrompt(array $samples): string
    {
        foreach ($samples as $index => $sample) {
            $html = trim((string)($sample['html_body'] ?? ''));
            if ($html === '') {
                continue;
            }
            $candidates = $this->candidatesFromHtml($html);
            if ($candidates === []) {
                return '';
            }

            $block = 'PHP HTML structure scan (sample #' . ($index + 1) . ") — repeated wrappers likely to be story_selector:\n";
            foreach ($candidates as $row) {
                $block .= '- ' . $row['selector'] . ' => ' . $row['count'] . ' nodes';
                if ($row['sample_text'] !== '') {
                    $block .= ' (e.g. "' . $row['sample_text'] . '")';
                }
                $block .= "\n";
            }
            if ($this->looksLikeTypo3Newsletter($candidates)) {
                $block .= "TYPO3/punkt4 digest detected. Prefer:\n";
                $block .= "story_selector: \"div.csc-frame-default, table table table table td\"\n";
                $block .= "title_selector: \"h1.csc-firstHeader, a\"\n";
                $block .= "link_selector: \"a\"\n";
                $block .= "body_selector: \"p.bodytext, td\"\n";
            } elseif ($this->looksLikeMjmlNewsletter($candidates)) {
                $block .= "MJML digest detected. Prefer:\n";
                $block .= "story_selector: \"div.mj-column-per-100 table\"\n";
                $block .= "title_selector: \"a\"\n";
                $block .= "link_selector: \"a\"\n";
                $block .= "body_selector: \"td, p\"\n";
            } else {
                $block .= "Pick a selector matching repeated story wrappers in html_body.\n";
            }
            $block .= "\n";

            return $block;
        }

        return '';
    }

    private function loadDom(string $html): ?DOMDocument
    {
        $html = trim($html);
        if ($html === '') {
            return null;
        }

        $prev = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (stripos($html, 'encoding=') === false) {
            $html = '<?xml encoding="UTF-8">' . $html;
        }
        $loaded = $dom->loadHTML($html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $loaded ? $dom : null;
    }

    /**
     * @param list<array{selector: string, count: int, sample_text: string}> $candidates
     */
    private function looksLikeTypo3Newsletter(array $candidates): bool
    {
        foreach ($candidates as $row) {
            if ($row['selector'] === 'div.csc-frame-default' && $row['count'] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{selector: string, count: int, sample_text: string}>
     */
    public function mjmlCandidatesFromHtml(string $html): array
    {
        $dom = $this->loadDom($html);
        if ($dom === null) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        $candidates = [];
        foreach ([
            'div.mj-column-per-100' => './/div[contains(concat(" ", normalize-space(@class), " "), " mj-column-per-100 ")]',
            'div.mj-outlook-group-fix' => './/div[contains(@class, "mj-outlook-group-fix")]',
            'table.mj-full-width-mobile' => './/table[contains(@class, "mj-full-width-mobile")]',
        ] as $selector => $query) {
            $nodes = $xpath->query($query);
            $count = $nodes !== false ? $nodes->length : 0;
            if ($count === 0) {
                continue;
            }
            $sampleText = '';
            if ($nodes !== false && $nodes->item(0) !== null) {
                $sampleText = trim(mb_substr($nodes->item(0)->textContent, 0, 120));
            }
            $candidates[] = [
                'selector' => $selector,
                'count' => $count,
                'sample_text' => $sampleText,
            ];
        }

        return $candidates;
    }

    /**
     * @param list<array{selector: string, count: int, sample_text: string}> $candidates
     */
    private function looksLikeMjmlNewsletter(array $candidates): bool
    {
        foreach ($candidates as $row) {
            if (str_starts_with($row['selector'], 'div.mj-') && $row['count'] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{subject: string, body?: string, text_body?: string, html_body?: string}> $samples
     */
    public function detectTypo3InSamples(array $samples): bool
    {
        foreach ($samples as $sample) {
            $html = trim((string)($sample['html_body'] ?? ''));
            if ($html === '') {
                continue;
            }
            if ($this->looksLikeTypo3Newsletter($this->typo3CandidatesFromHtml($html))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{subject: string, body?: string, text_body?: string, html_body?: string}> $samples
     */
    public function samplesHaveHtml(array $samples): bool
    {
        foreach ($samples as $sample) {
            if (trim((string)($sample['html_body'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function isNoiseClass(string $class): bool
    {
        $lower = strtolower($class);
        foreach (self::NOISE_CLASSES as $noise) {
            if (str_contains($lower, $noise)) {
                return true;
            }
        }

        return false;
    }
}
