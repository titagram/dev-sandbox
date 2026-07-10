<?php

namespace App\Services\Neo4j;

use Laudis\Neo4j\Contracts\ClientInterface;

class LaudisNeo4jClient implements Neo4jClient
{
    public function __construct(
        private readonly ClientInterface $client,
    ) {}

    /** @param array<string, mixed> $parameters */
    public function run(string $cypher, array $parameters = []): mixed
    {
        return $this->client->run($cypher, $parameters);
    }
}
