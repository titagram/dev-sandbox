<?php

namespace App\Services\Neo4j;

use RuntimeException;

class FailingNeo4jClient implements Neo4jClient
{
    /** @param array<string, mixed> $params */
    public function run(string $cypher, array $params = []): mixed
    {
        throw new RuntimeException('neo4j unavailable');
    }
}
