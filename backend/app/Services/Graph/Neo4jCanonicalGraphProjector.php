<?php

namespace App\Services\Graph;

use App\Services\Neo4j\Neo4jClient;
use Closure;
use Laudis\Neo4j\ParameterHelper;
use Laudis\Neo4j\Types\CypherMap;
use RuntimeException;

class Neo4jCanonicalGraphProjector
{
    private const BATCH_SIZE = 500;

    /** @return array{nodes:int, relationships:int} */
    public function project(array $graph, object $projection, Neo4jClient $client, ?Closure $heartbeat = null): array
    {
        $this->assertPropertyBagsAreMaps($graph);
        $this->assertHeartbeat($heartbeat);

        $scope = ['graph_version' => $projection->graph_version, 'project_id' => $projection->project_id, 'source_scope_type' => $projection->source_scope_type, 'source_scope_id' => $projection->source_scope_id];
        $client->run('CREATE INDEX canonical_node_version_external IF NOT EXISTS FOR (n:CanonicalGraphNode) ON (n.graph_version, n.external_id)');
        $client->run('CREATE INDEX canonical_version_identity IF NOT EXISTS FOR (v:CanonicalGraphVersion) ON (v.graph_version)');
        $client->run('CREATE INDEX canonical_adjacency_direction_rank IF NOT EXISTS FOR (a:CanonicalGraphAdjacency) ON (a.graph_version, a.from_external_id, a.direction, a.direction_rank)');
        $client->run('CREATE INDEX canonical_adjacency_any_rank IF NOT EXISTS FOR (a:CanonicalGraphAdjacency) ON (a.graph_version, a.from_external_id, a.any_rank)');
        $client->run('CALL db.awaitIndexes(300)');
        $this->assertHeartbeat($heartbeat);
        $client->run('MERGE (v:CanonicalGraphVersion {graph_version: $graph_version}) ON CREATE SET v.current = false SET v += $metadata, v.status = \'projecting\'', $scope + ['metadata' => $this->boltPropertyMap($scope + ['snapshot_id' => $projection->snapshot_id ?? null])]);

        foreach (array_chunk($graph['nodes'], self::BATCH_SIZE) as $batch) {
            $this->assertHeartbeat($heartbeat);
            $client->run('UNWIND $nodes AS node MERGE (n:CanonicalGraphNode {graph_version: $graph_version, external_id: node.id}) SET n += node.properties, n.graph_version = $graph_version, n.external_id = node.id, n.labels = node.labels, n.project_id = $project_id, n.source_scope_type = $source_scope_type, n.source_scope_id = $source_scope_id', $scope + ['nodes' => $this->withBoltPropertyMaps($batch)]);
        }
        foreach ($this->relationshipsByType($graph['relationships']) as $type => $relationships) {
            foreach (array_chunk($relationships, self::BATCH_SIZE) as $batch) {
                $this->assertHeartbeat($heartbeat);
                $client->run("UNWIND \$relationships AS relationship MATCH (source:CanonicalGraphNode {graph_version: \$graph_version, external_id: relationship.source_id}) MATCH (target:CanonicalGraphNode {graph_version: \$graph_version, external_id: relationship.target_id}) MERGE (source)-[r:{$type} {graph_version: \$graph_version, external_id: relationship.id}]->(target) SET r += relationship.properties, r.graph_version = \$graph_version, r.external_id = relationship.id, r.project_id = \$project_id, r.source_scope_type = \$source_scope_type, r.source_scope_id = \$source_scope_id", $scope + ['relationships' => $this->withBoltPropertyMaps($batch)]);
            }
        }
        $adjacencies = $this->adjacencyRows($graph['relationships']);
        foreach (array_chunk($adjacencies, self::BATCH_SIZE) as $batch) {
            $this->assertHeartbeat($heartbeat);
            $client->run('UNWIND $adjacencies AS adjacency MERGE (a:CanonicalGraphAdjacency {graph_version: $graph_version, external_id: adjacency.id}) SET a += adjacency.properties, a.graph_version = $graph_version, a.external_id = adjacency.id, a.project_id = $project_id, a.source_scope_type = $source_scope_type, a.source_scope_id = $source_scope_id', $scope + ['adjacencies' => $this->withBoltPropertyMaps($batch)]);
        }

        $verified = $client->run('MATCH (n:CanonicalGraphNode {graph_version: $graph_version}) WITH count(n) AS nodes OPTIONAL MATCH ()-[r {graph_version: $graph_version}]->() RETURN nodes, count(r) AS relationships', $scope + ['expected_nodes' => count($graph['nodes']), 'expected_relationships' => count($graph['relationships'])]);
        if (! is_iterable($verified)) {
            throw new RuntimeException('Canonical graph verification did not return rows.');
        }
        $rows = [];
        foreach ($verified as $row) {
            $rows[] = $row;
        }
        if (count($rows) !== 1) {
            throw new RuntimeException('Canonical graph verification must return exactly one row.');
        }
        $row = $rows[0];
        $actualNodes = $this->verificationValue($row, 'nodes');
        $actualRelationships = $this->verificationValue($row, 'relationships');
        if (! is_numeric($actualNodes) || ! is_numeric($actualRelationships) || (int) $actualNodes !== count($graph['nodes']) || (int) $actualRelationships !== count($graph['relationships'])) {
            throw new RuntimeException('Canonical graph verification count mismatch.');
        }
        $verifiedAdjacencies = $client->run('MATCH (a:CanonicalGraphAdjacency {graph_version: $graph_version}) RETURN count(a) AS adjacencies', $scope + ['expected_adjacencies' => count($adjacencies)]);
        $adjacencyRows = [];
        if (is_iterable($verifiedAdjacencies)) {
            foreach ($verifiedAdjacencies as $adjacencyRow) {
                $adjacencyRows[] = $adjacencyRow;
            }
        }
        if (count($adjacencyRows) !== 1 || ! is_numeric($this->verificationValue($adjacencyRows[0], 'adjacencies')) || (int) $this->verificationValue($adjacencyRows[0], 'adjacencies') !== count($adjacencies)) {
            throw new RuntimeException('Canonical graph adjacency verification count mismatch.');
        }
        $this->assertHeartbeat($heartbeat);

        return ['nodes' => count($graph['nodes']), 'relationships' => count($graph['relationships'])];
    }

