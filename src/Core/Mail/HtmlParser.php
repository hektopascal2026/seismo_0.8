<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

use DOMDocument;
use Masterminds\HTML5;

final class HtmlParser
{
    private static ?HTML5 $html5 = null;

    private static function getParser(): HTML5
    {
        if (self::$html5 === null) {
            self::$html5 = new HTML5([
                'disable_html_ns' => true,
            ]);
        }

        return self::$html5;
    }

    /**
     * Parse HTML string into DOMDocument using HTML5 spec.
     */
    public static function parse(string $html): DOMDocument
    {
        $html = trim($html);
        if ($html === '') {
            return new DOMDocument();
        }

        return self::getParser()->loadHTML($html);
    }

    /**
     * Save HTML5 DOMDocument/DOMElement as standard HTML5 string.
     */
    public static function saveHTML(\DOMNode $dom): string
    {
        return self::getParser()->saveHTML($dom);
    }
}
