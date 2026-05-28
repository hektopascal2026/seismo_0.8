<?php

declare(strict_types=1);

namespace Seismo\Core\Mail\Processor;

use Seismo\Core\Mail\EmailBodyDisplay;
use Seismo\Core\Mail\EmailBodyProcessorInterface;

/**
 * Applies statically generated regex cleanup rules and title extraction locally.
 */
final class DynamicRegexEmailProcessor implements EmailBodyProcessorInterface
{
    public const KEY = 'dynamic_regex';

    /**
     * @param array<string, mixed> $config Parsed JSON cleanup_config.
     */
    public function __construct(private array $config)
    {
    }

    public function process(array $row): array
    {
        $subject = trim((string)($row['subject'] ?? ''));
        $body = trim((string)($row['text_body'] ?? $row['body_text'] ?? ''));
        if ($body === '') {
            return $row;
        }

        // 1. Apply strip regexes
        $stripRegexes = $this->config['strip_regexes'] ?? [];
        if (is_array($stripRegexes)) {
            foreach ($stripRegexes as $pattern) {
                $pattern = trim((string)$pattern);
                if ($pattern !== '') {
                    // Use a try-catch or silent error suppressor to avoid crashing on invalid user-supplied regexes
                    try {
                        $cleaned = preg_replace($pattern, '', $body);
                        if (is_string($cleaned)) {
                            $body = $cleaned;
                        }
                    } catch (\Throwable $e) {
                        error_log('Seismo DynamicRegexEmailProcessor regex error: ' . $e->getMessage() . ' for pattern: ' . $pattern);
                    }
                }
            }
        }

        // 2. Normalize and collapse whitespace/newlines
        $body = EmailBodyDisplay::collapseForStorage($body);
        if ($body !== '') {
            $row['text_body'] = $body;
            $row['body_text'] = $body;
        }

        // 3. Extract title if title_extractor is present
        $titleExtractor = trim((string)($this->config['title_extractor'] ?? ''));
        if ($titleExtractor !== '') {
            try {
                if (preg_match($titleExtractor, $body, $matches)) {
                    $extracted = trim($matches[1] ?? $matches[0] ?? '');
                    if ($extracted !== '') {
                        $row['derived_title'] = mb_substr($extracted, 0, 500);
                    }
                }
            } catch (\Throwable $e) {
                error_log('Seismo DynamicRegexEmailProcessor title extractor error: ' . $e->getMessage() . ' for pattern: ' . $titleExtractor);
            }
        }

        return $row;
    }
}