    public function publishCurrent(object $projection, Neo4jClient $client): void
    {
        $scope = $this->projectionScope($projection);
        $client->run("MATCH (candidate:CanonicalGraphVersion {graph_version: \$graph_version})\nMATCH (other:CanonicalGraphVersion {project_id: \$project_id, source_scope_type: \$source_scope_type, source_scope_id: \$source_scope_id})\nSET other.current = false, candidate.current = true, candidate.status = 'ready', candidate.traversal_schema_version = 1, candidate.projected_at = datetime()", $scope);
    }

    /** Rebuild Neo4j's marker from PostgreSQL's authoritative active pointer. */
    public function reconcileCurrent(object $scopeProjection, ?string $activeGraphVersion, Neo4jClient $client): void
    {
        $scope = $this->projectionScope($scopeProjection);
        $client->run("/* recovery source of truth: PostgreSQL active_graph_version */\nMATCH (version:CanonicalGraphVersion {project_id: \$project_id, source_scope_type: \$source_scope_type, source_scope_id: \$source_scope_id}) SET version.current = false", $scope);
        if ($activeGraphVersion !== null) {
            $client->run("/* recovery source of truth: restore PostgreSQL winner */\nMATCH (active:CanonicalGraphVersion {graph_version: \$active_graph_version, project_id: \$project_id, source_scope_type: \$source_scope_type, source_scope_id: \$source_scope_id}) SET active.current = true, active.status = 'ready'", $scope + ['active_graph_version' => $activeGraphVersion]);
        }
    }

    /** Delete only a reconciled, non-current physical candidate. Idempotent. */
    public function cleanupCandidate(object $scopeProjection, string $candidateGraphVersion, Neo4jClient $client): void
    {
        $params = $this->projectionScope($scopeProjection) + ['candidate_graph_version' => $candidateGraphVersion];
        $guard = '(guard:CanonicalGraphVersion {graph_version: $candidate_graph_version, project_id: $project_id, source_scope_type: $source_scope_type, source_scope_id: $source_scope_id}) WHERE coalesce(guard.current, false) = false';
        $client->run("/* cleanup non-current candidate nodes and relationships */ MATCH {$guard} MATCH (node:CanonicalGraphNode {graph_version: \$candidate_graph_version}) DETACH DELETE node", $params);
        $client->run("/* cleanup non-current candidate adjacency */ MATCH {$guard} MATCH (adjacency:CanonicalGraphAdjacency {graph_version: \$candidate_graph_version}) DELETE adjacency", $params);
        $client->run("/* cleanup non-current candidate version */ MATCH {$guard} DELETE guard", $params);
    }

    private function projectionScope(object $projection): array
    {
        return [
            'graph_version' => (string) ($projection->graph_version ?? ''),
            'project_id' => (string) $projection->project_id,
            'source_scope_type' => (string) $projection->source_scope_type,
            'source_scope_id' => (string) $projection->source_scope_id,
        ];
    }

