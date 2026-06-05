<?php

declare(strict_types=1);

namespace Seismo\Service;

use Seismo\Controller\SettingsController;
use Seismo\Core\Mail\CleanupConfigVerifier;
use Seismo\Core\Mail\DigestSplitConfigNormalizer;
use Seismo\Core\Mail\DigestSplitVerifier;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Service\Http\BaseClient;
use Seismo\Service\Http\HttpClientException;
use Seismo\Service\Http\Response;

/**
 * Service to generate static regex email cleanup configurations using Google Gemini.
 * Zero runtime footprint—only executed on manual user request.
 */
final class EmailGeminiConfigGenerator
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const DEFAULT_MODEL = 'gemini-3.5-flash';
    /** Max extra retries after the first Gemini call (cleanup: 1 => 2 calls total; split: 2 => 3 calls total). */
    private const MAX_CLEANUP_RETRIES = 1;
    private const MAX_SPLIT_RETRIES = 1;
    public const GEMINI_SAMPLE_COUNT = 3;
    private const HTTP_TIMEOUT_SECONDS = 120;
    private const PROMPT_BODY_MAX_CHARS = 8000;
    private const PROMPT_HTML_MAX_CHARS = 15000;

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

    private const CLEANUP_SYSTEM_INSTRUCTION = <<<'TEXT'
You are an expert newsletter editor preparing emails for a clean reading timeline.

CONTENT the reader wants: headlines, article paragraphs, bylines, story links, quotes, datelines.
NOISE to strip: mastheads, logos-as-text, navigation, "view in browser" / webversion lines, ads,
social follow blocks, section dividers with no article text, legal footers, unsubscribe/preferences,
tracking boilerplate, empty spacer lines, repeated subject-line banners.

