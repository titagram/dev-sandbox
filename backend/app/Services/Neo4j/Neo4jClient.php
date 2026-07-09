<?php

namespace App\Services\Neo4j;

interface Neo4jClient
{
    /** @param array<string, mixed> $parameters */
    public function run(string $cypher, array $parameters = []): mixed;
}