    private function assertHeartbeat(?Closure $heartbeat): void
    {
        if ($heartbeat !== null && $heartbeat() !== true) {
            throw new RuntimeException('Canonical publication ownership was lost.');
        }
    }

    /**
     * Build two lookup records for every relationship. Ranks are scoped to a
     * source node and are assigned only after a stable identity sort, so the
     * traversal query can apply an indexed upper bound before loading targets.
     *
     * @return list<array<string, mixed>>
     */
    private function adjacencyRows(array $relationships): array
    {
        $rows = [];
        foreach ($relationships as $relationship) {
            $type = preg_replace('/[^A-Z0-9_]/', '_', strtoupper((string) $relationship['type'])) ?: 'RELATED';
            $edgeId = (string) ($relationship['id'] ?? hash('sha256', $relationship['source_id'].'|'.$type.'|'.$relationship['target_id']));
            $edge = array_merge($relationship['properties'] ?? [], [
                'external_id' => $edgeId,
                'type' => $type,
                'source_id' => (string) $relationship['source_id'],
                'target_id' => (string) $relationship['target_id'],
            ]);
            foreach ([
                ['direction' => 'out', 'from' => (string) $relationship['source_id'], 'to' => (string) $relationship['target_id']],
                ['direction' => 'in', 'from' => (string) $relationship['target_id'], 'to' => (string) $relationship['source_id']],
            ] as $lookup) {
                $rows[] = [
                    'id' => hash('sha256', $edgeId.'|'.$lookup['direction'].'|'.$lookup['from'].'|'.$lookup['to']),
                    'properties' => [
                        'from_external_id' => $lookup['from'],
                        'to_external_id' => $lookup['to'],
                        'direction' => $lookup['direction'],
                        'edge_external_id' => $edgeId,
                        'edge_type' => $type,
                        'edge_json' => json_encode($edge, JSON_THROW_ON_ERROR),
                    ],
                ];
            }
        }
        usort($rows, fn (array $left, array $right): int => [
            $left['properties']['from_external_id'], $left['properties']['direction'],
            $left['properties']['to_external_id'], $left['properties']['edge_type'], $left['properties']['edge_external_id'],
        ] <=> [
            $right['properties']['from_external_id'], $right['properties']['direction'],
            $right['properties']['to_external_id'], $right['properties']['edge_type'], $right['properties']['edge_external_id'],
        ]);
        $directionRanks = [];
        $anyRanks = [];
        foreach ($rows as &$row) {
            $properties = &$row['properties'];
            $directionKey = $properties['from_external_id'].'|'.$properties['direction'];
            $anyKey = $properties['from_external_id'];
            $properties['direction_rank'] = $directionRanks[$directionKey] ?? 0;
            $properties['any_rank'] = $anyRanks[$anyKey] ?? 0;
            $directionRanks[$directionKey] = $properties['direction_rank'] + 1;
            $anyRanks[$anyKey] = $properties['any_rank'] + 1;
            unset($properties);
        }
        unset($row);

        return $rows;
    }

    private function relationshipsByType(array $relationships): array
    {
        $groups = [];
        foreach ($relationships as $relationship) {
            $type = preg_replace('/[^A-Z0-9_]/', '_', strtoupper((string) $relationship['type'])) ?: 'RELATED';
            $relationship['id'] ??= hash('sha256', $relationship['source_id'].'|'.$type.'|'.$relationship['target_id']);
            $groups[$type][] = $relationship;
        }

        return $groups;
    }

    private function assertPropertyBagsAreMaps(array $graph): void
    {
        foreach (['nodes' => 'Node', 'relationships' => 'Relationship'] as $collection => $label) {
            foreach ($graph[$collection] ?? [] as $item) {
                $properties = $item['properties'] ?? [];

                if (! is_array($properties) || ($properties !== [] && array_is_list($properties))) {
                    throw new RuntimeException("{$label} property bag must be a map.");
                }
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withBoltPropertyMaps(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['properties'] = $this->boltPropertyMap($row['properties'] ?? []);
        }
        unset($row);

        return $rows;
    }

    /**
     * Empty PHP arrays are encoded as Bolt lists by Laudis. An explicit
     * CypherMap preserves the empty map required by Cypher's SET += operator.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>|CypherMap<mixed>
     */
    private function boltPropertyMap(array $properties): array|CypherMap
    {
        return $properties === [] ? ParameterHelper::asMap([]) : $properties;
    }

    private function verificationValue(mixed $row, string $field): mixed
    {
        if (is_array($row)) {
            return $row[$field] ?? null;
        }
        if (is_object($row) && method_exists($row, 'get')) {
            return $row->get($field);
        }
        if (is_object($row)) {
            return $row->{$field} ?? null;
        }

        return null;
    }
}
