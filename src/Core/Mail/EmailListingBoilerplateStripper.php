<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

/**
 * Strips common newsletter / press-mail noise from the start of a plain body:
 * “view in browser” lines, image-display warnings, then Admin.ch-style meta, repeated
 * subject, and dateline. Used at ingest, recipe rescoring, Magnitu export, and
 * dashboard when {@see strip_listing_boilerplate} is enabled for a subscription.
 */
final class EmailListingBoilerplateStripper
{
    public static function strip(string $body, ?string $subject = null): string
    {
        $body = trim($body);
        if ($body === '') {
            return $body;
        }
        $body = self::stripLeadingNewsletterShellLines($body);
        $body = trim($body);
        if ($body === '') {
            return $body;
        }
        $body = (string) preg_replace(
            '/^News Service Bund\s+www\.news\.admin\.ch\s+[^\|]+\s*\|\s*\d{1,2}\.\d{1,2}\.\d{1,4}\s+/u',
            '',
            $body,
            1
        );
        $body = trim($body);
        if ($body === '') {
            return $body;
        }
        $sub = trim((string) $subject);
        if ($sub !== '' && $sub !== '(No subject)' && str_starts_with($body, $sub)) {
            $body = trim(mb_substr($body, mb_strlen($sub)));
        }
        if ($body !== '' && str_contains($body, ',')) {
            $body = (string) preg_replace(
                '/^(.+),\s*\d{1,2}\.\d{1,2}\.\d{1,4}\s*-\s*/us',
                '',
                $body,
                1
            );
        }

        return trim($body);
    }

    /**
     * Remove leading line blocks that are clearly shell UI (EN/DE), not article text.
     */
    private static function stripLeadingNewsletterShellLines(string $body): string
    {
        $prefixes = self::newsletterLinePrefixes();
        for ($i = 0; $i < 50; $i++) {
            if ($body === '') {
                return $body;
            }
            $parts = preg_split("/\r\n|\n|\r/", $body, 2) ?: [''];
            $first = (string)($parts[0] ?? '');
            $rest  = array_key_exists(1, $parts) ? (string)$parts[1] : null;
            $t     = trim($first);
            if ($t === '') {
                if ($rest === null) {
                    return '';
                }
                $body = ltrim($rest, "\r\n");
                continue;
            }
            $lower = mb_strtolower($t, 'UTF-8');
            $drop  = false;
            foreach ($prefixes as $p) {
                if (str_starts_with($lower, $p)) {
                    $drop = true;
                    break;
                }
            }
            if (!$drop) {
                return $body;
            }
            if ($rest === null) {
                return '';
            }
            $body = ltrim($rest, "\r\n");
        }

        return $body;
    }

    /**
     * Lowercase line-start substrings. Only lines whose trimmed text starts with one
     * of these are removed (repeatedly from the top of the body).
     *
     * @return list<string>
     */
    private static function newsletterLinePrefixes(): array
    {
        return [
            // English — view in browser / alternate version
            'view this email in your browser',
            'view this e-mail in your browser',
            'having trouble viewing this email',
            'having trouble viewing this e-mail',
            'having trouble reading this email',
            'having trouble reading this e-mail',
            'read online',
            'if you are unable to see the message',
            "if you can't read this email",
            "if you can't read this e-mail",
            "if you can't read this e-mail in your",
            'this email is best viewed in your browser',
            'this e-mail is best viewed in your browser',
            'this email requires a modern e-mail reader',
            'this email requires a modern email reader',
            // English — image display
            'if images are not displaying',
            'if the images in this email are not',
            'if the images in this e-mail are not',
            "if you don't see this email,",
            "if you don't see this e-mail,",
            'if images in this message are not',
            "if images in this e-mail are not",
            "images in this message can't be displayed",
            "images in this e-mail can't be displayed",
            'images in this e-mail are hidden',
            'if you cannot see the images,',
            // German — im Browser ansehen / Onlineversion
            'e-mail im browser',
            'email im browser',
            'diese e-mail im browser',
            'diese e-mail in ihrem browser ansehen',
            'diese e-mail in deinem browser ansehen',
            'diese e-mail in ihrem web-browser',
            'diese e-mail in ihrem web browser',
            'im browser ansehen',
            'im web-browser ansehen',
            'e-mail in ihrem browser anzeigen',
            'online-version dieser e-mail',
            'online version dieser e-mail',
            'probleme mit der anzeige dieser e-mail',
            'probleme bei der anzeige dieser e-mail',
            'probleme mit der anzeige dieses newsletters',
            'klicken sie hier, um die online',
            'hier klicken für die online',
            'webversion dieser e-mail',
            'webversion dieser e-mail anzeigen',
            'online ansehen',
            'online lesen',
            'im web lesen',
            // German — Bilder
            'wenn die bilder in dieser e-mail',
            'wenn die bilder in dieser email',
            'wenn die bilder in diesem newsletter',
            'wenn die bilder in dieser e-mail nicht',
            'wenn die bilder nicht richtig',
            'wenn die bilder nicht korrekt',
            'wenn die bilder unten nicht erscheinen',
            'wenn unten stehende bilder fehlen',
            'wenn unten stehende bilder nicht erscheinen',
            'falls die bilder nicht erscheinen',
            'falls die bilder nicht angezeigt werden',
            'bilder werden nicht richtig angezeigt',
            'bilder werden in dieser e-mail nicht',
        ];
    }
}
