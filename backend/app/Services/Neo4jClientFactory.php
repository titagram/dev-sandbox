<?php

namespace App\Services;

use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;

class Neo4jClientFactory
{
    public function client(): object
    {
        [$user, $password] = config('services.neo4j.auth');

        return ClientBuilder::create()
            ->withDriver('default', config('services.neo4j.uri'), Authenticate::basic($user, $password))
            ->build();
    }
}
