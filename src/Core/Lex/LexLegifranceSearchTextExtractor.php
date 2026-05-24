<?php

declare(strict_types=1);

namespace Seismo\Core\Lex;

/**
 * Build corpus text from Légifrance /search hit payloads (no /consult call).
 */
final class LexLegifranceSearchTextExtractor
{
    /**
     * @param array<string, mixed> $hit
     */
    public static function corpusFromSearchHit(array $hit): ?string
    {
        $parts = [];

        foreach (['text', 'exposeMotif', 'visa'] as $key) {
            $value = trim((string)($hit[$key] ?? ''));
            if ($value !== '') {
                $parts[] = LexPlainText::normalize($value);
            }
        }

        if (!empty($hit['resumePrincipal']) && is_array($hit['resumePrincipal'])) {
            $resume = trim(implode("\n\n", array_map('strval', $hit['resumePrincipal'])));
            if ($resume !== '') {
                $parts[] = LexPlainText::normalize($resume);
            }
        }

        $html = (string)($hit['descriptionFusionHtml'] ?? '');
        if ($html !== '') {
            $parts[] = LexPlainText::fromHtml($html);
        }

        foreach ((array)($hit['sections'] ?? []) as $section) {
            if (!is_array($section)) {
                continue;
            }
            $title = trim((string)($section['title'] ?? ''));
            if ($title !== '') {
                $parts[] = $title;
            }
            foreach ((array)($section['extracts'] ?? []) as $extract) {
                if (!is_array($extract)) {
                    continue;
                }
                $extractTitle = trim((string)($extract['title'] ?? ''));
                if ($extractTitle !== '') {
                    $parts[] = $extractTitle;
                }
                foreach ((array)($extract['values'] ?? []) as $value) {
                    $plain = LexPlainText::fromHtml((string)$value);
                    if ($plain !== '') {
                        $parts[] = $plain;
                    }
                }
            }
        }

        $parts = array_values(array_unique(array_filter($parts)));
        if ($parts === []) {
            return null;
        }

        $plain = trim(implode("\n\n", $parts));
        if ($plain === '') {
            return null;
        }
        if (strlen($plain) > LexLegifranceContentFetcher::MAX_CONTENT_BYTES) {
            $plain = substr($plain, 0, LexLegifranceContentFetcher::MAX_CONTENT_BYTES) . "\n\n[truncated]";
        }

        return $plain;
    }
}
