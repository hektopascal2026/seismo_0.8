<?php

declare(strict_types=1);

namespace Seismo\Service;

use Seismo\Controller\SettingsController;
use Seismo\Core\Mail\DigestSplitConfigNormalizer;
use Seismo\Core\Mail\DigestSplitVerifier;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Service\Http\BaseClient;

/**
 * Service to generate static regex email cleanup configurations using Google Gemini.
 * Zero runtime footprint—only executed on manual user request.
 */
final class EmailGeminiConfigGenerator
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const DEFAULT_MODEL = 'gemini-3.5-flash';
    private const MAX_SPLIT_RETRIES = 2;
    private const PROMPT_BODY_MAX_CHARS = 12000;
    private const PROMPT_HTML_MAX_CHARS = 50000;

    private const SPLIT_SYSTEM_INSTRUCTION = <<<'TEXT'
You are an expert email digest analyst configuring a deterministic HTML/text splitter.

A "card" is one distinct news item a reader would scan, open, or score independently.
NOT a card: masthead, navigation, "view in browser", section labels ("TOP STORIES"),
ads, unsubscribe footer, social icons, empty spacers, author bios.

Your output is consumed by PHP EmailDigestSplitterService. Follow the schema exactly.
CSS selectors must use only: tag, .class, #id, and space-separated descendants (no >, :nth-child, [attr]).
Prefer stable class/id/table wrappers repeated across all samples, not dates or tracking IDs.
HTML emails: use html_selector. Plain-text delimiters: use regex_split on text_body.
TEXT;

    public function __construct(
        private readonly SystemConfigRepository $configRepo,
        private readonly BaseClient $http = new BaseClient(60),
        private readonly DigestSplitVerifier $splitVerifier = new DigestSplitVerifier(),
    ) {
    }

    /**
     * @param list<array{subject: string, body?: string, text_body?: string, html_body?: string}> $samples
     * @return array{strip_regexes: list<string>, webview_keywords: list<string>, title_extractor: ?string, digest_split_config: ?string}
     */
    public function generateConfig(array $samples): array
    {
        $prompt = $this->buildBoilerplatePrompt($samples);

        $extracted = $this->callGeminiJson(
            "You are an expert email layout analyzer and regular expression engineer.\n"
            . "Generate regex cleanup rules, webview keywords, optional title extraction, "
            . "and digest split rules when the sender publishes multi-story digests.",
            $prompt
        );

        $dscString = $this->encodeDigestSplitConfig($extracted['digest_split_config'] ?? null);

        return [
            'strip_regexes' => $this->normalizeStringArray($extracted['strip_regexes'] ?? []),
            'webview_keywords' => $this->normalizeStringArray($extracted['webview_keywords'] ?? []),
            'title_extractor' => $this->normalizeString($extracted['title_extractor'] ?? null),
            'digest_split_config' => $dscString,
        ];
    }

    /**
     * @param list<array{subject: string, body?: string, text_body?: string, html_body?: string}> $samples
     * @return array{
     *     digest_split_config: ?string,
     *     analysis: ?array<string, mixed>,
     *     verification: array{
     *         verified: bool,
     *         expected_counts: list<int>,
     *         actual_counts: list<int>,
     *         attempts: int,
     *         message: string
     *     }
     * }
     */
    public function generateSplitConfig(array $samples): array
    {
        $attempts = 0;
        $lastAnalysis = null;
        $lastConfig = null;
        $verification = [
            'verified' => false,
            'expected_counts' => [],
            'actual_counts' => [],
            'attempts' => 0,
            'message' => 'No digest structure detected.',
        ];

        $retryContext = null;

        while ($attempts <= self::MAX_SPLIT_RETRIES) {
            ++$attempts;

            $prompt = $retryContext === null
                ? $this->buildSplitPrompt($samples)
                : $this->buildSplitRetryPrompt($samples, $retryContext);

            $extracted = $this->callGeminiJson(self::SPLIT_SYSTEM_INSTRUCTION, $prompt);
            $lastAnalysis = is_array($extracted['analysis'] ?? null) ? $extracted['analysis'] : null;

            if (empty($extracted['is_digest'])) {
                $verification = [
                    'verified' => true,
                    'expected_counts' => [],
                    'actual_counts' => [],
                    'attempts' => $attempts,
                    'message' => 'Not a multi-story digest — no split config needed.',
                ];

                return [
                    'digest_split_config' => null,
                    'analysis' => $lastAnalysis,
                    'verification' => $verification,
                ];
            }

            $normalized = DigestSplitConfigNormalizer::normalize($extracted);
            if ($normalized === null) {
                $retryContext = [
                    'analysis' => $lastAnalysis,
                    'config' => $extracted,
                    'verification' => [
                        'expected_counts' => DigestSplitConfigNormalizer::expectedCountsFromAnalysis($extracted),
                        'actual_counts' => array_fill(0, count($samples), 0),
                        'message' => 'Gemini returned is_digest=true but split_rules were incomplete or invalid.',
                    ],
                ];
                continue;
            }

            $lastConfig = $normalized;
            $check = $this->splitVerifier->verify($samples, $normalized, $extracted);
            $verification = [
                'verified' => $check['verified'],
                'expected_counts' => $check['expected_counts'],
                'actual_counts' => $check['actual_counts'],
                'attempts' => $attempts,
                'message' => $check['message'],
            ];

            if ($check['verified']) {
                return [
                    'digest_split_config' => json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'analysis' => $lastAnalysis,
                    'verification' => $verification,
                ];
            }

            $retryContext = [
                'analysis' => $lastAnalysis,
                'config' => $normalized,
                'verification' => $check,
            ];
        }

        return [
            'digest_split_config' => $lastConfig !== null
                ? json_encode($lastConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null,
            'analysis' => $lastAnalysis,
            'verification' => $verification,
        ];
    }

    /**
     * Second pass: user marked preview blocks as noise/keep; refine split_rules to drop noise.
     *
     * @param list<array{subject: string, body?: string, text_body?: string, html_body?: string}> $samples
     * @param array{is_digest: true, split_rules: array<string, mixed>} $currentConfig
     * @param array{blocks: list<array<string, mixed>>} $feedback
     * @return array{
     *     digest_split_config: ?string,
     *     analysis: ?array<string, mixed>,
     *     verification: array{
     *         verified: bool,
     *         expected_counts: list<int>,
     *         actual_counts: list<int>,
     *         attempts: int,
     *         message: string
     *     }
     * }
     */
    public function refineSplitConfig(array $samples, array $currentConfig, array $feedback): array
    {
        $keepCount = $this->countFeedbackVerdict($feedback, 'keep');
        $noiseCount = $this->countFeedbackVerdict($feedback, 'noise');
        if ($noiseCount === 0) {
            throw new \InvalidArgumentException('Mark at least one preview block as noise before refining.');
        }

        $attempts = 0;
        $lastAnalysis = null;
        $lastConfig = $currentConfig;
        $verification = [
            'verified' => false,
            'expected_counts' => [$keepCount],
            'actual_counts' => [],
            'attempts' => 0,
            'message' => 'Refinement did not produce a matching config.',
        ];

        $retryContext = null;

        while ($attempts <= self::MAX_SPLIT_RETRIES) {
            ++$attempts;

            $prompt = $retryContext === null
                ? $this->buildRefinePrompt($samples, $currentConfig, $feedback, $keepCount)
                : $this->buildRefineRetryPrompt($samples, $retryContext);

            $extracted = $this->callGeminiJson(self::SPLIT_SYSTEM_INSTRUCTION, $prompt);
            $lastAnalysis = is_array($extracted['analysis'] ?? null) ? $extracted['analysis'] : null;

            $normalized = DigestSplitConfigNormalizer::normalize($extracted);
            if ($normalized === null) {
                $retryContext = [
                    'analysis' => $lastAnalysis,
                    'config' => $currentConfig,
                    'feedback' => $feedback,
                    'verification' => [
                        'expected_counts' => [$keepCount],
                        'actual_counts' => [0],
                        'message' => 'Gemini returned invalid split_rules during refinement.',
                        'mismatches' => [],
                    ],
                ];
                continue;
            }

            $lastConfig = $normalized;
            $verifyPayload = $extracted;
            if (!is_array($verifyPayload['analysis'] ?? null)) {
                $verifyPayload['analysis'] = [
                    'samples' => [
                        ['sample_index' => 1, 'expected_card_count' => $keepCount],
                    ],
                ];
            }

            $check = $this->splitVerifier->verify($samples, $normalized, $verifyPayload);
            $verification = [
                'verified' => $check['verified'],
                'expected_counts' => $check['expected_counts'] !== [] ? $check['expected_counts'] : [$keepCount],
                'actual_counts' => $check['actual_counts'],
                'attempts' => $attempts,
                'message' => $check['verified']
                    ? 'Refined: ' . $keepCount . ' card(s) kept, ' . $noiseCount . ' noise block(s) excluded.'
                    : $check['message'],
            ];

            if ($check['verified']) {
                return [
                    'digest_split_config' => json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'analysis' => $lastAnalysis,
                    'verification' => $verification,
                ];
            }

            $retryContext = [
                'analysis' => $lastAnalysis,
                'config' => $normalized,
                'feedback' => $feedback,
                'verification' => $check,
            ];
        }

        return [
            'digest_split_config' => json_encode($lastConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'analysis' => $lastAnalysis,
            'verification' => $verification,
        ];
    }

    /**
     * @param array{blocks: list<array<string, mixed>>} $feedback
     */
    private function countFeedbackVerdict(array $feedback, string $verdict): int
    {
        $count = 0;
        foreach ($feedback['blocks'] ?? [] as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (trim((string)($block['verdict'] ?? '')) === $verdict) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param list<array{subject: string, body?: string, text_body?: string, html_body?: string}> $samples
     * @param array{is_digest: true, split_rules: array<string, mixed>} $currentConfig
     * @param array{blocks: list<array<string, mixed>>} $feedback
     */
    private function buildRefinePrompt(array $samples, array $currentConfig, array $feedback, int $keepCount): string
    {
        $prompt = "REFINEMENT PASS — the user reviewed a live split preview and marked blocks as noise or keep.\n\n";
        $prompt .= "Current split_rules produced too many matches. Revise so ONLY the kept blocks survive.\n";
        $prompt .= "Target: exactly {$keepCount} card(s) on sample #1.\n\n";
        $prompt .= "Current config:\n" . json_encode($currentConfig, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        $prompt .= "User feedback on preview blocks:\n" . json_encode($feedback, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        $prompt .= "Strategies (pick what fits):\n";
        $prompt .= "- Narrow story_selector to match only real story wrappers (e.g. tr.story not every tr).\n";
        $prompt .= "- Add exclude_selectors: array of simple selectors (.class, #id, tag) — matched story nodes are skipped.\n";
        $prompt .= "- Derive exclude_selectors from class/id patterns visible in noise block html_preview.\n\n";

        $prompt .= "Sample email for reference:\n";
        $prompt .= $this->formatSampleBlock(0, $samples[0]);

        $prompt .= "Return JSON:\n";
        $prompt .= "{\n";
        $prompt .= "  \"is_digest\": true,\n";
        $prompt .= "  \"confidence\": \"high\"|\"medium\"|\"low\",\n";
        $prompt .= "  \"analysis\": {\n";
        $prompt .= "    \"samples\": [{\"sample_index\": 1, \"expected_card_count\": {$keepCount}}]\n";
        $prompt .= "  },\n";
        $prompt .= "  \"split_rules\": { ... }\n";
        $prompt .= "}\n\n";
        $prompt .= $this->splitConfigSchemaDescription();
        $prompt .= "\nReturn ONLY valid JSON. No markdown.";

        return $prompt;
    }

    /**
     * @param list<array{subject: string, body?: string, text_body?: string, html_body?: string}> $samples
     * @param array{analysis: ?array, config: array, feedback: array, verification: array} $retryContext
     */
    private function buildRefineRetryPrompt(array $samples, array $retryContext): string
    {
        $prompt = "Refinement still failed verification. Revise split_rules again.\n\n";
        $prompt .= "Previous split_rules:\n" . json_encode($retryContext['config']['split_rules'] ?? $retryContext['config'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        $prompt .= "User feedback (noise vs keep):\n" . json_encode($retryContext['feedback'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        $prompt .= "Verification failure:\n" . ($retryContext['verification']['message'] ?? '') . "\n";

        $mismatches = $retryContext['verification']['mismatches'] ?? [];
        if ($mismatches !== []) {
            $prompt .= "Mismatches:\n" . json_encode($mismatches, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        }

        $prompt .= "Sample email:\n";
        $prompt .= $this->formatSampleBlock(0, $samples[0]);
        $prompt .= $this->splitConfigSchemaDescription();
        $prompt .= "\nReturn the full JSON object again. No markdown.";

        return $prompt;
    }

    /**
     * @param list<array{subject: string, body?: string, text_body?: string, html_body?: string}> $samples
     */
    private function buildBoilerplatePrompt(array $samples): string
    {
        $prompt = "We want to clean up emails from a newsletter sender. Below are sample emails from this sender.\n";
        $prompt .= "Analyze structure, headers, footers, 'view in browser' lines, disclaimers, and tracking noise.\n\n";

        foreach ($samples as $index => $sample) {
            $prompt .= $this->formatSampleBlock($index, $sample);
        }

        $prompt .= "Identify repeating structural boilerplate sections that appear in multiple samples.\n";
        $prompt .= "Generate robust PHP-compatible regular expressions. For HTML blocks, match opening to closing with .*? using /is or /iu.\n";
        $prompt .= "Avoid fragile values (email addresses, tracking IDs, timestamps); use wildcards.\n";
        $prompt .= "If bodies contain HTML entities (&amp;lt; etc.), include patterns for both escaped and normal forms.\n\n";
        $prompt .= "Return JSON with these keys:\n";
        $prompt .= "1. \"strip_regexes\": array of PHP regex strings with delimiters.\n";
        $prompt .= "2. \"webview_keywords\": array of web-version link phrases.\n";
        $prompt .= "3. \"title_extractor\": optional regex with capture group, or null.\n";
        $prompt .= "4. \"digest_split_config\": null if single-article, OR an object when multi-story digest:\n";
        $prompt .= $this->splitConfigSchemaDescription();
        $prompt .= "\nReturn ONLY valid JSON. No markdown.";

        return $prompt;
    }

    /**
     * @param list<array{subject: string, body?: string, text_body?: string, html_body?: string}> $samples
     */
    private function buildSplitPrompt(array $samples): string
    {
        $prompt = "Configure multi-story digest splitting for this newsletter sender.\n\n";
        $prompt .= "PHASE 1 — For EACH sample, identify cards vs noise and count expected_card_count.\n";
        $prompt .= "PHASE 2 — Propose split_rules that produce exactly those counts when run by our PHP splitter.\n\n";

        foreach ($samples as $index => $sample) {
            $prompt .= $this->formatSampleBlock($index, $sample);
        }

        $prompt .= "Return JSON:\n";
        $prompt .= "{\n";
        $prompt .= "  \"is_digest\": true|false,\n";
        $prompt .= "  \"confidence\": \"high\"|\"medium\"|\"low\",\n";
        $prompt .= "  \"analysis\": {\n";
        $prompt .= "    \"samples\": [\n";
        $prompt .= "      {\n";
        $prompt .= "        \"sample_index\": 1,\n";
        $prompt .= "        \"expected_card_count\": <int>,\n";
        $prompt .= "        \"cards\": [{\"title\": \"...\", \"link\": \"...\"}],\n";
        $prompt .= "        \"noise\": [{\"description\": \"...\", \"reason\": \"...\"}],\n";
        $prompt .= "        \"structural_observation\": \"stable wrapper pattern\"\n";
        $prompt .= "      }\n";
        $prompt .= "    ]\n";
        $prompt .= "  },\n";
        $prompt .= "  \"split_rules\": { ... }  // omit or null when is_digest is false\n";
        $prompt .= "}\n\n";
        $prompt .= $this->splitConfigSchemaDescription();
        $prompt .= "\nReturn ONLY valid JSON. No markdown.";

        return $prompt;
    }

    /**
     * @param list<array{subject: string, body?: string, text_body?: string, html_body?: string}> $samples
     * @param array{analysis: ?array, config: array, verification: array} $retryContext
     */
    private function buildSplitRetryPrompt(array $samples, array $retryContext): string
    {
        $prompt = "Your previous split_rules did NOT pass verification. Revise them.\n\n";
        $prompt .= "Previous analysis:\n" . json_encode($retryContext['analysis'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        $prompt .= "Previous split_rules:\n" . json_encode($retryContext['config']['split_rules'] ?? $retryContext['config'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        $prompt .= "Verification failure:\n" . ($retryContext['verification']['message'] ?? '') . "\n";

        $mismatches = $retryContext['verification']['mismatches'] ?? [];
        if ($mismatches !== []) {
            $prompt .= "Mismatches:\n" . json_encode($mismatches, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        }

        $prompt .= "Sample emails for reference:\n";
        foreach ($samples as $index => $sample) {
            $prompt .= $this->formatSampleBlock($index, $sample);
        }

        $prompt .= "Fix split_rules so actual_card_count equals expected_card_count on every sample.\n";
        $prompt .= "If story_selector matches too many nodes, narrow it. If too few, broaden to the parent wrapper.\n";
        $prompt .= $this->splitConfigSchemaDescription();
        $prompt .= "\nReturn the full JSON object again (is_digest, confidence, analysis, split_rules). No markdown.";

        return $prompt;
    }

    private function splitConfigSchemaDescription(): string
    {
        return <<<'TEXT'
split_rules schema (EmailDigestSplitterService):

HTML (preferred when html_body has structure):
{
  "split_method": "html_selector",
  "story_selector": "tr.story-row",
  "title_selector": "h2 a",
  "link_selector": "h2 a",
  "body_selector": "td.body",
  "exclude_selectors": [".masthead", "#footer"]
}

exclude_selectors (optional): simple .class, #id, tag, or tag.class selectors.
When a matched story node itself matches an exclude selector, that match is skipped.

Plain text (regex runs on text_body):
{
  "split_method": "regex_split",
  "split_pattern": "/---\s*STORY\s+\d+\s*---/i",
  "title_pattern": "/Title:\s*(.+)/i",
  "link_pattern": "/Link:\s*(https?:\S+)/i",
  "body_pattern": "/Body:\s*(.*)/is"
}

Stored config shape: { "is_digest": true, "split_rules": { ... } }
title_selector/link_selector/body_selector are relative to each story node.
story_selector must match story wrappers only — not nested children.
TEXT;
    }

    /**
     * @param array{subject: string, body?: string, text_body?: string, html_body?: string} $sample
     */
    private function formatSampleBlock(int $index, array $sample): string
    {
        $block = '--- SAMPLE EMAIL #' . ($index + 1) . " ---\n";
        $block .= 'Subject: ' . $sample['subject'] . "\n";

        $html = trim((string)($sample['html_body'] ?? ''));
        if ($html !== '') {
            $block .= "HTML Body (primary source for digest structure";
            if (mb_strlen($html) > self::PROMPT_HTML_MAX_CHARS) {
                $block .= ', truncated';
            }
            $block .= "):\n" . $this->truncateForPrompt($html, self::PROMPT_HTML_MAX_CHARS) . "\n";
        }

        $text = trim((string)($sample['text_body'] ?? $sample['body'] ?? ''));
        $block .= "Plain Text Body:\n" . $this->truncateForPrompt($text, self::PROMPT_BODY_MAX_CHARS) . "\n\n";

        return $block;
    }

    private function truncateForPrompt(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, $maxChars) . "\n[... truncated ...]";
    }

    /**
     * @return array<string, mixed>
     */
    private function callGeminiJson(string $systemInstruction, string $prompt): array
    {
        $apiKey = trim((string)($this->configRepo->get(SettingsController::KEY_GEMINI_API_KEY) ?? ''));
        if ($apiKey === '') {
            throw new \RuntimeException('Google Gemini API key is not configured. Please add it under Settings → General.');
        }

        $modelConfigured = trim((string)($this->configRepo->get(GeminiResearcherService::CONFIG_KEY_MODEL) ?? ''));
        $model = $modelConfigured !== '' ? $modelConfigured : self::DEFAULT_MODEL;

        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => $systemInstruction]],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $prompt]],
                ],
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.1,
            ],
        ];

        $url = self::API_BASE . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);

        try {
            $response = $this->http->postJson($url, $payload);
            if ($response->status !== 200) {
                throw new \RuntimeException('Gemini API call failed with status ' . $response->status . ': ' . $response->body);
            }

            $data = json_decode($response->body, true);
            $text = $this->cleanGeminiJsonText(trim((string)($data['candidates'][0]['content']['parts'][0]['text'] ?? '')));
            if ($text === '' || $text === 'null') {
                throw new \RuntimeException('Gemini returned an empty response.');
            }

            $extracted = json_decode($text, true);
            if (!is_array($extracted)) {
                throw new \RuntimeException('Failed to parse Gemini output as JSON.');
            }

            return $extracted;
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('Seismo EmailGeminiConfigGenerator error: ' . $e->getMessage());
            throw new \RuntimeException('AI Analysis failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function cleanGeminiJsonText(string $text): string
    {
        if (str_starts_with($text, '```json')) {
            $text = substr($text, 7);
        } elseif (str_starts_with($text, '```')) {
            $text = substr($text, 3);
        }
        if (str_ends_with($text, '```')) {
            $text = substr($text, 0, -3);
        }

        return trim($text);
    }

    private function encodeDigestSplitConfig(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        if (is_string($raw)) {
            $decoded = json_decode(trim($raw), true);
            if (!is_array($decoded)) {
                return trim($raw) === '' || trim($raw) === 'null' ? null : trim($raw);
            }
            $raw = $decoded;
        }

        if (!is_array($raw)) {
            return null;
        }

        if (empty($raw['is_digest'])) {
            return null;
        }

        $normalized = DigestSplitConfigNormalizer::normalize($raw);
        if ($normalized === null) {
            return null;
        }

        return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function normalizeString(mixed $val): ?string
    {
        if ($val === null) {
            return null;
        }
        $str = trim((string)$val);

        return $str === '' ? null : $str;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringArray(mixed $val): array
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
