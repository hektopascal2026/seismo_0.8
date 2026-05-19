<?php

declare(strict_types=1);

namespace Seismo\Service\Sparql;

use EasyRdf\Http;

/**
 * Shared EasyRdf SPARQL setup for legislation plugins (EUR-Lex, Fedlex).
 */
final class SparqlEasyRdf
{
    private static bool $httpConfigured = false;

    public static function client(string $endpoint): PostOnlySparqlClient
    {
        self::configureHttpOnce();

        return new PostOnlySparqlClient($endpoint);
    }

    private static function configureHttpOnce(): void
    {
        if (self::$httpConfigured) {
            return;
        }

        $version = defined('SEISMO_VERSION') ? (string)SEISMO_VERSION : 'dev';
        $contact = defined('SEISMO_MOTHERSHIP_URL') && SEISMO_MOTHERSHIP_URL !== ''
            ? ' (+' . SEISMO_MOTHERSHIP_URL . ')'
            : '';

        $http = new Http\Client();
        $http->setConfig([
            'timeout' => 120,
            'useragent' => 'Seismo/' . $version . $contact,
        ]);
        Http::setDefaultHttpClient($http);
        self::$httpConfigured = true;
    }
}
