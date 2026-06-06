<?php

declare(strict_types=1);

namespace Seismo\Service;

use Seismo\Controller\SettingsController;
use Seismo\Core\Mail\CleanupConfigVerifier;
use Seismo\Core\Mail\DigestSplitConfigNormalizer;
use Seismo\Core\Mail\DigestSplitSelectorProber;
use Seismo\Core\Mail\DigestSplitStructureHint;
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
    public const GEMINI_SPLIT_SAMPLE_COUNT = 5;
    private const HTTP_TIMEOUT_SECONDS = 120;
    private const PROMPT_BODY_MAX_CHARS = 8000;
    private const PROMPT_HTML_MAX_CHARS = 15000;

    private const SPLIT_V1_SYSTEM_INSTRUCTION = <<<'TEXT'
You are an expert DOM Engineering Agent specializing in parsing email template layouts.
Your goal is to detect digest structures in newsletters and generate precise, resilient CSS selector rules.

You are highly familiar with compilation frameworks and layouts like:
- MJML (e.g. div.mj-column-per-100, mj-column-px)
- TYPO3 / punkt4 (e.g. div.csc-frame-default, nested table structures)
- Mailchimp, Substack, Campaign Monitor, and Ghost templates.

CRITICAL DESIGN PRINCIPLES:
1. Target the absolute outermost wrapper for each story card. Never target individual paragraphs or headings as the story wrapper.
2. Favor semantic classes (e.g., .story, .article, .item, .csc-frame-default) or repeated table containers.
3. Avoid fragile ID selectors containing dynamic hashes or numeric counters (e.g., #body_12345).
4. Rely on tag hierarchies only as a last resort, keeping them as short as possible.
5. If the newsletter is not a digest (i.e. single main story or no repeated article cards), set is_digest to false.
TEXT;

    private const SPLIT_REFINE_SYSTEM_INSTRUCTION = <<<'TEXT'
You are an expert DOM Engineering Agent specializing in parsing email template layouts.
Refine digest split_rules to exclude noise blocks while keeping story cards. Use exclude_selectors (.class, #id, tag) or narrow story_selector to drop noise blocks.
TEXT;

    private const SPLIT_SIMPLE_SYSTEM_INSTRUCTION = <<<'TEXT'
You are an expert DOM Engineering Agent specializing in parsing email template layouts.
Detect digest HTML structure and return runnable split_rules only.
Prefer simple class-based selectors (.csc-frame-default, table wrappers) over fragile inline-style attribute selectors.
TEXT;

    private const CLEANUP_SYSTEM_INSTRUCTION = <<<'TEXT'
You are a Newsletter Editorial Architect specializing in content extraction, text normalization, and PHP regular expressions.
Your goal is to strip boilerplate and noise from plain-text email bodies while perfectly preserving the core articles.

CLASSIFICATION RULES:
- KEEP: Headlines, article paragraphs, bylines, direct links, datelines, quotes, and core analysis.
- STRIP: Mastheads, navigation links, "view in browser", dynamic ads, social sharing widgets, empty lines, copyright lines, unsubscribe/preference links, and tracking headers.

REGEX SAFETY CONTRACT:
1. Ensure your PHP preg_replace patterns use robust delimiters like /iu or /is.
2. Match whole noise blocks by anchoring or using line boundaries (e.g., /^View in browser.*$/imu) rather than matching partial text.
3. Use wildcard characters for variable URL parameters, tokens, or dates (e.g., do not hardcode a specific date or token).
4. Never generate overly broad patterns that could match article paragraphs or sentences.
5. Use \s+ or \s* instead of literal spaces to match variable whitespace (including double spaces and line breaks) and prevent failure due to spacing variations.
TEXT;

    public function __construct(
        private readonly SystemConfigRepository $configRepo,
        private readonly BaseClient $http = new BaseClient(self::HTTP_TIMEOUT_SECONDS),
        private readonly DigestSplitVerifier $splitVerifier = new DigestSplitVerifier(),
        private readonly CleanupConfigVerifier $cleanupVerifier = new CleanupConfigVerifier(),
        private readonly DigestSplitStructureHint $structureHint = new DigestSplitStructureHint(),
        private readonly DigestSplitSelectorProber $selectorProber = new DigestSplitSelectorProber(),
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
            $lastAnalysis = $this->extractSplitAnalysis($extracted);

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
            $lastAnalysis = $this->extractSplitAnalysis($extracted);

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
     * @param ?string $keepText
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
    public function generateSplitConfig(array $samples, ?string $keepText = null): array
    {
        $extracted = $this->callGeminiSplitV1($samples, $keepText);
        $debugLog = "--- GEMINI SPLIT CONFIG DEBUG ---\n";
        $debugLog .= "Extracted raw: " . json_encode($extracted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        if ($extracted === null) {
            $debugLog .= "Extracted is null\n";
            file_put_contents('/tmp/gemini_split_debug.log', $debugLog);
            return [
                'digest_split_config' => null,
                'analysis' => null,
                'verification' => [
                    'verified' => true,
                    'expected_counts' => [],
                    'actual_counts' => [],
                    'attempts' => 1,
                    'message' => 'Not a multi-story digest — no split config needed.',
                ],
                'debug_log' => $debugLog,
            ];
        }

        $normalized = DigestSplitConfigNormalizer::normalize($extracted, rejectFragileSelectors: false);
        $debugLog .= "Normalized: " . json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        if ($normalized !== null) {
            $previewCount = $this->countPreviewCards($samples, $normalized);
            $debugLog .= "Preview count: " . $previewCount . "\n";
            if ($previewCount > 0) {
                file_put_contents('/tmp/gemini_split_debug.log', $debugLog);
                return [
                    'digest_split_config' => json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'analysis' => null,
                    'verification' => [
                        'verified' => true,
                        'expected_counts' => [],
                        'actual_counts' => [$previewCount],
                        'attempts' => 1,
                        'message' => 'Analysis complete — ' . $previewCount . ' card(s) in preview.',
                    ],
                    'debug_log' => $debugLog,
                ];
            }
        } else {
            $debugLog .= "Normalized is null\n";
        }

        $probed = $this->tryProbedSplitConfig($samples, 'HTML template probe');
        $debugLog .= "Probed: " . json_encode($probed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents('/tmp/gemini_split_debug.log', $debugLog);

        if ($probed !== null) {
            return [
                'digest_split_config' => json_encode($probed['config'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'analysis' => null,
                'verification' => $probed['verification'],
                'debug_log' => $debugLog,
            ];
        }

        return [
            'digest_split_config' => null,
            'analysis' => null,
            'verification' => [
                'verified' => false,
                'expected_counts' => [],
                'actual_counts' => [0],
                'attempts' => 1,
                'message' => 'Gemini config did not produce preview cards — try manual selectors or mark noise blocks and refine.',
            ],
            'debug_log' => $debugLog,
        ];
    }

    /**
     * @param list<array{subject: string, body?: string, text_body?: string, html_body?: string}> $samples
     * @param ?string $keepText
     * @return ?array<string, mixed>
     */
    private function callGeminiSplitV1(array $samples, ?string $keepText = null): ?array
    {
        $extracted = $this->callGeminiJsonOrNull(
            self::SPLIT_V1_SYSTEM_INSTRUCTION,
            $this->buildSplitV1Prompt($samples, $keepText),
            'split v1'
        );

        if ($extracted === null || $extracted === []) {
            return null;
        }

        if (isset($extracted['is_digest']) && empty($extracted['is_digest'])) {
            return null;
        }

        if (isset($extracted['split_rules']) && !is_array($extracted['split_rules'])) {
            return null;
        }

        return $extracted;
    }

    /**
     * @param list<array{subject: string, body?: string, text_body?: string, html_body?: string}> $samples
     * @param ?string $keepText
     */
    private function buildSplitV1Prompt(array $samples, ?string $keepText = null): string
    {
        $prompt = "We want to configure multi-story digest splitting for a newsletter. Below are "
            . count($samples) . " sample emails from this newsletter.\n\n";

        foreach ($samples as $index => $sample) {
            $prompt .= $this->formatSampleBlock($index, $sample);
        }

        if ($keepText !== null && trim($keepText) !== '') {
            $prompt .= "CRITICAL SELECTION FILTER:\n";
            $prompt .= "The user specifically wants to extract and keep the following content from the email template:\n";
            $prompt .= "========================================\n";
            $prompt .= trim($keepText) . "\n";
            $prompt .= "========================================\n\n";
            $prompt .= "Note: The pasted content above may contain multiple distinct articles/stories separated by delimiters (like '---', '___', '===', or similar divider lines). Each separated section represents a single child story card you want to extract.\n";
            $prompt .= "CRITICAL: If a single story section contains multiple paragraphs, headings, or fact boxes (such as 'Das ist passiert' followed by 'Darum ist es wichtig'), these MUST all remain together within the SAME single story card. Do NOT select individual paragraphs or fact blocks as separate stories. Find the outermost common ancestor container/wrapper element in the HTML that groups all parts of a single story together.\n";
            $prompt .= "For newsletters (especially table-heavy layouts like NZZ), stories are typically built inside repeating `table` elements. Never select individual paragraphs (`p`), headings (`h1`-`h4`), or inner cells (`td`) as the `story_selector` if they are children of a parent story table. Instead, target the outermost `table` or `div` container that wraps the entire story block.\n\n";
            $prompt .= "The user has confirmed this email is a multi-story digest. Suggest a JSON split configuration matching our split schema:\n";
        } else {
            $prompt .= "Determine if these emails contain multiple distinct news articles/sections (a digest). If they do not, return null.\n";
            $prompt .= "If they do, suggest a JSON split configuration matching our split schema:\n";
        }

        $prompt .= "- For HTML emails: {\"split_method\": \"html_selector\", \"story_selector\": \"CSS selector for story wrapper\", \"title_selector\": \"CSS selector for title relative to story node\", \"body_selector\": \"CSS selector for body content relative to story node\", \"link_selector\": \"CSS selector for story URL relative to story node\"}\n";
        $prompt .= "- For plain text or regex-delimited emails: {\"split_method\": \"regex_split\", \"split_pattern\": \"PHP regex delimiter\", \"title_pattern\": \"regex pattern\", \"body_pattern\": \"regex pattern\", \"link_pattern\": \"regex pattern\"}\n\n";
        $prompt .= 'Return ONLY the JSON split config object (or null). Do not include markdown wraps like ```json.';

        return $prompt;
    }

    /**
     * @param list<array{subject: string, body?: string, text_body?: string, html_body?: string}> $samples
     * @param array{is_digest: true, split_rules: array<string, mixed>} $config
     */
    private function countPreviewCards(array $samples, array $config): int
    {
        if ($samples === []) {
            return 0;
        }

        $splitter = new \Seismo\Core\Mail\EmailDigestSplitterService();
        $html = (string)($samples[0]['html_body'] ?? '');
        $text = (string)($samples[0]['text_body'] ?? $samples[0]['body'] ?? '');

        return count($splitter->split($html, $text, $config));
    }

    /**
     * @param list<array{subject: string, body?: string, text_body?: string, html_body?: string}> $samples
     * @return ?array{config: array{is_digest: true, split_rules: array<string, string>}, verification: array<string, mixed>}
     */
    private function tryProbedSplitConfig(array $samples, string $labelPrefix): ?array
    {
        $html = trim((string)($samples[0]['html_body'] ?? ''));
        if ($html === '') {
            return null;
        }

        $probe = $this->selectorProber->probeBest($html);
        if ($probe === null) {
            return null;
        }

        $label = $labelPrefix . ' (' . (string)($probe['label'] ?? 'template') . ')';
        unset($probe['score'], $probe['label']);
        $previewCount = $this->countPreviewCards($samples, $probe);
        if ($previewCount === 0) {
            return null;
        }

        return [
            'config' => $probe,
            'verification' => [
                'verified' => true,
                'expected_counts' => [],
                'actual_counts' => [$previewCount],
                'attempts' => 0,
                'message' => 'Applied local ' . $label . ': ' . $previewCount . ' card(s) on sample 1.',
            ],
        ];
    }

    /**
     * One-shot layout-analyzer fallback (pre-editor prompt style).
     *
     * @param list<array{subject: string, body?: string, text_body?: string, html_body?: string}> $samples
     * @return ?array{config: array{is_digest: true, split_rules: array<string, string>}, verification: array<string, mixed>}
     */
    private function trySimpleSplitFallback(array $samples): ?array
    {
        $extracted = $this->callGeminiJson(
            self::SPLIT_SIMPLE_SYSTEM_INSTRUCTION,
            $this->buildSimpleSplitPrompt($samples),
            'split simple fallback'
        );

        if (empty($extracted['is_digest'])) {
            return null;
        }

        $normalized = DigestSplitConfigNormalizer::normalize($extracted);
        if ($normalized === null) {
            return null;
        }

        if (
            $this->structureHint->samplesHaveHtml($samples)
            && ($normalized['split_rules']['split_method'] ?? '') === 'regex_split'
        ) {
            return null;
        }

        $check = $this->splitVerifier->verify($samples, $normalized, []);
        if (!$check['verified']) {
            return null;
        }

        return [
            'config' => $normalized,
            'verification' => [
                'verified' => true,
                'expected_counts' => [],
                'actual_counts' => $check['actual_counts'],
                'attempts' => 0,
                'message' => 'Simple layout-analyzer fallback: ' . ($check['actual_counts'][0] ?? 0) . ' card(s) on sample 1.',
            ],
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
        $rawKeepCount = $this->countFeedbackVerdict($feedback, 'keep');
        $noiseCount = $this->countFeedbackVerdict($feedback, 'noise');

        // Check if user manually glued some adjacent cards together
        $glueToggles = 0;
        foreach ($feedback['blocks'] ?? [] as $block) {
            if (!empty($block['glue_with_next'])) {
                $glueToggles++;
            }
        }

        if ($noiseCount === 0 && $glueToggles === 0) {
            throw new \InvalidArgumentException('Mark at least one preview block as noise or merge blocks together before refining.');
        }

        // Expected keepCount decreases by 1 for each glued connection (since 2 blocks merge to 1)
        $keepCount = max(1, $rawKeepCount - $glueToggles);

        $currentConfig = DigestSplitConfigNormalizer::mergeNoiseFeedback($currentConfig, $feedback);
        $localCount = $this->countSplitStoriesOnSample($samples, $currentConfig);
        if ($localCount === $keepCount && $localCount > 0 && $glueToggles === 0) {
            return [
                'digest_split_config' => json_encode($currentConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'analysis' => [
                    'refinement' => 'exclude_titles',
                    'noise_blocks_excluded' => $noiseCount,
                ],
                'verification' => [
                    'verified' => true,
                    'expected_counts' => [$keepCount],
                    'actual_counts' => [$localCount],
                    'attempts' => 0,
                    'message' => 'Refined locally: excluded ' . $noiseCount . ' noise block(s) by title.',
                ],
            ];
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
                self::SPLIT_REFINE_SYSTEM_INSTRUCTION,
                $prompt,
                'split refine attempt ' . $attempts . '/' . (self::MAX_SPLIT_RETRIES + 1)
            );
            $lastAnalysis = $this->extractSplitAnalysis($extracted);

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
            $check = $this->splitVerifier->verify($samples, $normalized, []);
            $actual = (int)($check['actual_counts'][0] ?? 0);
            $refineOk = $check['verified'] && $actual === $keepCount;
            $verification = [
                'verified' => $refineOk,
                'expected_counts' => [$keepCount],
                'actual_counts' => $check['actual_counts'],
                'attempts' => $attempts,
                'message' => $refineOk
                    ? 'Refined: ' . $keepCount . ' card(s) kept, ' . $noiseCount . ' noise block(s) excluded.'
                    : ($actual === 0 ? $check['message'] : 'Refined split produced ' . $actual . ' cards, expected ' . $keepCount . ' kept.'),
            ];

            if ($refineOk) {
                $normalized = DigestSplitConfigNormalizer::mergeNoiseFeedback($normalized, $feedback);

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
                'verification' => [
                    'message' => $verification['message'],
                    'actual_counts' => $check['actual_counts'],
                    'mismatches' => $check['mismatches'],
                ],
            ];
        }

        $lastConfig = DigestSplitConfigNormalizer::mergeNoiseFeedback($lastConfig, $feedback);
        $finalCount = $this->countSplitStoriesOnSample($samples, $lastConfig);
        if ($finalCount === $keepCount && $finalCount > 0) {
            $verification['verified'] = true;
            $verification['actual_counts'] = [$finalCount];
            $verification['message'] = 'Refined: ' . $keepCount . ' card(s) kept after excluding '
                . $noiseCount . ' noise block(s).';
        }

        return [
            'digest_split_config' => json_encode($lastConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'analysis' => $lastAnalysis,
            'verification' => $verification,
        ];
    }

    /**
     * @param list<array{subject: string, body?: string, text_body?: string, html_body?: string}> $samples
     * @param array{is_digest: true, split_rules: array<string, mixed>} $config
     */
    private function countSplitStoriesOnSample(array $samples, array $config): int
    {
        if ($samples === []) {
            return 0;
        }
        $sample = $samples[0];
        $splitter = new \Seismo\Core\Mail\EmailDigestSplitterService();

        return count($splitter->split(
            (string)($sample['html_body'] ?? ''),
            (string)($sample['text_body'] ?? $sample['body'] ?? ''),
            $config,
        ));
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

        // Extract and format glue hints if present
        $glueInstructions = "";
        $blocks = $feedback['blocks'] ?? [];
        foreach ($blocks as $idx => $block) {
            if (!empty($block['glue_with_next'])) {
                $nextIdx = $idx + 1;
                $glueInstructions .= "- Block #" . ($idx + 1) . " (\"" . ($block['title'] ?? '') . "\") and Block #" . ($nextIdx + 1) . " (\"" . ($blocks[$nextIdx]['title'] ?? '') . "\") must be MERGED into a single card.\n";
            }
        }

        if ($glueInstructions !== "") {
            $prompt .= "CRITICAL MERGE REQUIREMENT:\n";
            $prompt .= "The user has flagged that some adjacent blocks are part of the SAME story and should be kept together:\n";
            $prompt .= $glueInstructions;
            $prompt .= "This means your generated story_selector is too narrow (splitting a single article into multiple items). You MUST broaden or change the story_selector to target a wider ancestor container (e.g. table instead of td) so these sections remain together as one block.\n\n";
        }

        $prompt .= "Current split_rules produced too many matches. Revise so ONLY the kept blocks survive.\n";
        $prompt .= "Target: exactly {$keepCount} card(s) on sample #1.\n\n";
        $prompt .= "Current config:\n" . json_encode($currentConfig, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        $prompt .= "User feedback on preview blocks:\n" . json_encode($feedback, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        $prompt .= "Strategies (pick what fits):\n";
        $prompt .= "- Broaden story_selector to a parent tag (like `table` instead of `td`) if adjacent blocks need to be kept together.\n";
        $prompt .= "- Narrow story_selector to match only real story wrappers (e.g. tr.story not every tr) if there is unwanted noise.\n";
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
     * @param array<string, mixed> $extracted
     * @return ?array<string, mixed>
     */
    private function extractSplitAnalysis(array $extracted): ?array
    {
        if (is_array($extracted['analysis'] ?? null)) {
            return $extracted['analysis'];
        }

        $observation = trim((string)($extracted['structural_observation'] ?? ''));
        if ($observation !== '') {
            return ['structural_observation' => $observation];
        }

        return null;
    }

    /**
     * @param list<array{subject: string, body?: string, text_body?: string, html_body?: string}> $samples
     */
    private function buildSplitPrompt(array $samples): string
    {
        $hasHtml = $this->structureHint->samplesHaveHtml($samples);

        $prompt = "Configure multi-story digest splitting for this newsletter sender.\n\n";
        $prompt .= "Inspect html_body for repeated story wrapper elements (tables, div.csc-frame-default, article blocks).\n";
        $prompt .= "Return split_rules that our PHP splitter can run — focus on DOM structure, not editorial topic counts.\n\n";

        if ($hasHtml) {
            $prompt .= "REQUIRED: samples include html_body → use split_method \"html_selector\" only.\n";
            $prompt .= "Carefully analyze the repeated elements detected in the scan below. Integrate or prioritize them for story_selector if they match the article cards.\n\n";
            $hintBlock = $this->structureHint->formatForPrompt($samples);
            if ($hintBlock !== '') {
                $prompt .= $hintBlock;
            }
        }

        foreach ($samples as $index => $sample) {
            $prompt .= $this->formatSampleBlock($index, $sample);
        }

        $prompt .= "Return JSON:\n";
        $prompt .= "{\n";
        $prompt .= "  \"is_digest\": true|false,\n";
        $prompt .= "  \"confidence\": \"high\"|\"medium\"|\"low\",\n";
        $prompt .= "  \"structural_observation\": \"one sentence: wrapper pattern you chose\",\n";
        $prompt .= "  \"split_rules\": { ... }  // omit or null when is_digest is false\n";
        $prompt .= "}\n\n";
        $prompt .= $this->splitConfigSchemaDescription();
        $prompt .= "\nReturn ONLY valid JSON. No markdown.";

        return $prompt;
    }

    /**
     * @param list<array{subject: string, body?: string, text_body?: string, html_body?: string}> $samples
     */
    private function buildSimpleSplitPrompt(array $samples): string
    {
        $prompt = "These sample emails may be a multi-story digest. Find repeated story wrappers in the HTML.\n\n";

        foreach ($samples as $index => $sample) {
            $prompt .= $this->formatSampleBlock($index, $sample);
        }

        $prompt .= "If NOT a digest, return {\"is_digest\": false}.\n";
        $prompt .= "If a digest, return {\"is_digest\": true, \"split_rules\": { ... }} using our schema.\n";
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

        if (!empty($retryContext['force_html_selector'])) {
            $prompt .= "MANDATORY FIX: Use split_method \"html_selector\". regex_split on HTML emails returns zero cards.\n\n";
            $hintBlock = $this->structureHint->formatForPrompt($samples);
            if ($hintBlock !== '') {
                $prompt .= $hintBlock;
            }
        }

        $prompt .= "Previous split_rules:\n" . json_encode($retryContext['config']['split_rules'] ?? $retryContext['config'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        $prompt .= "Verification failure:\n" . ($retryContext['verification']['message'] ?? '') . "\n";

        $mismatches = $retryContext['verification']['mismatches'] ?? [];
        if ($mismatches !== []) {
            $prompt .= "Issues:\n" . json_encode($mismatches, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        }

        $prompt .= "Sample emails for reference:\n";
        foreach ($samples as $index => $sample) {
            $prompt .= $this->formatSampleBlock($index, $sample);
        }

        $prompt .= "Fix split_rules so the PHP splitter extracts real story cards (title + link + body) from html_body.\n";
        $prompt .= "Prefer stable class/table wrappers. If story_selector matches zero nodes, broaden; if noise, narrow or add exclude_selectors.\n";
        $prompt .= $this->splitConfigSchemaDescription();
        $prompt .= "\nReturn the full JSON object again (is_digest, confidence, structural_observation, split_rules). No markdown.";

        return $prompt;
    }

    private function splitConfigSchemaDescription(): string
    {
        return <<<'TEXT'
split_rules schema (EmailDigestSplitterService):

When html_body is non-empty in samples: REQUIRED split_method = html_selector.
regex_split runs on text_body only and ignores HTML — it will fail on HTML digests.

HTML (required when html_body has structure):
{
  "split_method": "html_selector",
  "story_selector": "tr.story-row",
  "title_selector": "h2 a",
  "link_selector": "h2 a",
  "body_selector": "td.body",
  "exclude_selectors": [".masthead", "#footer"]
}

TYPO3/punkt4 digests (class csc-frame-default plus nested table stories):
{
  "split_method": "html_selector",
  "story_selector": "div.csc-frame-default, table table table table td",
  "title_selector": "h1.csc-firstHeader, a",
  "link_selector": "a",
  "body_selector": "p.bodytext, td"
}

MJML digests (class mj-column-per-100):
{
  "split_method": "html_selector",
  "story_selector": "div.mj-column-per-100 table",
  "title_selector": "a",
  "link_selector": "a",
  "body_selector": "td, p"
}

exclude_selectors (optional): simple .class, #id, tag, or tag.class selectors.
When a matched story node itself matches an exclude selector, that match is skipped.

Plain text ONLY (no html_body — regex runs on text_body):
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
            $sanitizedHtml = \Seismo\Core\Mail\EmailHtmlSanitizer::sanitize($html);
            $block .= "HTML Body (primary source for digest structure";
            if (mb_strlen($sanitizedHtml) > self::PROMPT_HTML_MAX_CHARS) {
                $block .= ', truncated';
            }
            $block .= "):\n" . $this->truncateForPrompt($sanitizedHtml, self::PROMPT_HTML_MAX_CHARS) . "\n";
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
     * @return ?array<string, mixed>
     */
    private function callGeminiJsonOrNull(string $systemInstruction, string $prompt, string $callLabel = 'email-config'): ?array
    {
        try {
            return $this->callGeminiJson($systemInstruction, $prompt, $callLabel);
        } catch (\RuntimeException $e) {
            if (
                str_contains($e->getMessage(), 'empty response')
                || str_contains($e->getMessage(), 'Failed to parse Gemini output')
            ) {
                return null;
            }

            throw $e;
        }
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
