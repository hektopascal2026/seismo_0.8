<?php

declare(strict_types=1);

namespace Seismo\Core\Fetcher;

use Seismo\Core\Mail\NewsletterBodyExtractor;

/**
 * Derive plain text from HTML mail bodies (delegates to Slice 11 extractor).
 */
final class EmailHtmlPlainText
{
    public static function fromHtml(string $html): string
    {
        return NewsletterBodyExtractor::fromHtml($html);
    }
}
