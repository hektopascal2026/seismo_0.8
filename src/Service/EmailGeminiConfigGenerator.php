<?php

declare(strict_types=1);

namespace Seismo\Service;

use Seismo\Controller\SettingsController;
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

    public function __construct(
        private readonly SystemConfigRepository $configRepo,
        private readonly BaseClient $http = new BaseClient(60)
    ) {
    }

    /**
     * @param list<array{subject: string, body: string}> $samples
     * @return array{strip_regexes: list<string>, webview_keywords: list<string>, title_extractor: ?string, digest_split_config: ?string}
     */
    public function generateConfig(array $samples): array
    {
        $apiKey = trim((string)($this->configRepo->get(SettingsController::KEY_GEMINI_API_KEY) ?? ''));
        if ($apiKey === '') {
            throw new \RuntimeException('Google Gemini API key is not configured. Please add it under Settings → General.');
        }

        $modelConfigured = trim((string)($this->configRepo->get(GeminiResearcherService::CONFIG_KEY_MODEL) ?? ''));
        $model = $modelConfigured !== '' ? $modelConfigured : self::DEFAULT_MODEL;

        $prompt = $this->buildPrompt($samples);

        $payload = [
            'systemInstruction' => [
                'parts' => [
                    ['text' => "You are an expert email layout analyzer and regular expression engineer.\nYour task is to generate regular expressions to strip repetitive boilerplate/noise lines from emails of a specific newsletter sender, extract web view link keywords, and generate digest split rules if the email contains multiple news stories/sections."]
                ]
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $prompt]]
                ]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.1,
            ]
        ];

        $url = self::API_BASE . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);

        try {
            $response = $this->http->postJson($url, $payload);
            if ($response->status !== 200) {
                throw new \RuntimeException('Gemini API call failed with status ' . $response->status . ': ' . $response->body);
            }

            $data = json_decode($response->body, true);
            $text = trim((string)($data['candidates'][0]['content']['parts'][0]['text'] ?? ''));
            if ($text === '') {
                throw new \RuntimeException('Gemini returned an empty response.');
            }

            // Robust cleaning: Strip markdown code block wraps if returned by the LLM
            if (str_starts_with($text, '```json')) {
                $text = substr($text, 7);
            } elseif (str_starts_with($text, '```')) {
                $text = substr($text, 3);
            }
            if (str_ends_with($text, '```')) {
                $text = substr($text, 0, -3);
            }
            $text = trim($text);

            $extracted = json_decode($text, true);
            if (!is_array($extracted)) {
                throw new \RuntimeException('Failed to parse Gemini output as JSON.');
            }

            $dscVal = $extracted['digest_split_config'] ?? null;
            $dscString = null;
            if (is_array($dscVal)) {
                $dscString = json_encode($dscVal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } elseif (is_string($dscVal) && trim($dscVal) !== '') {
                $dscString = trim($dscVal);
            }

            return [
                'strip_regexes' => $this->normalizeStringArray($extracted['strip_regexes'] ?? []),
                'webview_keywords' => $this->normalizeStringArray($extracted['webview_keywords'] ?? []),
                'title_extractor' => $this->normalizeString($extracted['title_extractor'] ?? null),
                'digest_split_config' => $dscString,
            ];
        } catch (\Throwable $e) {
            error_log('Seismo EmailGeminiConfigGenerator error: ' . $e->getMessage());
            throw new \RuntimeException('AI Analysis failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param list<array{subject: string, body: string}> $samples
     */
    private function buildPrompt(array $samples): string
    {
        $prompt = "We want to clean up emails from a newsletter sender. Below are 5 sample emails from this sender.\n";
        $prompt .= "Analyze their structure, headers, footers, 'view in browser' lines, disclaimers, and tracking noise.\n\n";

        foreach ($samples as $index => $sample) {
            $prompt .= "--- SAMPLE EMAIL #" . ($index + 1) . " ---\n";
            $prompt .= "Subject: " . $sample['subject'] . "\n";
            $prompt .= "Body:\n" . $sample['body'] . "\n\n";
        }

        $prompt .= "Identify repeating structural boilerplate sections (such as header logo/author/navigation blocks, section dividers, subscription promotions, and legal/unsubscribe footers) that appear in multiple samples.\n";
        $prompt .= "Generate robust regular expressions that can match these repeating blocks. Specifically, if a block is enclosed in HTML container elements (e.g., `<div id=\"pl-newsletter-header\">...</div>` or specific tables/paragraphs), write a regex that matches the entire block from the opening element to the closing element using wildcards like `.*?` with case-insensitive `/is` or `/iu` flags.\n";
        $prompt .= "Ensure the regular expressions do not contain fragile specific values (like specific email addresses, tracking IDs, or timestamps) that change between emails; use wildcards or character classes to match them safely.\n";
        $prompt .= "If you detect that the email bodies contain HTML entities (like `&amp;lt;` or `&amp;gt;` or `&amp;quot;`), generate matching regex patterns for both the escaped entity form and the standard HTML/text form to ensure maximum reliability.\n";
        $prompt .= "Generate a JSON response matching the following keys:\n";
        $prompt .= "1. \"strip_regexes\": An array of safe PHP-compatible regular expression strings (e.g., matching lines/sections, including pattern delimiters like /.../ui or /.../is) to replace the boilerplate text with empty strings. Focus on matching noisy start/end blocks precisely.\n";
        $prompt .= "2. \"webview_keywords\": An array of specific words or short phrases used by this sender to link to their online/web version (e.g., 'webversion', 'online lesen', 'webansicht', 'view online').\n";
        $prompt .= "3. \"title_extractor\": (Optional string or null) If the newsletter body starts with a repetitive header prepended to the actual headline, suggest a regex pattern with a capture group (e.g. '/Subject:\\s*(.+)/iu') to extract the true, clean article title from the body. Otherwise, set this to null.\n";
        $prompt .= "4. \"digest_split_config\": (Optional JSON object or null) Analyze if the email body contains multiple distinct news articles or sections. If it is a digest newsletter, provide a split configuration object. If it is a standard HTML newsletter, prefer html_css type (e.g., {\"type\": \"html_css\", \"selector_story\": \"div.story-container\", \"selector_title\": \"h2\", \"selector_body\": \"p\", \"selector_link\": \"a.read-more\"}). If it is plain-text or regex-delimited, specify a regex split config (e.g., {\"type\": \"regex\", \"pattern_split\": \"/\\s*---\\s*/\", \"pattern_title\": \"/^Title:\\s*(.*)/i\", \"pattern_body\": \"/Body:\\s*(.*)/is\", \"pattern_link\": \"/Link:\\s*(https?:\\S+)/i\"}). If the email does NOT contain multiple stories or is a single article, set this to null.\n\n";
        $prompt .= "Return ONLY a valid JSON object. Do not include markdown wraps like ```json.";

        return $prompt;
    }

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

    private function normalizeString(mixed $val): ?string
    {
        if ($val === null) {
            return null;
        }
        $str = trim((string)$val);

        return $str !== '' ? $str : null;
    }
}
