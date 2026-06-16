<?php

namespace App\Services;

use Laudis\Neo4j\ClientBuilder;

class Neo4jClientFactory
{
    public function client(): object
    {
        return ClientBuilder::create()
            ->withDriver('default', config('services.neo4j.uri'), config('services.neo4j.auth'))
            ->build();
    }
}
