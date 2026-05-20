<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

/**
 * Newsletter “view in browser” / online-version phrases (EN, DE, FR).
 *
 * Matched case-insensitively after {@see normalizeForMatch()}. Longer phrases are
 * listed first in source arrays for readability only; matching uses substring search.
 */
final class EmailWebViewPhraseLexicon
{
    /** @var list<string>|null */
    private static ?array $allPhrasesCache = null;

    public static function textLooksLikeWebView(string $text): bool
    {
        $lower = self::normalizeForMatch($text);
        if ($lower === '') {
            return false;
        }
        foreach (self::allPhrases() as $phrase) {
            if (str_contains($lower, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Short link labels that only count when surrounding block text matches webview phrasing.
     */
    public static function shortAnchorInWebViewContext(string $anchorText, string $context): bool
    {
        $anchor = self::normalizeForMatch($anchorText);
        if ($anchor === '' || !self::isShortAnchorLabel($anchor)) {
            return false;
        }

        return self::textLooksLikeWebView($context);
    }

    /**
     * @return list<string>
     */
    public static function allPhrases(): array
    {
        if (self::$allPhrasesCache !== null) {
            return self::$allPhrasesCache;
        }

        $merged = array_merge(
            self::phrasesEnglish(),
            self::phrasesGerman(),
            self::phrasesFrench(),
        );
        $normalized = [];
        foreach ($merged as $phrase) {
            $p = self::normalizeForMatch($phrase);
            if ($p !== '') {
                $normalized[$p] = $p;
            }
        }
        self::$allPhrasesCache = array_values($normalized);

        return self::$allPhrasesCache;
    }

    /**
     * Lowercase + fold accents so “problème” matches “probleme”.
     */
    public static function normalizeForMatch(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace(
            ['ä', 'ö', 'ü', 'ß', 'é', 'è', 'ê', 'ë', 'à', 'â', 'á', 'ù', 'û', 'ú', 'î', 'ï', 'í', 'ô', 'ó', 'ç', 'œ', 'æ'],
            ['a', 'o', 'u', 'ss', 'e', 'e', 'e', 'e', 'a', 'a', 'a', 'u', 'u', 'u', 'i', 'i', 'i', 'o', 'o', 'c', 'oe', 'ae'],
            $text
        );

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }

    private static function isShortAnchorLabel(string $normalizedAnchor): bool
    {
        static $labels = null;
        if ($labels === null) {
            $raw = array_merge(
                self::shortAnchorsEnglish(),
                self::shortAnchorsGerman(),
                self::shortAnchorsFrench(),
            );
            $labels = [];
            foreach ($raw as $l) {
                $n = self::normalizeForMatch($l);
                if ($n !== '') {
                    $labels[$n] = true;
                }
            }
        }

        return isset($labels[$normalizedAnchor]);
    }

    /**
     * @return list<string>
     */
    private static function shortAnchorsEnglish(): array
    {
        return [
            'here',
            'click here',
            'view',
            'view online',
            'read online',
            'open',
            'browser',
            'web version',
            'online version',
            'full version',
            'see online',
        ];
    }

    /**
     * @return list<string>
     */
    private static function shortAnchorsGerman(): array
    {
        return [
            'hier',
            'hier klicken',
            'ansehen',
            'online',
            'webversion',
            'web-version',
            'browser',
            'onlineversion',
            'online-version',
            'jetzt ansehen',
            'zur webansicht',
        ];
    }

    /**
     * @return list<string>
     */
    private static function shortAnchorsFrench(): array
    {
        return [
            'ici',
            'cliquez ici',
            'voir',
            'en ligne',
            'version en ligne',
            'navigateur',
            'afficher',
            'consulter',
            'lire en ligne',
        ];
    }

    /**
     * @return list<string>
     */
    private static function phrasesEnglish(): array
    {
        return [
            'view this email in your browser',
            'view this e-mail in your browser',
            'view this message in your browser',
            'view this newsletter in your browser',
            'view in your browser',
            'view in browser',
            'view in a browser',
            'view this email online',
            'view this e-mail online',
            'view this message online',
            'view this newsletter online',
            'view the email in your browser',
            'view the e-mail in your browser',
            'view the message in your browser',
            'view the newsletter in your browser',
            'view email in browser',
            'view e-mail in browser',
            'view online',
            'view on the web',
            'view as a webpage',
            'view as webpage',
            'view as web page',
            'view as web page',
            'view web version',
            'view the web version',
            'view full message',
            'view full email',
            'view full e-mail',
            'view full newsletter',
            'open in browser',
            'open in your browser',
            'open in a browser',
            'open this email in your browser',
            'open this e-mail in your browser',
            'open online',
            'read online',
            'read this email online',
            'read this e-mail online',
            'read in browser',
            'read in your browser',
            'read the web version',
            'read the online version',
            'see online',
            'see this email online',
            'see this e-mail online',
            'see the online version',
            'see the web version',
            'see the full message',
            'see the full email',
            'web version',
            'webversion',
            'web-version',
            'online version',
            'online-version',
            'browser version',
            'desktop version',
            'full version',
            'html version',
            'email version for browsers',
            'e-mail version for browsers',
            'having trouble viewing this email',
            'having trouble viewing this e-mail',
            'having trouble viewing this message',
            'having trouble reading this email',
            'having trouble reading this e-mail',
            'having trouble reading this message',
            'trouble viewing this email',
            'trouble viewing this e-mail',
            'trouble reading this email',
            'trouble reading this e-mail',
            'if you are unable to see the message',
            'if you are unable to see this message',
            'if you are unable to see this email',
            'if you are unable to see this e-mail',
            "if you can't read this email",
            "if you can't read this e-mail",
            "if you can't read this message",
            "if you can't see this email",
            "if you can't see this e-mail",
            "if you can't view this email",
            "if you can't view this e-mail",
            "if you don't see this email",
            "if you don't see this e-mail",
            "if you don't see this message",
            'if you cannot view this email',
            'if you cannot view this e-mail',
            'if you cannot read this email',
            'if you cannot read this e-mail',
            'if you cannot see this email',
            'if you cannot see this e-mail',
            'if this email is not displayed',
            'if this e-mail is not displayed',
            'if this message is not displayed',
            'if this email does not display',
            'if this e-mail does not display',
            'email not displaying correctly',
            'e-mail not displaying correctly',
            'message not displaying correctly',
            'this email is best viewed in your browser',
            'this e-mail is best viewed in your browser',
            'this email is best viewed in a browser',
            'this e-mail is best viewed in a browser',
            'this email requires a modern e-mail reader',
            'this email requires a modern email reader',
            'this e-mail requires a modern e-mail reader',
            'this e-mail requires a modern email reader',
            'click here to view',
            'click here to read',
            'click here to see',
            'click here to open',
            'click here for the online',
            'click here for online version',
            'click to view',
            'click to view online',
            'click to read online',
            'to view this email',
            'to view this e-mail',
            'to read this email online',
            'to read this e-mail online',
            'use this link to view',
            'use this link to read',
            'display problems',
            'display issue',
            'problems viewing this email',
            'problems viewing this e-mail',
            'problems displaying this email',
            'problems displaying this e-mail',
        ];
    }

    /**
     * @return list<string>
     */
    private static function phrasesGerman(): array
    {
        return [
            'e-mail im browser',
            'email im browser',
            'diese e-mail im browser',
            'diese email im browser',
            'diese e-mail in ihrem browser ansehen',
            'diese e-mail in deinem browser ansehen',
            'diese e-mail in ihrem web-browser',
            'diese e-mail in ihrem web browser',
            'diese e-mail in ihrem browser anzeigen',
            'diese e-mail in ihrem browser offnen',
            'diese e-mail in ihrem browser oeffnen',
            'im browser ansehen',
            'im web-browser ansehen',
            'im webbrowser ansehen',
            'im browser offnen',
            'im browser oeffnen',
            'im browser lesen',
            'im web lesen',
            'e-mail in ihrem browser anzeigen',
            'e-mail in ihrem browser ansehen',
            'newsletter im browser',
            'newsletter im browser ansehen',
            'newsletter online ansehen',
            'online-version dieser e-mail',
            'online version dieser e-mail',
            'online-version dieses newsletters',
            'online version dieses newsletters',
            'webversion dieser e-mail',
            'webversion dieser e-mail anzeigen',
            'web-version dieser e-mail',
            'web version dieser e-mail',
            'zur online-version',
            'zur webversion',
            'zur web-version',
            'zur webansicht',
            'zur online-ansicht',
            'online ansehen',
            'online lesen',
            'online offnen',
            'online oeffnen',
            'onlineversion anzeigen',
            'online-version anzeigen',
            'webansicht',
            'web-ansicht',
            'browser-ansicht',
            'klicken sie hier, um die online',
            'klicken sie hier fuer die online',
            'klicken sie hier fur die online',
            'hier klicken fuer die online',
            'hier klicken fur die online',
            'hier klicken, um die online',
            'bitte hier klicken',
            'klicken sie hier',
            'hier klicken',
            'hier ansehen',
            'jetzt im browser ansehen',
            'jetzt online lesen',
            'newsletter online lesen',
            'e-mail online lesen',
            'falls sie ihre e-mail nicht',
            'falls sie ihre e-mail nicht oder nur teilweise',
            'falls sie ihre email nicht',
            'falls sie diese e-mail nicht',
            'falls sie diesen newsletter nicht',
            'wenn sie ihre e-mail nicht',
            'wenn sie diese e-mail nicht',
            'wenn sie diesen newsletter nicht',
            'wenn die e-mail nicht richtig',
            'wenn die nachricht nicht richtig',
            'wenn der newsletter nicht richtig',
            'nicht oder nur teilweise sehen',
            'e-mail nicht oder nur teilweise',
            'e-mail nicht oder nur teilweise sehen',
            'newsletter nicht oder nur teilweise',
            'probleme mit der anzeige dieser e-mail',
            'probleme bei der anzeige dieser e-mail',
            'probleme mit der anzeige dieses newsletters',
            'probleme bei der anzeige dieses newsletters',
            'probleme mit der darstellung',
            'probleme bei der darstellung',
            'anzeigeprobleme',
            'darstellungsprobleme',
            'e-mail wird nicht richtig angezeigt',
            'newsletter wird nicht richtig angezeigt',
            'wenn die bilder in dieser e-mail nicht',
            'wenn die bilder in diesem newsletter nicht',
            'wenn die bilder nicht richtig',
            'wenn die bilder nicht korrekt',
            'wenn die bilder unten nicht erscheinen',
            'wenn unten stehende bilder fehlen',
            'wenn unten stehende bilder nicht erscheinen',
            'falls die bilder nicht erscheinen',
            'falls die bilder nicht angezeigt werden',
            'bilder werden nicht richtig angezeigt',
            'bilder werden in dieser e-mail nicht',
            'modernen e-mail-reader',
            'modernen email-reader',
        ];
    }

    /**
     * @return list<string>
     */
    private static function phrasesFrench(): array
    {
        return [
            'voir cet e-mail dans votre navigateur',
            'voir cet email dans votre navigateur',
            'voir ce e-mail dans votre navigateur',
            'voir ce email dans votre navigateur',
            'voir cette newsletter dans votre navigateur',
            'voir ce message dans votre navigateur',
            'voir cet e-mail dans le navigateur',
            'voir cet email dans le navigateur',
            'voir dans votre navigateur',
            'voir dans le navigateur',
            'voir dans un navigateur',
            'afficher dans votre navigateur',
            'afficher dans le navigateur',
            'afficher cet e-mail dans votre navigateur',
            'afficher cet email dans votre navigateur',
            'afficher cette newsletter dans votre navigateur',
            'ouvrir dans votre navigateur',
            'ouvrir dans le navigateur',
            'ouvrir cet e-mail dans votre navigateur',
            'ouvrir cet email dans votre navigateur',
            'consulter dans votre navigateur',
            'consulter dans le navigateur',
            'consulter cet e-mail dans votre navigateur',
            'consulter cet email dans votre navigateur',
            'consulter en ligne',
            'consulter la version en ligne',
            'consulter la version web',
            'lire en ligne',
            'lire cet e-mail en ligne',
            'lire cet email en ligne',
            'lire cette newsletter en ligne',
            'lire la version en ligne',
            'lire la version web',
            'voir en ligne',
            'voir la version en ligne',
            'voir la version web',
            'version en ligne',
            'version web',
            'version web de cet e-mail',
            'version web de cet email',
            'version web de cette newsletter',
            'version en ligne de cet e-mail',
            'version en ligne de cet email',
            'version en ligne de cette newsletter',
            'version navigateur',
            'version pour navigateur',
            'version complete en ligne',
            'cliquez ici pour voir',
            'cliquez ici pour afficher',
            'cliquez ici pour consulter',
            'cliquez ici pour lire',
            'cliquez ici pour la version en ligne',
            'cliquez ici pour la version web',
            'cliquez ici pour ouvrir',
            'cliquez ici',
            'cliquez sur ce lien',
            'cliquer ici',
            'cliquer ici pour voir',
            'cliquer ici pour afficher',
            'si vous ne voyez pas cet e-mail',
            'si vous ne voyez pas cet email',
            'si vous ne voyez pas ce message',
            'si vous ne voyez pas cette newsletter',
            'si vous n\'arrivez pas a lire',
            'si vous n\'arrivez pas à lire',
            'si vous ne parvenez pas a lire',
            'si vous ne parvenez pas à lire',
            'si vous ne pouvez pas lire',
            'si vous ne pouvez pas voir',
            'si vous ne pouvez pas afficher',
            'si cet e-mail ne s\'affiche pas',
            'si cet email ne s\'affiche pas',
            'si ce message ne s\'affiche pas',
            'si cette newsletter ne s\'affiche pas',
            'si l\'e-mail ne s\'affiche pas',
            'si l\'email ne s\'affiche pas',
            'probleme d\'affichage',
            'probleme avec cet e-mail',
            'probleme avec cet email',
            'probleme avec cette newsletter',
            'problemes d\'affichage',
            'problemes avec cet e-mail',
            'difficulte a afficher',
            'difficulte a lire',
            'difficultes a afficher',
            'difficultes a lire',
            'message non visible',
            'e-mail non visible',
            'email non visible',
            'newsletter non visible',
            'cet e-mail est mieux affiche dans votre navigateur',
            'cet email est mieux affiche dans votre navigateur',
            'cet e-mail est concu pour etre lu dans un navigateur',
            'cet email est concu pour etre lu dans un navigateur',
            'utilisez un navigateur pour lire',
            'pour lire cet e-mail en ligne',
            'pour lire cet email en ligne',
            'pour voir cet e-mail en ligne',
            'pour voir cet email en ligne',
            'lien vers la version en ligne',
            'lien vers la version web',
            'acceder a la version en ligne',
            'acceder a la version web',
        ];
    }
}