Your strip_regexes run as PHP preg_replace on plain text_body (after HTML→text conversion).
Use robust patterns with /iu or /is delimiters. Match whole noise blocks; never match core article text.
Include text_snippet (8+ chars, copied verbatim from plain text) for every content and noise item in analysis.
TEXT;

    public function __construct(
        private readonly SystemConfigRepository $configRepo,
        private readonly BaseClient $http = new BaseClient(self::HTTP_TIMEOUT_SECONDS),
        private readonly DigestSplitVerifier $splitVerifier = new DigestSplitVerifier(),
        private readonly CleanupConfigVerifier $cleanupVerifier = new CleanupConfigVerifier(),
    ) {
    }

    /**
     * @param list<array{subject: string, body?: string, text_body?: string, html_body?: string}> $samples
     * @return array{
     *     strip_regexes: list<string>,
     *     webview_keywords: list<string>,
     *     title_extractor: ?string,
     *     digest_split_config: ?string,
     *     analysis: ?array<string, mixed>,
     *     verification: array{verified: bool, message: string, attempts: int, issues: list<array<string, mixed>>}
     * }
     */
    public function generateConfig(array $samples): array
    {
        $attempts = 0;
        $lastAnalysis = null;
        $lastConfig = null;
        $verification = [
            'verified' => false,
            'message' => 'Cleanup analysis did not produce a valid config.',
            'attempts' => 0,
            'issues' => [],
        ];

        $retryContext = null;

        while ($attempts <= self::MAX_CLEANUP_RETRIES) {
            ++$attempts;

            $prompt = $retryContext === null
                ? $this->buildBoilerplatePrompt($samples)
                : $this->buildCleanupRetryPrompt($samples, $retryContext);

            $extracted = $this->callGeminiJson(
                self::CLEANUP_SYSTEM_INSTRUCTION,
                $prompt,
                'cleanup attempt ' . $attempts . '/' . (self::MAX_CLEANUP_RETRIES + 1)
            );
            $lastAnalysis = is_array($extracted['analysis'] ?? null) ? $extracted['analysis'] : null;

            $config = $this->extractCleanupConfig($extracted);
            if ($config['strip_regexes'] === []) {
                $retryContext = [
                    'analysis' => $lastAnalysis,
                    'config' => $config,
                    'verification' => [
                        'message' => 'Gemini returned no strip_regexes.',
                        'issues' => [],
                    ],
                ];
                continue;
            }

            $lastConfig = $config;
            $check = $this->cleanupVerifier->verify($samples, $config, $extracted);
            $verification = [
                'verified' => $check['verified'],
                'message' => $check['message'],
                'attempts' => $attempts,
                'issues' => $check['issues'],
            ];

            if ($check['verified']) {
                return $this->buildCleanupResult($config, $extracted, $lastAnalysis, $verification);
            }

            $retryContext = [
                'analysis' => $lastAnalysis,
                'config' => $config,
                'verification' => $check,
            ];
        }

        return $this->buildCleanupResult(
            $lastConfig ?? ['strip_regexes' => [], 'webview_keywords' => [], 'title_extractor' => null],
            [],
            $lastAnalysis,
            $verification
        );
    }

    /**
     * @param list<array{subject: string, body?: string, text_body?: string, html_body?: string}> $samples
     * @param array{strip_regexes: list<string>, webview_keywords?: list<string>, title_extractor?: ?string} $currentConfig
     * @param array{still_noise?: list<array<string, mixed>>, wrongly_removed?: list<array<string, mixed>>} $feedback
     * @return array{
     *     strip_regexes: list<string>,
     *     webview_keywords: list<string>,
     *     title_extractor: ?string,
     *     digest_split_config: ?string,
     *     analysis: ?array<string, mixed>,
     *     verification: array{verified: bool, message: string, attempts: int, issues: list<array<string, mixed>>}
     * }
     */
    public function refineCleanupConfig(array $samples, array $currentConfig, array $feedback): array
    {
        $stillNoise = $feedback['still_noise'] ?? [];
        $wronglyRemoved = $feedback['wrongly_removed'] ?? [];
        if (!is_array($stillNoise)) {
            $stillNoise = [];
        }
        if (!is_array($wronglyRemoved)) {
            $wronglyRemoved = [];
        }
        if ($stillNoise === [] && $wronglyRemoved === []) {
            throw new \InvalidArgumentException('Provide still-visible noise or wrongly removed content before refining.');
        }

        $attempts = 0;
        $lastAnalysis = null;
        $lastConfig = $currentConfig;
        $verification = [
            'verified' => false,
            'message' => 'Refinement did not fix cleanup issues.',
            'attempts' => 0,
            'issues' => [],
        ];

        $retryContext = null;

        while ($attempts <= self::MAX_CLEANUP_RETRIES) {
            ++$attempts;

            $prompt = $retryContext === null
                ? $this->buildCleanupRefinePrompt($samples, $currentConfig, $feedback)
                : $this->buildCleanupRetryPrompt($samples, $retryContext);

            $extracted = $this->callGeminiJson(
                self::CLEANUP_SYSTEM_INSTRUCTION,
                $prompt,
                'cleanup refine attempt ' . $attempts . '/' . (self::MAX_CLEANUP_RETRIES + 1)
            );
            $lastAnalysis = is_array($extracted['analysis'] ?? null) ? $extracted['analysis'] : null;

            $config = $this->extractCleanupConfig($extracted);
            if ($config['strip_regexes'] === []) {
                $retryContext = [
                    'analysis' => $lastAnalysis,
                    'config' => $currentConfig,
                    'feedback' => $feedback,
                    'verification' => [
                        'message' => 'Gemini returned no strip_regexes during refinement.',
                        'issues' => [],
                    ],
                ];
                continue;
            }

            $lastConfig = $config;
            $check = $this->cleanupVerifier->verify($samples, $config, $extracted);
            $verification = [
                'verified' => $check['verified'],
                'message' => $check['verified']
                    ? 'Refined cleanup verified on samples.'
                    : $check['message'],
                'attempts' => $attempts,
                'issues' => $check['issues'],
            ];

            if ($check['verified']) {
                return $this->buildCleanupResult($config, $extracted, $lastAnalysis, $verification);
            }

            $retryContext = [
                'analysis' => $lastAnalysis,
                'config' => $config,
                'feedback' => $feedback,
                'verification' => $check,
            ];
        }

        return $this->buildCleanupResult($lastConfig, [], $lastAnalysis, $verification);
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

            $extracted = $this->callGeminiJson(
                self::SPLIT_SYSTEM_INSTRUCTION,
                $prompt,
                'split attempt ' . $attempts . '/' . (self::MAX_SPLIT_RETRIES + 1)
            );
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

            $extracted = $this->callGeminiJson(
                self::SPLIT_SYSTEM_INSTRUCTION,
                $prompt,
                'split refine attempt ' . $attempts . '/' . (self::MAX_SPLIT_RETRIES + 1)
            );
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
        $prompt = "Configure boilerplate cleanup for this newsletter sender.\n\n";
        $prompt .= "PHASE 1 — Editor analysis: for EACH sample, list content vs noise with verbatim text_snippet (8+ chars).\n";
        $prompt .= "PHASE 2 — Generate strip_regexes that remove ALL noise snippets but preserve ALL content snippets.\n\n";

        foreach ($samples as $index => $sample) {
            $prompt .= $this->formatSampleBlock($index, $sample);
        }

        $prompt .= "Return JSON:\n";
        $prompt .= "{\n";
        $prompt .= "  \"confidence\": \"high\"|\"medium\"|\"low\",\n";
        $prompt .= "  \"analysis\": {\n";
        $prompt .= "    \"samples\": [\n";
        $prompt .= "      {\n";
        $prompt .= "        \"sample_index\": 1,\n";
        $prompt .= "        \"content\": [{\"description\": \"...\", \"text_snippet\": \"verbatim from plain text\", \"must_keep\": true}],\n";
        $prompt .= "        \"noise\": [{\"description\": \"...\", \"text_snippet\": \"verbatim from plain text\", \"must_remove\": true}]\n";
        $prompt .= "      }\n";
        $prompt .= "    ]\n";
        $prompt .= "  },\n";
        $prompt .= "  \"strip_regexes\": [\"/pattern/ui\", ...],\n";
        $prompt .= "  \"webview_keywords\": [\"view online\", ...],\n";
        $prompt .= "  \"title_extractor\": null,\n";
        $prompt .= "  \"digest_split_config\": null\n";
        $prompt .= "}\n\n";
        $prompt .= $this->cleanupConfigSchemaDescription();
        $prompt .= "\nIf multi-story digest, set digest_split_config instead of null:\n";
        $prompt .= $this->splitConfigSchemaDescription();
        $prompt .= "\nReturn ONLY valid JSON. No markdown.";

        return $prompt;
    }

    /**
     * @param list<array{subject: string, body?: string, text_body?: string, html_body?: string}> $samples
     * @param array{strip_regexes: list<string>, webview_keywords?: list<string>, title_extractor?: ?string} $currentConfig
     * @param array{still_noise?: list<array<string, mixed>>, wrongly_removed?: list<array<string, mixed>>} $feedback
     */
    private function buildCleanupRefinePrompt(array $samples, array $currentConfig, array $feedback): string
    {
        $prompt = "REFINEMENT PASS — user reviewed cleanup preview and reported problems.\n\n";
        $prompt .= "Current config:\n" . json_encode($currentConfig, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        $prompt .= "User feedback:\n" . json_encode($feedback, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        $prompt .= "Revise strip_regexes:\n";
        $prompt .= "- still_noise: add/tighten patterns to remove these phrases (do NOT remove article text).\n";
        $prompt .= "- wrongly_removed: loosen or remove patterns that deleted this content.\n\n";

        foreach ($samples as $index => $sample) {
            $prompt .= $this->formatSampleBlock($index, $sample);
        }

        $prompt .= "Return full JSON (confidence, analysis with updated snippets, strip_regexes, webview_keywords, title_extractor).\n";
        $prompt .= $this->cleanupConfigSchemaDescription();
        $prompt .= "\nReturn ONLY valid JSON. No markdown.";

        return $prompt;
    }

    /**
     * @param list<array{subject: string, body?: string, text_body?: string, html_body?: string}> $samples
     * @param array{analysis: ?array, config: array, verification: array, feedback?: array} $retryContext
     */
    private function buildCleanupRetryPrompt(array $samples, array $retryContext): string
    {
        $prompt = "Cleanup config FAILED verification. Revise strip_regexes.\n\n";
        $prompt .= "Previous analysis:\n" . json_encode($retryContext['analysis'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        $prompt .= "Previous config:\n" . json_encode($retryContext['config'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        $prompt .= "Verification failure:\n" . ($retryContext['verification']['message'] ?? '') . "\n";

        $issues = $retryContext['verification']['issues'] ?? [];
        if ($issues !== []) {
            $prompt .= "Issues:\n" . json_encode($issues, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        }

        if (!empty($retryContext['feedback'])) {
            $prompt .= "User feedback:\n" . json_encode($retryContext['feedback'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        }

        foreach ($samples as $index => $sample) {
            $prompt .= $this->formatSampleBlock($index, $sample);
        }

        $prompt .= "Fix strip_regexes so noise snippets are gone and content snippets remain.\n";
        $prompt .= $this->cleanupConfigSchemaDescription();
        $prompt .= "\nReturn the full JSON object again. No markdown.";

        return $prompt;
    }

    private function cleanupConfigSchemaDescription(): string
    {
        return <<<'TEXT'
cleanup_config schema (DynamicRegexEmailProcessor):

strip_regexes: PHP preg_replace patterns applied in order to plain text_body.
- Prefer line/block patterns: /^View in browser.*$/imu
- For multi-line blocks: /View in browser.*?Unsubscribe/ims
- Use wildcards for variable URLs/IDs; never hardcode one-off timestamps.
- If HTML entities appear in text, include patterns for escaped and normal forms.

webview_keywords: phrases linking to the web version (for URL extraction).
title_extractor: optional regex with capture group for headline, or null.
TEXT;
    }

    /**
     * @param array<string, mixed> $extracted
     * @return array{strip_regexes: list<string>, webview_keywords: list<string>, title_extractor: ?string}
     */
    private function extractCleanupConfig(array $extracted): array
    {
        return [
            'strip_regexes' => $this->normalizeStringArray($extracted['strip_regexes'] ?? []),
            'webview_keywords' => $this->normalizeStringArray($extracted['webview_keywords'] ?? []),
            'title_extractor' => $this->normalizeString($extracted['title_extractor'] ?? null),
        ];
    }

    /**
     * @param array{strip_regexes: list<string>, webview_keywords: list<string>, title_extractor: ?string} $config
     * @param array<string, mixed> $extracted
     * @param ?array<string, mixed> $analysis
     * @param array{verified: bool, message: string, attempts: int, issues: list<array<string, mixed>>} $verification
     * @return array{
     *     strip_regexes: list<string>,
     *     webview_keywords: list<string>,
     *     title_extractor: ?string,
     *     digest_split_config: ?string,
     *     analysis: ?array<string, mixed>,
     *     verification: array{verified: bool, message: string, attempts: int, issues: list<array<string, mixed>>}
     * }
     */
    private function buildCleanupResult(array $config, array $extracted, ?array $analysis, array $verification): array
    {
        return [
            'strip_regexes' => $config['strip_regexes'],
            'webview_keywords' => $config['webview_keywords'],
            'title_extractor' => $config['title_extractor'],
            'digest_split_config' => $this->encodeDigestSplitConfig($extracted['digest_split_config'] ?? null),
            'analysis' => $analysis,
            'verification' => $verification,
        ];
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
    private function callGeminiJson(string $systemInstruction, string $prompt, string $callLabel = 'email-config'): array
    {
        set_time_limit(300);

        $apiKey = trim((string)($this->configRepo->get(SettingsController::KEY_GEMINI_API_KEY) ?? ''));
        if ($apiKey === '') {
            throw new \RuntimeException('Google Gemini API key is not configured. Please add it under Settings → General.');
        }

        $modelConfigured = trim((string)($this->configRepo->get(GeminiResearcherService::CONFIG_KEY_MODEL) ?? ''));
        $model = $modelConfigured !== '' ? $modelConfigured : self::DEFAULT_MODEL;

        $generationConfig = [
            'responseMimeType' => 'application/json',
            'temperature' => 0.1,
        ];
        if (GeminiResearcherService::usesGemini35Family($model)) {
            $generationConfig['thinkingConfig'] = ['thinkingLevel' => 'MINIMAL'];
        }

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
            'generationConfig' => $generationConfig,
        ];

        $url = self::API_BASE . rawurlencode($model) . ':generateContent';
        $started = microtime(true);
        error_log(sprintf(
            'Seismo EmailGeminiConfigGenerator [%s]: Gemini request start (model=%s, prompt=%d chars)',
            $callLabel,
            $model,
            mb_strlen($prompt)
        ));

        try {
            $response = $this->http->postJson($url, $payload, ['x-goog-api-key' => $apiKey]);
            error_log(sprintf(
                'Seismo EmailGeminiConfigGenerator [%s]: Gemini request finished in %.1fs (HTTP %d)',
                $callLabel,
                microtime(true) - $started,
                $response->status
            ));
            if ($response->status !== 200) {
                throw new \RuntimeException($this->formatGeminiApiError($response, $model));
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
        } catch (HttpClientException $e) {
            error_log('Seismo EmailGeminiConfigGenerator transport: ' . $e->getMessage());
            throw new \RuntimeException(
                'Could not reach Google Gemini (' . $this->sanitizeTransportDetail($e->getMessage()) . '). '
                . 'Model: ' . $model . '. '
                . 'If shell curl works, check PHP-FPM has the curl extension (www-data) and retry after deploy.',
                0,
                $e
            );
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('Seismo EmailGeminiConfigGenerator error: ' . $e->getMessage());
            throw new \RuntimeException('AI Analysis failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function sanitizeTransportDetail(string $message): string
    {
        $message = preg_replace('/([?&]key=)[^&\s)]+/i', '$1[REDACTED]', $message) ?? $message;
        $message = preg_replace('/(x-goog-api-key:\s*)[^\s]+/i', '$1[REDACTED]', $message) ?? $message;

        return trim($message) !== '' ? trim($message) : 'network/TLS';
    }

    private function formatGeminiApiError(Response $response, string $model): string
    {
        $data = json_decode($response->body, true);
        $apiMessage = '';
        if (is_array($data)) {
            $apiMessage = trim((string)($data['error']['message'] ?? ''));
        }

        if ($apiMessage !== '') {
            return 'Gemini API error (HTTP ' . $response->status . ', model ' . $model . '): ' . $apiMessage;
        }

        $snippet = trim(mb_substr($response->body, 0, 240));

        return 'Gemini API call failed (HTTP ' . $response->status . ', model ' . $model . ')'
            . ($snippet !== '' ? ': ' . $snippet : '.');
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
