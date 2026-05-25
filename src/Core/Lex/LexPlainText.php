<?php

declare(strict_types=1);

namespace Seismo\Core\Lex;

use Seismo\Core\PlainTextNormalizer;

/**
 * Plain-text helpers for legal corpus fields (`description` synopsis vs `content` body).
 */
final class LexPlainText
{
    public const DEFAULT_SYNOPSIS_CHARS = 2000;

    public static function fromHtml(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return self::normalize($text);
    }

    public static function normalize(string $text): string
    {
        return PlainTextNormalizer::forIngest($text);
    }

    public static function truncate(?string $text, int $maxChars = self::DEFAULT_SYNOPSIS_CHARS): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $maxChars)) . '…';
    }
}
