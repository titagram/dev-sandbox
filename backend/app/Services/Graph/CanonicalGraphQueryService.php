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

    private const EDGE_FAMILIES = [
        'call' => ['CALLS', 'CALLS_METHOD', 'STATIC_CALL'],
        'dependency' => ['USES_DEPENDENCY', 'INSTANTIATES', 'EXTENDS', 'USES_FORM_REQUEST', 'THROWS_EXCEPTION', 'API_RESOURCE_REF'],
        'route' => ['ROUTE_HANDLER'],
        'test' => ['TEST_COVERS_SYMBOL', 'TEST_IMPORTS', 'TEST_COVERS_ROUTE'],
        'table' => ['QUERY_TABLE', 'ELOQUENT_QUERY'],
    ];

    private readonly DashboardGraphPublicHandle $publicHandles;

    public function __construct(
        private readonly ?Neo4jClient $client = null,
        ?DashboardGraphPublicHandle $publicHandles = null,
    ) {
        $this->publicHandles = $publicHandles ?? new DashboardGraphPublicHandle;
    }

    /** @param array<string, mixed> $params */
    public function query(string $projectId, string $scopeType, string $scopeId, string $type, array $params = []): array
    {
        if (! in_array($scopeType, ['repository', 'workspace_binding'], true)) {
            throw new InvalidArgumentException('Unsupported graph source scope: '.$scopeType);
        }
        if (! in_array($type, self::TYPES, true)) {
            throw new RuntimeException('Unsupported query type: '.$type);
        }
        $this->assertFamilies($params['families'] ?? []);
        if ($type === 'traverse') {
            $this->assertDirection($params['direction'] ?? 'any');
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
            $activeGraphVersion = $this->activeGraphVersion($projection);
            $client = $this->client ?? app(Neo4jClientFactory::class)->client();
            $rows = match ($type) {
                'callers' => $client->run($this->adjacency('in', $params['families'] ?? []), $this->symbolParams($params, $projection)),
                'callees' => $client->run($this->adjacency('out', $params['families'] ?? []), $this->symbolParams($params, $projection)),
                'path' => $this->runPath($client, $params, $projection),
                'traverse' => $this->runTraverse($client, $params, $projection),
            };
        } catch (BoundedTraversalUnavailable) {
            return array_merge($base, ['found' => false, 'reason' => 'graph_projection_rebuild_required']);
        } catch (\Throwable) {
            return array_merge($base, ['found' => false, 'reason' => 'query_error']);
        }

        $limit = $type === 'traverse' ? max(1, min(51, (int) ($params['limit'] ?? 20))) : null;
        [$nodes, $edges, $truncated, $matchFields] = $this->normaliseRows(
            $rows,
            $limit,
            $projectId,
            $scopeType,
            $scopeId,
            $activeGraphVersion,
        );

        return array_merge($base, [
            'found' => true,
            'reason' => null,
            'results' => $nodes,
            'edges' => $edges,
            'truncated' => $truncated,
            'traversal_match_fields' => $matchFields,
        ]);
    }

    private function adjacency(string $direction, array $families = []): string
    {
        $edgePredicate = $this->edgePredicate('edge', $families);
        $match = $direction === 'in'
            ? '(result:CanonicalGraphNode {project_id: $project_id, source_scope_type: $source_scope_type, source_scope_id: $source_scope_id, graph_version: $graph_version})-[edge]->(node:CanonicalGraphNode {project_id: $project_id, source_scope_type: $source_scope_type, source_scope_id: $source_scope_id, external_id: $external_id, graph_version: $graph_version})'
            : '(node:CanonicalGraphNode {project_id: $project_id, source_scope_type: $source_scope_type, source_scope_id: $source_scope_id, external_id: $external_id, graph_version: $graph_version})-[edge]->(result:CanonicalGraphNode {project_id: $project_id, source_scope_type: $source_scope_type, source_scope_id: $source_scope_id, graph_version: $graph_version})';

        return "MATCH {$match} WHERE {$edgePredicate} RETURN properties(result) AS node, labels(result) AS labels, properties(edge) AS edge, type(edge) AS edge_type LIMIT \$limit";
    }

    /** @param array<string, mixed> $params */
    private function symbolParams(array $params, object $projection): array
    {
        return [
            'external_id' => (string) ($params['symbol_id'] ?? ''),
            'graph_version' => $this->activeGraphVersion($projection),
            'project_id' => (string) $projection->project_id,
            'source_scope_type' => (string) $projection->source_scope_type,
            'source_scope_id' => (string) $projection->source_scope_id,
            'semantic_edge_types' => $this->edgeTypes($params['families'] ?? []),
            'limit' => max(1, min(50, (int) ($params['limit'] ?? 50))),
        ];
    }

    /** @param array<string, mixed> $params */
    private function runPath(Neo4jClient $client, array $params, object $projection): mixed
    {
        $depth = max(1, min(10, (int) ($params['max_depth'] ?? 5)));
        $edgePredicate = $this->edgePredicate('r', $params['families'] ?? []);

        return $client->run(
            'MATCH (from:CanonicalGraphNode {project_id: $project_id, source_scope_type: $source_scope_type, source_scope_id: $source_scope_id, external_id: $from_external_id, graph_version: $graph_version}), '
            .'(to:CanonicalGraphNode {project_id: $project_id, source_scope_type: $source_scope_type, source_scope_id: $source_scope_id, external_id: $to_external_id, graph_version: $graph_version}) '
            .'MATCH p = shortestPath((from)-[*1..'.$depth.']-(to)) '
            .'WHERE ALL(n IN nodes(p) WHERE n.graph_version = $graph_version AND n.project_id = $project_id AND n.source_scope_type = $source_scope_type AND n.source_scope_id = $source_scope_id) '
            .'AND ALL(r IN relationships(p) WHERE '.$edgePredicate.') '
            .'RETURN [n IN nodes(p) | {node: properties(n), labels: labels(n)}] AS nodes, '
            .'[r IN relationships(p) | properties(r) + {type: type(r)}] AS edges LIMIT 1',
            [
                'from_external_id' => (string) ($params['from_symbol_id'] ?? ''),
                'to_external_id' => (string) ($params['to_symbol_id'] ?? ''),
                'graph_version' => $this->activeGraphVersion($projection),
                'project_id' => (string) $projection->project_id,
                'source_scope_type' => (string) $projection->source_scope_type,
                'source_scope_id' => (string) $projection->source_scope_id,
                'semantic_edge_types' => $this->edgeTypes($params['families'] ?? []),
            ],
        );
    }

    /** @param array<string, mixed> $params */
    private function runTraverse(Neo4jClient $client, array $params, object $projection): mixed
    {
        $depth = max(1, min(3, (int) ($params['max_depth'] ?? 2)));
        $direction = (string) ($params['direction'] ?? 'any');
        $this->assertDirection($direction);
        $limit = max(1, min(51, (int) ($params['limit'] ?? 20)));
        $query = strtolower((string) ($params['start'] ?? ''));
        $startExternalId = array_key_exists('start_external_id', $params)
            ? trim((string) $params['start_external_id'])
            : null;
        if ($startExternalId === '') {
            throw new InvalidArgumentException('invalid_start_external_id');
        }
        $graphVersion = $this->activeGraphVersion($projection);
        $semanticEdgeTypes = $this->edgeTypes($params['families'] ?? []);
        $scopePredicate = isset($projection->project_id)
            ? ' AND start.project_id = $project_id AND start.source_scope_type = $source_scope_type AND start.source_scope_id = $source_scope_id '
            : '';
        $targetScopePredicate = isset($projection->project_id)
            ? ' WHERE target.project_id = $project_id AND target.source_scope_type = $source_scope_type AND target.source_scope_id = $source_scope_id '
            : '';
        $capabilityRows = $this->materialiseSequences($client->run(
            'MATCH (version:CanonicalGraphVersion {graph_version: $graph_version}) RETURN coalesce(version.traversal_schema_version, 0) AS traversal_schema_version',
            ['graph_version' => $graphVersion],
        ));
        if (! is_array($capabilityRows) || (int) ($capabilityRows[0]['traversal_schema_version'] ?? 0) !== 1) {
            throw new BoundedTraversalUnavailable;
        }
        $startMatch = $startExternalId !== null
            ? 'start.external_id = $start_external_id'
            : implode(' OR ', [
                "toLower(coalesce(start.external_id, '')) CONTAINS \$start_query",
                "toLower(coalesce(start.name, '')) CONTAINS \$start_query",
                "toLower(coalesce(start.label, '')) CONTAINS \$start_query",
                "toLower(coalesce(start.path, '')) CONTAINS \$start_query",
            ]);
        $matchFields = $startExternalId !== null
            ? '[]'
            : "[field IN ['external_id', 'name', 'label', 'path'] WHERE toLower(coalesce(start[field], '')) CONTAINS \$start_query]";
        $startParams = [
            'graph_version' => $graphVersion,
            'fetch_limit' => $limit + 1,
            'semantic_edge_types' => $semanticEdgeTypes,
            'direction' => $direction,
            'max_depth' => $depth,
            'limit' => $limit,
            ...$this->scopeParams($projection),
        ];
        if ($startExternalId !== null) {
            $startParams['start_external_id'] = $startExternalId;
        } else {
            $startParams['start_query'] = $query;
        }
        $startRows = $client->run(
            'MATCH (start:CanonicalGraphNode {graph_version: $graph_version}) WHERE ('.$startMatch.') '
            .$scopePredicate
            .'WITH start ORDER BY start.external_id LIMIT $fetch_limit '
            .'RETURN properties(start) AS node, labels(start) AS labels, '
            .$matchFields.' AS match_fields',
            $startParams,
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
                .'WHERE adjacency.edge_type IN $semantic_edge_types '
                .'WITH frontier_id, adjacency ORDER BY adjacency.'.$rankProperty.' LIMIT $per_frontier_fetch_limit '
                .'MATCH (target:CanonicalGraphNode {graph_version: $graph_version, external_id: adjacency.to_external_id}) '
                .$targetScopePredicate
                .'RETURN frontier_id AS source_id, properties(target) AS node, labels(target) AS labels, '
                .'adjacency {edge_json: adjacency.edge_json, external_id: adjacency.edge_external_id, type: adjacency.edge_type} AS edge } '
                .'RETURN source_id, node, labels, edge ORDER BY source_id, node.external_id, edge.external_id LIMIT $hop_fetch_limit',
                [
                    'frontier_ids' => $frontier,
                    'graph_version' => $graphVersion,
                    'direction' => $direction,
                    'per_frontier_fetch_limit' => $perFrontierFetchLimit,
                    'hop_fetch_limit' => $hopFetchLimit,
                    'semantic_edge_types' => $semanticEdgeTypes,
                    ...$this->scopeParams($projection),
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

        return [
            'found' => false,
            'reason' => null,
            'graph_version' => $projection?->graph_version,
            'active_graph_version' => $projection?->active_graph_version,
            'quality' => $projection?->quality,
            'results' => [],
            'edges' => [],
            'metadata' => [
                'project_id' => $projectId,
                'source_scope_type' => $scopeType,
                'source_scope_id' => $scopeId,
                'projection_id' => $projection?->id,
                'graph_version' => $projection?->graph_version,
                'active_graph_version' => $projection?->active_graph_version,
                'artifact_id' => $projection?->artifact_id,
                'artifact_type' => $projection?->artifact_type,
                'schema' => $schema,
                'head_commit' => $headCommit,
                'quality' => $projection?->quality,
                'node_count' => $projection?->node_count,
                'relationship_count' => $projection?->relationship_count,
            ],
        ];
    }

    private function activeGraphVersion(object $projection): string
    {
        $active = trim((string) ($projection->active_graph_version ?? ''));
        if ($active === '') {
            throw new BoundedTraversalUnavailable;
        }

        return $active;
    }

    /** @return array{0: list<array<string,mixed>>, 1: list<array<string,mixed>>, 2: bool, 3: list<string>} */
    private function normaliseRows(
        mixed $result,
        ?int $limit = null,
        ?string $projectId = null,
        ?string $scopeType = null,
        ?string $scopeId = null,
        ?string $activeGraphVersion = null,
    ): array {
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
                    $normalised = $this->node(
                        is_array($node) ? $node : [],
                        $projectId,
                        $scopeType,
                        $scopeId,
                        $activeGraphVersion,
                    );
                    $nodesById[$normalised['id']] ??= $normalised;
                }
            } elseif (isset($row['node'])) {
                $normalised = $this->node($row, $projectId, $scopeType, $scopeId, $activeGraphVersion);
                $nodesById[$normalised['id']] ??= $normalised;
            }
            if (isset($row['edge']) && is_array($row['edge'])) {
                $edge = $row['edge'];
                if (isset($row['edge_type'])) {
                    $edge['type'] ??= $row['edge_type'];
                }
                $edgesById[$this->edgeIdentity($edge)] ??= $this->normaliseEdge(
                    $edge,
                    $projectId,
                    $scopeType,
                    $scopeId,
                    $activeGraphVersion,
                );
            }
            if (isset($row['edges']) && is_array($row['edges'])) {
                foreach ($row['edges'] as $edge) {
                    if (is_array($edge)) {
                        $edgesById[$this->edgeIdentity($edge)] ??= $this->normaliseEdge(
                            $edge,
                            $projectId,
                            $scopeType,
                            $scopeId,
                            $activeGraphVersion,
                        );
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

    private function node(
        array $row,
        ?string $projectId = null,
        ?string $scopeType = null,
        ?string $scopeId = null,
        ?string $activeGraphVersion = null,
    ): array {
        $props = is_array($row['node'] ?? null) ? $row['node'] : $row;
        $id = (string) ($props['external_id'] ?? $props['symbol_id'] ?? $props['id'] ?? 'node-'.md5(json_encode($props)));
        $handle = (string) ($props['public_handle'] ?? '');
        if ($handle === '' && $projectId !== null && $scopeType !== null && $scopeId !== null && $activeGraphVersion !== null) {
            try {
                $handle = $this->publicHandles->forNode($projectId, $scopeType, $scopeId, $activeGraphVersion, $id);
            } catch (InvalidArgumentException) {
                $handle = '';
            }
        }

        return [
            'id' => $id,
            'handle' => $handle !== '' ? $handle : null,
            'kind' => $this->nodeKind($row, $props),
            'labels' => array_values($row['labels'] ?? []),
            'properties' => $props,
        ];
    }

    private function normaliseEdge(
        array $edge,
        ?string $projectId = null,
        ?string $scopeType = null,
        ?string $scopeId = null,
        ?string $activeGraphVersion = null,
    ): array {
        $edgeType = strtoupper((string) ($edge['edge_type'] ?? $edge['type'] ?? 'RELATED'));
        $sourceId = (string) ($edge['source_id'] ?? $edge['from'] ?? $edge['from_external_id'] ?? '');
        $targetId = (string) ($edge['target_id'] ?? $edge['to'] ?? $edge['to_external_id'] ?? '');
        $normalised = array_merge($edge, [
            'type' => $edgeType,
            'edge_type' => $edgeType,
            'family' => $this->edgeFamily($edgeType),
            'source_id' => $sourceId,
            'target_id' => $targetId,
        ]);

        if ($projectId !== null && $scopeType !== null && $scopeId !== null && $activeGraphVersion !== null) {
            try {
                $normalised['source_handle'] = $sourceId === ''
                    ? null
                    : $this->publicHandles->forNode($projectId, $scopeType, $scopeId, $activeGraphVersion, $sourceId);
                $normalised['target_handle'] = $targetId === ''
                    ? null
                    : $this->publicHandles->forNode($projectId, $scopeType, $scopeId, $activeGraphVersion, $targetId);
            } catch (InvalidArgumentException) {
                $normalised['source_handle'] = null;
                $normalised['target_handle'] = null;
            }
        }

        return $normalised;
    }

    private function nodeKind(array $row, array $properties): string
    {
        $kind = strtolower(trim((string) ($properties['kind'] ?? '')));
        if ($kind !== '') {
            return $kind;
        }

        foreach (($row['labels'] ?? []) as $label) {
            $kind = strtolower(trim((string) $label));
            if ($kind !== '' && $kind !== 'symbol') {
                return $kind;
            }
        }

        return 'unknown';
    }

    private function edgeFamily(string $edgeType): string
    {
        foreach (self::EDGE_FAMILIES as $family => $types) {
            if (in_array($edgeType, $types, true)) {
                return $family;
            }
        }

        return 'other';
    }

    private function edgeTypes(mixed $families): array
    {
        $this->assertFamilies($families);
        if ($families === []) {
            return array_values(array_unique(array_merge(...array_values(self::EDGE_FAMILIES))));
        }

        $types = [];
        foreach ($families as $family) {
            array_push($types, ...self::EDGE_FAMILIES[$family]);
        }

        return array_values(array_unique($types));
    }

    private function assertFamilies(mixed $families): void
    {
        if (! is_array($families) || ! array_is_list($families)) {
            throw new InvalidArgumentException('invalid_family');
        }

        foreach ($families as $family) {
            if (! is_string($family) || ! isset(self::EDGE_FAMILIES[$family])) {
                throw new InvalidArgumentException('invalid_family');
            }
        }
    }

    private function assertDirection(mixed $direction): void
    {
        if (! is_string($direction) || ! in_array($direction, ['in', 'out', 'any'], true)) {
            throw new InvalidArgumentException('invalid_direction');
        }
    }

    private function edgePredicate(string $edgeAlias, mixed $families = []): string
    {
        $types = array_map(
            static fn (string $type): string => "'".$type."'",
            $this->edgeTypes($families),
        );

        return 'type('.$edgeAlias.') IN ['.implode(', ', $types).']';
    }

    private function scopeParams(object $projection): array
    {
        if (! isset($projection->project_id)) {
            return [];
        }

        return [
            'project_id' => (string) $projection->project_id,
            'source_scope_type' => (string) $projection->source_scope_type,
            'source_scope_id' => (string) $projection->source_scope_id,
        ];
    }
}

class BoundedTraversalUnavailable extends RuntimeException {}
