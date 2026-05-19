<?php

declare(strict_types=1);

namespace Seismo\Service\Sparql;

use EasyRdf\Format;
use EasyRdf\Http;
use EasyRdf\Sparql\Client;

/**
 * SPARQL client that always POSTs read queries.
 *
 * EasyRdf uses GET when the URL-encoded query fits in ~2 KiB. The EU
 * Publications Office endpoint returns 403 for non-trivial GET requests (WAF).
 */
final class PostOnlySparqlClient extends Client
{
    private string $seismoQueryUri;

    public function __construct(string $queryUri, ?string $updateUri = null)
    {
        parent::__construct($queryUri, $updateUri);
        $this->seismoQueryUri = $queryUri;
    }

    /**
     * @param string $processed_query
     * @param string $type
     * @return Http\Response|\Zend\Http\Response
     */
    protected function executeQuery($processed_query, $type)
    {
        if ($type !== 'query') {
            return parent::executeQuery($processed_query, $type);
        }

        $client = Http::getDefaultHttpClient();
        $client->resetParameters();

        $sparqlResultsTypes = [
            'application/sparql-results+json' => 1.0,
            'application/sparql-results+xml' => 0.8,
        ];

        $re = '(?:(?:\s*BASE\s*<.*?>\s*)|(?:\s*PREFIX\s+.+:\s*<.*?>\s*))*'
            . '(CONSTRUCT|SELECT|ASK|DESCRIBE)[\W]';

        $result = null;
        $matched = mb_eregi($re, $processed_query, $result);

        if ($matched === false || count($result) !== 2) {
            $queryVerb = null;
        } else {
            $queryVerb = strtoupper($result[1]);
        }

        if ($queryVerb === 'SELECT' || $queryVerb === 'ASK') {
            $accept = Format::formatAcceptHeader($sparqlResultsTypes);
        } elseif ($queryVerb === 'CONSTRUCT' || $queryVerb === 'DESCRIBE') {
            $accept = Format::getHttpAcceptHeader();
        } else {
            $accept = Format::getHttpAcceptHeader($sparqlResultsTypes);
        }

        $this->setHeaders($client, 'Accept', $accept);

        $encodedQuery = 'query=' . urlencode($processed_query);

        $client->setMethod('POST');
        $client->setUri($this->seismoQueryUri);
        $client->setRawData($encodedQuery);
        $this->setHeaders($client, 'Content-Type', 'application/x-www-form-urlencoded');

        if ($client instanceof \Zend\Http\Client) {
            return $client->send();
        }

        return $client->request();
    }
}
