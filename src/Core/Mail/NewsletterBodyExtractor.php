<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

/**
 * Derive readable plain text from newsletter HTML (Slice 11c).
 *
 * Sanitize full digest HTML, then extract plain text (no Readability / markdown for email).
 */
final class NewsletterBodyExtractor
{
    public static function fromHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $safe = EmailHtmlSanitizer::sanitize($html);
        if ($safe !== '') {
            $text = EmailPlainTextExtractor::fromSanitizedHtml($safe);
            if ($text !== '') {
                return self::postProcess($text);
            }
        }

        return self::postProcess(self::fallbackPlain($html));
    }

    private static function postProcess(string $text): string
    {
        if ($text === '') {
            return '';
        }

        return EmailListingBoilerplateStripper::strip($text, null);
    }

    private static function fallbackPlain(string $html): string
    {
        $clean = preg_replace('/<(style|script)\b[^>]*>.*<\/\\1>/is', '', $html) ?? '';
        $text  = strip_tags($clean);
        $text  = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text  = preg_replace("/\r\n|\r/", "\n", $text) ?? $text;
        $text  = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        $lines = preg_split("/\n/", $text) ?: [];
        $lines = array_map(static fn (string $l): string => trim(preg_replace('/\s+/u', ' ', $l) ?? ''), $lines);

        return trim(implode("\n", array_filter($lines, static fn (string $l): bool => $l !== '')));
    }
}
