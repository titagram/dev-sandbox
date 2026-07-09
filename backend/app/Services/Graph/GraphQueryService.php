<?php

namespace App\Services\Graph;

use App\Services\Neo4j\Neo4jClient;
use App\Services\Neo4jClientFactory;
use RuntimeException;

class GraphQueryService
{
    private const ALLOWED_TYPES = ['callers', 'callees', 'path'];

    private const CALLERS_CYPHER = <<<'CYPHER'
    MATCH (caller)-[:CALLS]->(n {symbol_id: $symbol_id})
    RETURN properties(caller) AS node, labels(caller) AS labels, id(caller) AS internal_id
    LIMIT $limit
    CYPHER;

    private const CALLEES_CYPHER = <<<'CYPHER'
    MATCH (n {symbol_id: $symbol_id})-[:CALLS]->(callee)
    RETURN properties(callee) AS node, labels(callee) AS labels, id(callee) AS internal_id
    LIMIT $limit
    CYPHER;

    private const PATH_CYPHER = <<<'CYPHER'
    MATCH (from {symbol_id: $from_symbol_id}), (to {symbol_id: $to_symbol_id})
    MATCH p = shortestPath((from)-[:CALLS*1..$max_depth]-(to))
    UNWIND nodes(p) AS n
    RETURN properties(n) AS node, labels(n) AS labels, id(n) AS internal_id
    LIMIT 100
    CYPHER;

    private ?Neo4jClient $client = null;

    public function __construct(?Neo4jClient $client = null)
    {
        $this->client = $client;
    }

    /** @param array<string, mixed> $params */
    public function query(string $type, array $params): array
    {
        $this->guardType($type);

        try {
            $client = $this->resolveClient();
        } catch (\Throwable $e) {
            return [
                'found' => false,
                'reason' => 'neo4j_unavailable',
                'results' => [],
            ];
        }

        try {
            $rows = match ($type) {
                'callers' => $this->runCallers($client, $params),
                'callees' => $this->runCallees($client, $params),
                'path' => $this->runPath($client, $params),
            };
        } catch (\Throwable $e) {
            return [
                'found' => false,
                'reason' => 'query_error',
                'results' => [],
            ];
        }

        return [
            'found' => true,
            'results' => $rows,
        ];
    }

    /** @param array<string, mixed> $params */
    private function runCallers(Neo4jClient $client, array $params): array
    {
        $symbolId = (string) ($params['symbol_id'] ?? '');
        $limit = $this->boundedLimit($params);

        $result = $client->run(self::CALLERS_CYPHER, [
            'symbol_id' => $symbolId,
            'limit' => $limit,
        ]);

        return $this->normaliseRows($result);
    }

    /** @param array<string, mixed> $params */
    private function runCallees(Neo4jClient $client, array $params): array
    {
        $symbolId = (string) ($params['symbol_id'] ?? '');
        $limit = $this->boundedLimit($params);

        $result = $client->run(self::CALLEES_CYPHER, [
            'symbol_id' => $symbolId,
            'limit' => $limit,
        ]);

        return $this->normaliseRows($result);
    }

    /** @param array<string, mixed> $params */
    private function runPath(Neo4jClient $client, array $params): array
    {
        $fromId = (string) ($params['from_symbol_id'] ?? '');
        $toId = (string) ($params['to_symbol_id'] ?? '');
        $maxDepth = max(1, min(10, (int) ($params['max_depth'] ?? 5)));

        $result = $client->run(self::PATH_CYPHER, [
            'from_symbol_id' => $fromId,
            'to_symbol_id' => $toId,
            'max_depth' => $maxDepth,
        ]);

        return $this->normaliseRows($result);
    }

    /** @param array<string, mixed> $params */
    private function boundedLimit(array $params): int
    {
        return max(1, min(50, (int) ($params['limit'] ?? 50)));
    }

    /**
     * @param  mixed  $result
     * @return list<array<string, mixed>>
     */
    private function normaliseRows(mixed $result): array
    {
        if (! is_array($result)) {
            return [];
        }

        $rows = [];
        foreach ($result as $row) {
            if (! is_array($row)) {
                continue;
            }

            $props = $row['node'] ?? [];
            $labels = $row['labels'] ?? [];
            $id = isset($row['node']['id']) ? (string) $row['node']['id'] : null;

            if ($id === null && isset($row['node']['symbol_id'])) {
                $id = (string) $row['node']['symbol_id'];
            }

            if ($id === null) {
                $id = 'node-'.count($rows);
            }

            $entry = [
                'id' => $id,
                'labels' => array_values(array_filter(is_array($labels) ? $labels : [])),
                'properties' => is_array($props) ? $props : [],
            ];

            if (isset($props['name'])) {
                $entry['name'] = (string) $props['name'];
            }
            if (isset($props['path'])) {
                $entry['path'] = (string) $props['path'];
            }

            $rows[] = $entry;
        }

        return $rows;
    }

    private function guardType(string $type): void
    {
        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            throw new RuntimeException('Unsupported query type: '.$type);
        }
    }

    private function resolveClient(): Neo4jClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        return app(Neo4jClientFactory::class)->client();
    }
}
