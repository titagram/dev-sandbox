<?php

namespace App\Services\Graph;

use App\Services\Neo4j\Neo4jClient;
use App\Services\Neo4jClientFactory;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class CanonicalGraphQueryService
{
    private const TYPES = ['callers', 'callees', 'path', 'traverse'];

    public function __construct(private readonly ?Neo4jClient $client = null) {}

    /** @param array<string, mixed> $params */
    public function query(string $projectId, string $scopeType, string $scopeId, string $type, array $params = []): array
    {
        if (! in_array($scopeType, ['repository', 'workspace_binding'], true)) {
            throw new InvalidArgumentException('Unsupported graph source scope: '.$scopeType);
        }
        if (! in_array($type, self::TYPES, true)) {
            throw new RuntimeException('Unsupported query type: '.$type);
        }
        $empty = $this->envelope($projectId, $scopeType, $scopeId);
        if ($projectId === '') {
            throw new InvalidArgumentException('A project is required.');
        }
        if ($scopeId === '') {
            return array_merge($empty, ['found' => false, 'reason' => 'graph_scope_not_found']);
        }

        $owned = $scopeType === 'repository'
            ? DB::table('repositories')->where('id', $scopeId)->where('project_id', $projectId)->exists()
            : DB::table('hades_workspace_bindings')->where('id', $scopeId)->where('project_id', $projectId)->where('status', 'linked')->exists();
        if (! $owned) {
            return array_merge($empty, ['found' => false, 'reason' => 'graph_scope_not_found']);
        }

        $projection = DB::table('canonical_graph_projections')
            ->where('project_id', $projectId)->where('source_scope_type', $scopeType)->where('source_scope_id', $scopeId)
            ->where('status', 'ready')->orderByDesc('projected_at')->orderByDesc('id')->first();
        if ($projection === null) {
            return array_merge($empty, ['found' => false, 'reason' => 'graph_projection_not_ready']);
        }

        $base = $this->envelope($projectId, $scopeType, $scopeId, $projection);
        try {
            $client = $this->client ?? app(Neo4jClientFactory::class)->client();
            $rows = match ($type) {
                'callers' => $client->run($this->adjacency('in'), $this->symbolParams($params, $projection)),
                'callees' => $client->run($this->adjacency('out'), $this->symbolParams($params, $projection)),
                'path' => $this->runPath($client, $params, $projection),
                'traverse' => $this->runTraverse($client, $params, $projection),
            };
        } catch (BoundedTraversalUnavailable) {
            return array_merge($base, ['found' => false, 'reason' => 'graph_projection_rebuild_required']);
        } catch (\Throwable) {
            return array_merge($base, ['found' => false, 'reason' => 'query_error']);
        }

        $limit = $type === 'traverse' ? max(1, min(50, (int) ($params['limit'] ?? 20))) : null;
        [$nodes, $edges, $truncated, $matchFields] = $this->normaliseRows($rows, $limit);

        return array_merge($base, [
            'found' => true,
            'reason' => null,
            'results' => $nodes,
            'edges' => $edges,
            'truncated' => $truncated,
            'traversal_match_fields' => $matchFields,
        ]);
    }

    private function adjacency(string $direction): string
    {
        $match = $direction === 'in'
            ? '(result:CanonicalGraphNode {graph_version: $graph_version})-[edge:CALLS {graph_version: $graph_version}]->(node:CanonicalGraphNode {external_id: $external_id, graph_version: $graph_version})'
            : '(node:CanonicalGraphNode {external_id: $external_id, graph_version: $graph_version})-[edge:CALLS {graph_version: $graph_version}]->(result:CanonicalGraphNode {graph_version: $graph_version})';

        return "MATCH {$match} RETURN properties(result) AS node, labels(result) AS labels, properties(edge) AS edge LIMIT \$limit";
    }

    /** @param array<string, mixed> $params */
    private function symbolParams(array $params, object $projection): array
    {
        return ['external_id' => (string) ($params['symbol_id'] ?? ''), 'graph_version' => $this->activeGraphVersion($projection), 'limit' => max(1, min(50, (int) ($params['limit'] ?? 50)))];
    }

    /** @param array<string, mixed> $params */
    private function runPath(Neo4jClient $client, array $params, object $projection): mixed
    {
        $depth = max(1, min(10, (int) ($params['max_depth'] ?? 5)));

        return $client->run('MATCH (from:CanonicalGraphNode {external_id: $from_external_id, graph_version: $graph_version}), (to:CanonicalGraphNode {external_id: $to_external_id, graph_version: $graph_version}) MATCH p = shortestPath((from)-[:CALLS*1..'.$depth.']-(to)) WHERE ALL(n IN nodes(p) WHERE n.graph_version = $graph_version) AND ALL(r IN relationships(p) WHERE r.graph_version = $graph_version) RETURN [n IN nodes(p) | {node: properties(n), labels: labels(n)}] AS nodes, [r IN relationships(p) | properties(r)] AS edges LIMIT 1', ['from_external_id' => (string) ($params['from_symbol_id'] ?? ''), 'to_external_id' => (string) ($params['to_symbol_id'] ?? ''), 'graph_version' => $this->activeGraphVersion($projection)]);
    }

    /** @param array<string, mixed> $params */
    private function runTraverse(Neo4jClient $client, array $params, object $projection): mixed
    {
        $depth = max(1, min(3, (int) ($params['max_depth'] ?? 2)));
        $direction = (string) ($params['direction'] ?? 'any');
        $limit = max(1, min(50, (int) ($params['limit'] ?? 20)));
        $query = strtolower((string) ($params['start'] ?? ''));
        $graphVersion = $this->activeGraphVersion($projection);
        $capabilityRows = $this->materialiseSequences($client->run(
            'MATCH (version:CanonicalGraphVersion {graph_version: $graph_version}) RETURN coalesce(version.traversal_schema_version, 0) AS traversal_schema_version',
            ['graph_version' => $graphVersion],
        ));
        if (! is_array($capabilityRows) || (int) ($capabilityRows[0]['traversal_schema_version'] ?? 0) !== 1) {
            throw new BoundedTraversalUnavailable;
        }
        $startMatch = implode(' OR ', [
            "toLower(coalesce(start.external_id, '')) CONTAINS \$start_query",
            "toLower(coalesce(start.name, '')) CONTAINS \$start_query",
            "toLower(coalesce(start.label, '')) CONTAINS \$start_query",
            "toLower(coalesce(start.path, '')) CONTAINS \$start_query",
        ]);
        $startRows = $client->run(
            'MATCH (start:CanonicalGraphNode {graph_version: $graph_version}) WHERE ('.$startMatch.') '
            .'WITH start ORDER BY start.external_id LIMIT $fetch_limit '
            .'RETURN properties(start) AS node, labels(start) AS labels, '
            ."[field IN ['external_id', 'name', 'label', 'path'] WHERE toLower(coalesce(start[field], '')) CONTAINS \$start_query] AS match_fields",
            [
                'start_query' => $query,
                'graph_version' => $graphVersion,
                'fetch_limit' => $limit + 1,
            ],
        );
        [$starts, , , $matchFields] = $this->normaliseRows($startRows);
        $truncated = count($starts) > $limit;
        $starts = array_slice($starts, 0, $limit);
        $visited = [];
        foreach ($starts as $start) {
            $visited[$start['id']] = $start;
        }
        $frontier = array_keys($visited);
        $edges = [];
        $directionClause = in_array($direction, ['in', 'out'], true) ? ', direction: $direction' : '';
        $rankProperty = in_array($direction, ['in', 'out'], true) ? 'direction_rank' : 'any_rank';

        for ($hop = 0; $hop < $depth && $frontier !== []; $hop++) {
            $perFrontierFetchLimit = $limit + 1;
            $hopFetchLimit = count($frontier) * $perFrontierFetchLimit;
            $hopRows = $client->run(
                'UNWIND $frontier_ids AS frontier_id '
                .'CALL { WITH frontier_id '
                .'MATCH (adjacency:CanonicalGraphAdjacency {graph_version: $graph_version, from_external_id: frontier_id'.$directionClause.'}) '
                .'WHERE adjacency.'.$rankProperty.' < $per_frontier_fetch_limit '
                .'WITH frontier_id, adjacency ORDER BY adjacency.'.$rankProperty.' LIMIT $per_frontier_fetch_limit '
                .'MATCH (target:CanonicalGraphNode {graph_version: $graph_version, external_id: adjacency.to_external_id}) '
                .'RETURN frontier_id AS source_id, properties(target) AS node, labels(target) AS labels, '
                .'adjacency {edge_json: adjacency.edge_json, external_id: adjacency.edge_external_id, type: adjacency.edge_type} AS edge } '
                .'RETURN source_id, node, labels, edge ORDER BY source_id, node.external_id, edge.external_id LIMIT $hop_fetch_limit',
                [
                    'frontier_ids' => $frontier,
                    'graph_version' => $graphVersion,
                    'direction' => $direction,
                    'per_frontier_fetch_limit' => $perFrontierFetchLimit,
                    'hop_fetch_limit' => $hopFetchLimit,
                ],
            );
            $materializedRows = $this->materialiseSequences($hopRows);
            if (! is_array($materializedRows)) {
                $materializedRows = [];
            }
            foreach ($materializedRows as &$materializedRow) {
                if (! is_array($materializedRow) || ! is_array($materializedRow['edge'] ?? null) || ! is_string($materializedRow['edge']['edge_json'] ?? null)) {
                    continue;
                }
                $decodedEdge = json_decode($materializedRow['edge']['edge_json'], true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($decodedEdge)) {
                    throw new RuntimeException('Canonical graph adjacency edge payload is invalid.');
                }
                $materializedRow['edge'] = $decodedEdge;
            }
            unset($materializedRow);
            $rowsBySource = [];
            foreach ($materializedRows as $row) {
                if (is_array($row)) {
                    $rowsBySource[(string) ($row['source_id'] ?? '')] = ($rowsBySource[(string) ($row['source_id'] ?? '')] ?? 0) + 1;
                }
            }
            if (collect($rowsBySource)->contains(fn (int $count): bool => $count >= $perFrontierFetchLimit)) {
                $truncated = true;
            }
            [$hopNodes, $hopEdges] = $this->normaliseRows($materializedRows);
            foreach ($hopEdges as $edge) {
                $edges[$this->edgeIdentity($edge)] = $edge;
            }
            $nextFrontier = [];
            foreach ($hopNodes as $node) {
                if (isset($visited[$node['id']])) {
                    continue;
                }
                if (count($visited) >= $limit) {
                    $truncated = true;

                    continue;
                }
                $visited[$node['id']] = $node;
                $nextFrontier[] = $node['id'];
            }
            $frontier = $nextFrontier;
            if (count($visited) >= $limit) {
                break;
            }
        }

        $returnedIds = array_fill_keys(array_keys($visited), true);
        $edges = array_filter($edges, fn (array $edge): bool => isset(
            $returnedIds[(string) ($edge['source_id'] ?? '')],
            $returnedIds[(string) ($edge['target_id'] ?? '')],
        ));
        ksort($edges);
        $nodes = array_map(function (array $node): array {
            $properties = $node['properties'];
            $properties['external_id'] ??= $node['id'];

            return ['node' => $properties, 'labels' => $node['labels']];
        }, array_values($visited));

        return [[
            'nodes' => $nodes,
            'edges' => array_values($edges),
            'truncated' => $truncated,
            'match_fields' => $matchFields,
        ]];
    }

    private function envelope(string $projectId, string $scopeType, string $scopeId, ?object $projection = null): array
    {
        $schema = $projection?->artifact_type;
        $headCommit = $projection?->head_commit;
        if ($projection !== null && $scopeType === 'workspace_binding') {
            $artifact = DB::table('hades_agent_artifacts')->where('id', $projection->artifact_id)->first();
            $schema = $artifact?->schema ?? $schema;
            $payload = $artifact ? json_decode((string) $artifact->artifact, true) : [];
            $headCommit = is_array($payload) ? ($payload['head_commit'] ?? $payload['commit'] ?? $headCommit) : $headCommit;
        }

        return ['found' => false, 'reason' => null, 'graph_version' => $projection?->graph_version, 'quality' => $projection?->quality, 'results' => [], 'edges' => [], 'metadata' => ['project_id' => $projectId, 'source_scope_type' => $scopeType, 'source_scope_id' => $scopeId, 'projection_id' => $projection?->id, 'graph_version' => $projection?->graph_version, 'artifact_id' => $projection?->artifact_id, 'artifact_type' => $projection?->artifact_type, 'schema' => $schema, 'head_commit' => $headCommit, 'quality' => $projection?->quality, 'node_count' => $projection?->node_count, 'relationship_count' => $projection?->relationship_count]];
    }

    private function activeGraphVersion(object $projection): string
    {
        return (string) (($projection->active_graph_version ?? null) ?: $projection->graph_version);
    }

    /** @return array{0: list<array<string,mixed>>, 1: list<array<string,mixed>>, 2: bool, 3: list<string>} */
    private function normaliseRows(mixed $result, ?int $limit = null): array
    {
        $result = $this->materialiseSequences($result);
        if (! is_array($result)) {
            return [[], [], false, []];
        }
        $nodesById = [];
        $edgesById = [];
        $truncated = false;
        $matchFields = [];
        foreach ($result as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (isset($row['nodes']) && is_array($row['nodes'])) {
                foreach ($row['nodes'] as $node) {
                    $normalised = $this->node(is_array($node) ? $node : []);
                    $nodesById[$normalised['id']] ??= $normalised;
                }
            } elseif (isset($row['node'])) {
                $normalised = $this->node($row);
                $nodesById[$normalised['id']] ??= $normalised;
            }
            if (isset($row['edge']) && is_array($row['edge'])) {
                $edgesById[$this->edgeIdentity($row['edge'])] ??= $row['edge'];
            }
            if (isset($row['edges']) && is_array($row['edges'])) {
                foreach ($row['edges'] as $edge) {
                    if (is_array($edge)) {
                        $edgesById[$this->edgeIdentity($edge)] ??= $edge;
                    }
                }
            }
            $truncated = $truncated || ($row['truncated'] ?? false) === true;
            if (is_array($row['match_fields'] ?? null)) {
                $matchFields = array_merge($matchFields, $row['match_fields']);
            }
        }

        $nodes = array_values($nodesById);
        if ($limit !== null && count($nodes) > $limit) {
            $truncated = true;
            $nodes = array_slice($nodes, 0, $limit);
        }

        if ($limit !== null) {
            $returnedNodeIds = array_fill_keys(array_column($nodes, 'id'), true);
            $edgesById = array_filter($edgesById, function (array $edge) use ($returnedNodeIds): bool {
                $source = (string) ($edge['source_id'] ?? $edge['source'] ?? '');
                $target = (string) ($edge['target_id'] ?? $edge['target'] ?? '');

                return $source !== '' && $target !== ''
                    && isset($returnedNodeIds[$source], $returnedNodeIds[$target]);
            });
        }

        return [$nodes, array_values($edgesById), $truncated, array_values(array_unique($matchFields))];
    }

    private function materialiseSequences(mixed $value): mixed
    {
        if ($value instanceof \Traversable) {
            $value = iterator_to_array($value);
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->materialiseSequences($item);
            }
        }

        return $value;
    }

    private function edgeIdentity(array $edge): string
    {
        return (string) ($edge['external_id'] ?? $edge['id'] ?? hash('sha256', json_encode($edge)));
    }

    private function node(array $row): array
    {
        $props = is_array($row['node'] ?? null) ? $row['node'] : $row;

        return ['id' => (string) ($props['external_id'] ?? $props['symbol_id'] ?? $props['id'] ?? 'node-'.md5(json_encode($props))), 'labels' => array_values($row['labels'] ?? []), 'properties' => $props];
    }
}

class BoundedTraversalUnavailable extends RuntimeException {}
