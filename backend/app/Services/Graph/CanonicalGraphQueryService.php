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
        return ['external_id' => (string) ($params['symbol_id'] ?? ''), 'graph_version' => (string) $projection->graph_version, 'limit' => max(1, min(50, (int) ($params['limit'] ?? 50)))];
    }

    /** @param array<string, mixed> $params */
    private function runPath(Neo4jClient $client, array $params, object $projection): mixed
    {
        $depth = max(1, min(10, (int) ($params['max_depth'] ?? 5)));

        return $client->run('MATCH (from:CanonicalGraphNode {external_id: $from_external_id, graph_version: $graph_version}), (to:CanonicalGraphNode {external_id: $to_external_id, graph_version: $graph_version}) MATCH p = shortestPath((from)-[:CALLS*1..'.$depth.']-(to)) WHERE ALL(n IN nodes(p) WHERE n.graph_version = $graph_version) AND ALL(r IN relationships(p) WHERE r.graph_version = $graph_version) RETURN [n IN nodes(p) | {node: properties(n), labels: labels(n)}] AS nodes, [r IN relationships(p) | properties(r)] AS edges LIMIT 1', ['from_external_id' => (string) ($params['from_symbol_id'] ?? ''), 'to_external_id' => (string) ($params['to_symbol_id'] ?? ''), 'graph_version' => (string) $projection->graph_version]);
    }

    /** @param array<string, mixed> $params */
    private function runTraverse(Neo4jClient $client, array $params, object $projection): mixed
    {
        $depth = max(1, min(3, (int) ($params['max_depth'] ?? 2)));
        $direction = (string) ($params['direction'] ?? 'any');
        $pattern = match ($direction) {
            'in' => '<-[*1..'.$depth.']-',
            'out' => '-[*1..'.$depth.']->',
            default => '-[*1..'.$depth.']-',
        };

        $limit = max(1, min(50, (int) ($params['limit'] ?? 20)));
        $query = strtolower((string) ($params['start'] ?? ''));
        $startMatch = implode(' OR ', [
            "toLower(coalesce(start.external_id, '')) CONTAINS \$start_query",
            "toLower(coalesce(start.name, '')) CONTAINS \$start_query",
            "toLower(coalesce(start.label, '')) CONTAINS \$start_query",
            "toLower(coalesce(start.path, '')) CONTAINS \$start_query",
        ]);

        $cypher = 'MATCH (start:CanonicalGraphNode {graph_version: $graph_version}) WHERE ('.$startMatch.') '
            .'OPTIONAL MATCH p=(start)'.$pattern.'(node:CanonicalGraphNode {graph_version: $graph_version}) '
            .'WHERE p IS NULL OR (ALL(n IN nodes(p) WHERE n.graph_version = $graph_version) AND ALL(r IN relationships(p) WHERE r.graph_version = $graph_version)) '
            .'WITH start, p ORDER BY start.external_id, length(p) LIMIT $path_fetch_limit '
            .'WITH collect({nodes: CASE WHEN p IS NULL THEN [start] ELSE nodes(p) END, edges: CASE WHEN p IS NULL THEN [] ELSE [r IN relationships(p) | properties(r) + {source_id: startNode(r).external_id, target_id: endNode(r).external_id}] END}) AS candidatePaths, collect(DISTINCT start) AS starts '
            .'WITH candidatePaths[0..$path_limit] AS paths, starts, size(candidatePaths) > $path_limit AS pathTruncated '
            .'UNWIND paths AS path UNWIND path.nodes AS candidate WITH paths, starts, pathTruncated, collect(DISTINCT candidate) AS uniqueNodes '
            .'RETURN [n IN uniqueNodes[0..$fetch_limit] | {node: properties(n), labels: labels(n)}] AS nodes, '
            .'reduce(allEdges = [], path IN paths | allEdges + path.edges) AS edges, '
            .'size(uniqueNodes) > $limit OR pathTruncated AS truncated, '
            ."[field IN ['external_id', 'name', 'label', 'path'] WHERE any(s IN starts WHERE toLower(coalesce(s[field], '')) CONTAINS \$start_query)] AS match_fields";

        $pathLimit = min(200, max($limit + 1, $limit * $depth));

        return $client->run($cypher, [
            'start_query' => $query,
            'graph_version' => (string) $projection->graph_version,
            'limit' => $limit,
            'fetch_limit' => $limit + 1,
            'path_limit' => $pathLimit,
            'path_fetch_limit' => $pathLimit + 1,
        ]);
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

    /** @return array{0: list<array<string,mixed>>, 1: list<array<string,mixed>>, 2: bool, 3: list<string>} */
    private function normaliseRows(mixed $result, ?int $limit = null): array
    {
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
