<?php
require_once __DIR__ . '/bootstrap.php';
use Seismo\Service\Sparql\SparqlEasyRdf;

$sparql = SparqlEasyRdf::client('https://fedlex.data.admin.ch/sparqlendpoint');
$sq = '
SELECT ?p ?o WHERE {
    <https://fedlex.data.admin.ch/eli/oc/2026/232> ?p ?o .
}
';

$results = $sparql->query($sq);
foreach ($results as $row) {
    echo $row->p . " : " . $row->o . "\n";
}
