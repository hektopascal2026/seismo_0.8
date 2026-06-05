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
    public function candidatesFromHtml(string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            return [];
        }

        $prev = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (stripos($html, 'encoding=') === false) {
            $html = '<?xml encoding="UTF-8">' . $html;
        }
        $loaded = $dom->loadHTML($html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$loaded) {
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
            $block .= "Pick a selector whose match count equals expected_card_count. Do NOT count plain-text topics.\n\n";

            return $block;
        }

        return '';
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
