<?php

namespace App\Services\Graph;

use App\Services\Neo4j\Neo4jClient;
use RuntimeException;

class Neo4jCanonicalGraphProjector
{
    private const BATCH_SIZE = 500;

    /** @return array{nodes:int, relationships:int} */
    public function project(array $graph, object $projection, Neo4jClient $client): array
    {
        $scope = ['graph_version' => $projection->graph_version, 'project_id' => $projection->project_id, 'source_scope_type' => $projection->source_scope_type, 'source_scope_id' => $projection->source_scope_id];
        $client->run('CREATE INDEX canonical_node_version_external IF NOT EXISTS FOR (n:CanonicalGraphNode) ON (n.graph_version, n.external_id)');
        $client->run('CREATE INDEX canonical_version_identity IF NOT EXISTS FOR (v:CanonicalGraphVersion) ON (v.graph_version)');
        $client->run('CALL db.awaitIndexes(300)');
        $client->run('MERGE (v:CanonicalGraphVersion {graph_version: $graph_version}) SET v += $metadata, v.current = false, v.status = \'projecting\'', $scope + ['metadata' => $scope + ['snapshot_id' => $projection->snapshot_id ?? null]]);

        foreach (array_chunk($graph['nodes'], self::BATCH_SIZE) as $batch) {
            $client->run('UNWIND $nodes AS node MERGE (n:CanonicalGraphNode {graph_version: $graph_version, external_id: node.id}) SET n += node.properties, n.labels = node.labels, n.project_id = $project_id, n.source_scope_type = $source_scope_type, n.source_scope_id = $source_scope_id', $scope + ['nodes' => $batch]);
        }
        foreach ($this->relationshipsByType($graph['relationships']) as $type => $relationships) {
            foreach (array_chunk($relationships, self::BATCH_SIZE) as $batch) {
                $client->run("UNWIND \$relationships AS relationship MATCH (source:CanonicalGraphNode {graph_version: \$graph_version, external_id: relationship.source_id}) MATCH (target:CanonicalGraphNode {graph_version: \$graph_version, external_id: relationship.target_id}) MERGE (source)-[r:{$type} {graph_version: \$graph_version, external_id: relationship.id}]->(target) SET r += relationship.properties, r.project_id = \$project_id, r.source_scope_type = \$source_scope_type, r.source_scope_id = \$source_scope_id", $scope + ['relationships' => $batch]);
            }
        }

        $verified = $client->run('MATCH (n:CanonicalGraphNode {graph_version: $graph_version}) WITH count(n) AS nodes MATCH ()-[r {graph_version: $graph_version}]->() RETURN nodes, count(r) AS relationships', $scope);
        if (is_iterable($verified)) {
            foreach ($verified as $row) {
                $actualNodes = is_array($row) ? ($row['nodes'] ?? null) : (method_exists($row, 'get') ? $row->get('nodes') : ($row->nodes ?? null));
                $actualRelationships = is_array($row) ? ($row['relationships'] ?? null) : (method_exists($row, 'get') ? $row->get('relationships') : ($row->relationships ?? null));
                if ((int) $actualNodes !== count($graph['nodes']) || (int) $actualRelationships !== count($graph['relationships'])) {
                    throw new RuntimeException('Canonical graph verification count mismatch.');
                }
                break;
            }
        }
        $client->run("MATCH (candidate:CanonicalGraphVersion {graph_version: \$graph_version})\nMATCH (other:CanonicalGraphVersion {project_id: \$project_id, source_scope_type: \$source_scope_type, source_scope_id: \$source_scope_id})\nSET other.current = false, candidate.current = true, candidate.status = 'ready', candidate.projected_at = datetime()", $scope);

        return ['nodes' => count($graph['nodes']), 'relationships' => count($graph['relationships'])];
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
}
