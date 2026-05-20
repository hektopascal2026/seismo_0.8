<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

use DOMDocument;
use DOMElement;

/**
 * Find the newsletter "view in browser" / Webansicht link from HTML or plain text.
 */
final class EmailWebViewUrlExtractor
{
    public static function fromHtml(string $html): ?string
    {
        $html = trim($html);
        if ($html === '') {
            return null;
        }

        $prev = libxml_use_internal_errors(true);
        $dom  = new DOMDocument();
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="seismo-mail-root">' . $html . '</div>',
            LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            return null;
        }

        $root = $dom->getElementById('seismo-mail-root');
        if (!$root instanceof DOMElement) {
            return null;
        }

        foreach ($root->getElementsByTagName('a') as $anchor) {
            if (!$anchor instanceof DOMElement) {
                continue;
            }
            $href = self::normalizeHref($anchor->getAttribute('href'));
            if ($href === null) {
                continue;
            }
            $label = trim($anchor->textContent . ' ' . $anchor->getAttribute('title'));
            if (self::labelLooksLikeWebView($label)) {
                return $href;
            }
        }

        return null;
    }

    public static function fromPlainText(string $plain): ?string
    {
        $plain = trim($plain);
        if ($plain === '') {
            return null;
        }

        foreach (preg_split("/\r\n|\n|\r/", $plain) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line === '' || !self::labelLooksLikeWebView($line)) {
                continue;
            }
            if (preg_match('#https?://[^\s<>"\'\)]+#i', $line, $m) === 1) {
                $url = self::normalizeHref($m[0]);

                return $url;
            }
        }

        return null;
    }

    private static function labelLooksLikeWebView(string $text): bool
    {
        $lower = mb_strtolower(trim($text), 'UTF-8');
        if ($lower === '') {
            return false;
        }

        foreach (self::webViewPhrases() as $phrase) {
            if (str_contains($lower, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function webViewPhrases(): array
    {
        return [
            'view this email in your browser',
            'view this e-mail in your browser',
            'view in browser',
            'view in your browser',
            'view online',
            'view this email online',
            'view this e-mail online',
            'read online',
            'web version',
            'webversion',
            'online version',
            'online-version',
            'email im browser',
            'e-mail im browser',
            'im browser ansehen',
            'im web-browser ansehen',
            'webansicht',
            'web-ansicht',
            'online ansehen',
            'online lesen',
            'probleme mit der anzeige',
            'probleme bei der anzeige',
            'having trouble viewing this email',
            'having trouble viewing this e-mail',
            'if you cannot view this email',
            'if you can\'t read this email',
            'click here to view',
            'voir cet e-mail dans votre navigateur',
            'voir cet email dans votre navigateur',
            'version en ligne',
        ];
    }

    private static function normalizeHref(string $href): ?string
    {
        $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($href === ''
            || str_starts_with(strtolower($href), 'mailto:')
            || str_starts_with(strtolower($href), 'tel:')
            || str_starts_with(strtolower($href), 'javascript:')
        ) {
            return null;
        }
        if (!preg_match('#^https?://#i', $href)) {
            return null;
        }

        return $href;
    }
}
