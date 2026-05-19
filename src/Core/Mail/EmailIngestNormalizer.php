<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

/**
 * Apply ingest-time body normalization before DB write.
 */
final class EmailIngestNormalizer
{
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function normalizeBodies(array $row): array
    {
        $html = trim((string)($row['html_body'] ?? $row['body_html'] ?? ''));
        $plain = trim((string)($row['text_body'] ?? $row['body_text'] ?? ''));

        if ($html !== '' && ($plain === '' || self::plainLooksLikeBoilerplate($plain))) {
            $extracted = NewsletterBodyExtractor::fromHtml($html);
            if ($extracted !== '') {
                $plain = $extracted;
            }
        } elseif ($plain === '' && $html !== '') {
            $plain = NewsletterBodyExtractor::fromHtml($html);
        }

        if ($plain !== '') {
            $row['text_body'] = $plain;
            $row['body_text'] = $plain;
        }
        if ($html !== '') {
            $row['html_body'] = $html;
            $row['body_html'] = $html;
        }

        return $row;
    }

    private static function plainLooksLikeBoilerplate(string $plain): bool
    {
        $lower = mb_strtolower($plain, 'UTF-8');

        return str_contains($lower, 'view in browser')
            || str_contains($lower, 'view this email')
            || str_contains($lower, 'requires a modern e-mail reader')
            || str_contains($lower, 'requires a modern email reader')
            || (strlen($plain) < 400 && preg_match('#https?://#', $plain) === 1);
    }
}
