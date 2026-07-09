<?php

namespace App\Services;

use App\Services\Neo4j\LaudisNeo4jClient;
use App\Services\Neo4j\Neo4jClient;
use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;

class Neo4jClientFactory
{
    public function client(): Neo4jClient
    {
        [$user, $password] = config('services.neo4j.auth');

        $realClient = ClientBuilder::create()
            ->withDriver('default', config('services.neo4j.uri'), Authenticate::basic($user, $password))
            ->build();

        return new LaudisNeo4jClient($realClient);
    }
}
