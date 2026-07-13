<?php

namespace App\Services\Neo4j;

class FakeNeo4jClient implements Neo4jClient
{
    /** @var list<array{cypher: string, params: array<string, mixed>}> */
    public array $commands = [];

    /** @param array<string, mixed> $params */
    public function run(string $cypher, array $params = []): mixed
    {
        $this->commands[] = ['cypher' => $cypher, 'params' => $params];

        if (str_contains($cypher, 'RETURN nodes, count(r) AS relationships')) {
            return [['nodes' => $params['expected_nodes'], 'relationships' => $params['expected_relationships']]];
        }

        if (str_contains($cypher, 'RETURN count(a) AS adjacencies')) {
            return [['adjacencies' => $params['expected_adjacencies']]];
        }

        return [];
    }
}
