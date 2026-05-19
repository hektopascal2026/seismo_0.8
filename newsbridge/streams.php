<?php

declare(strict_types=1);

// Not a public page: browser requests would be blank because this file only `return`s data for cron.php.
if (PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && basename((string) $_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    header('Content-Type: text/plain; charset=UTF-8');
    http_response_code(403);
    echo "streams.php is configuration only. It is loaded by cron.php — edit it over FTP/SFTP, not in the browser.\n";
    echo "RSS output: feeds/*.xml\n";
    exit;
}

/**
 * One block = one RSS file at feeds/{id}.xml
 *
 * Broad OR queries pull huge noise. top-ch uses Swiss publisher domains; cron.php also filters
 * article URLs so only those hosts are kept (News API's domains= param alone is unreliable).
 *
 * RSS: feeds/top-ch.xml, feeds/ch-en.xml, feeds/ch-de.xml, feeds/ch-fr.xml
 *
 * @return list<array<string, mixed>>
 */
return [
    [
        'id'                  => 'top-ch',
        'channel_title'       => 'Switzerland — Swiss outlets (domains)',
        'channel_description' => 'Articles from a fixed set of .ch publishers News API indexes (highest precision, smaller volume).',
        'mode'                => 'everything',
        'params'              => [
            'domains' => implode(',', [
                'nzz.ch',
                'tagesanzeiger.ch',
                'blick.ch',
                '20min.ch',
                'swissinfo.ch',
                'watson.ch',
                'thelocal.ch',
                'letemps.ch',
                'rts.ch',
                'rsi.ch',
                'luzernerzeitung.ch',
                'bernerzeitung.ch',
                'aargauerzeitung.ch',
                'tagblatt.ch',
                'handelszeitung.ch',
                'finews.ch',
                'admin.ch',
                'parlament.ch',
            ]),
            'sortBy'    => 'publishedAt',
        ],
    ],
    [
        'id'                  => 'ch-en',
        'channel_title'       => 'Switzerland — English (tight query)',
        'channel_description' => 'English: institutions, votes, neutrality, macro — search in title/description only.',
        'mode'                => 'everything',
        'params'              => [
            'q' => '('
                . 'Switzerland OR "Swiss National Bank" OR SNB OR FINMA OR Bundesrat OR "Federal Council" OR SECO OR '
                . 'neutrality OR referendum OR Abstimmung OR "popular vote" OR "Council of States" OR "National Council" OR '
                . 'Eidgenossenschaft OR UBS OR "Credit Suisse" OR "Swiss franc" OR CHF OR Davos OR "Swiss government"'
                . ')',
            'language'  => 'en',
            'searchIn'  => 'title,description',
            'sortBy'    => 'publishedAt',
        ],
    ],
    [
        'id'                  => 'ch-de',
        'channel_title'       => 'Schweiz — Deutsch (enger)',
        'channel_description' => 'Deutsch: Bund, Finanzmarkt, Abstimmungen — nur Titel/Beschreibung.',
        'mode'                => 'everything',
        'params'              => [
            'q' => '('
                . 'Schweiz OR Eidgenossenschaft OR Bundesrat OR Nationalrat OR Ständerat OR SNB OR FINMA OR '
                . 'Abstimmung OR Referendum OR Volksabstimmung OR Neutralität OR "Schweizer Franken" OR '
                . 'Kanton OR Konferenz OR UBS OR "Credit Suisse"'
                . ')',
            'language'  => 'de',
            'searchIn'  => 'title,description',
            'sortBy'    => 'publishedAt',
        ],
    ],
    [
        'id'                  => 'ch-fr',
        'channel_title'       => 'Suisse — français (serré)',
        'channel_description' => 'Français: institutions fédérales, votes, place financière — titre/description seulement.',
        'mode'                => 'everything',
        'params'              => [
            'q' => '('
                . 'Suisse OR BNS OR FINMA OR "Conseil fédéral" OR "Conseil national" OR "Conseil des États" OR '
                . 'votation OR référendum OR neutralité OR "franc suisse" OR canton OR Genève OR Vaud OR Valais OR '
                . 'Jura OR Neuchâtel OR Fribourg OR Tessin OR UBS OR "Credit Suisse"'
                . ')',
            'language'  => 'fr',
            'searchIn'  => 'title,description',
            'sortBy'    => 'publishedAt',
        ],
    ],
];
