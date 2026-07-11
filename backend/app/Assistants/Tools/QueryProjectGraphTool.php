<?php

namespace App\Assistants\Tools;

use App\Services\Graph\GraphQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

final class QueryProjectGraphTool implements Tool
{
    public function __construct(private readonly ?GraphQueryService $queryService = null) {}

    public function name(): string
    {
        return 'query_project_graph';
    }

    public function description(): Stringable|string
    {
        return 'Query the latest stored DevBoard graph artifact for a project. Read-only; supports structured queries (callers/callees/path) against Neo4j or text search against JSON artifacts.';
    }

    public function handle(Request $request): Stringable|string
    {
        return json_encode($this->payload($request->all()), JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(array $arguments): array
    {
        $projectId = (string) ($arguments['project_id'] ?? '');
        $structuredQuery = $arguments['structured_query'] ?? null;

        if (is_array($structuredQuery) && isset($structuredQuery['type'])) {
            return $this->structuredPayload($projectId, $structuredQuery);
        }

        $query = trim((string) ($arguments['query'] ?? ''));
        $limit = min(25, max(1, (int) ($arguments['limit'] ?? 10)));

        $snapshot = DB::table('snapshots')
            ->where('project_id', $projectId)
            ->whereNotNull('graph_snapshot_artifact_id')
            ->orderByDesc('created_at')
            ->first();

        if (! $snapshot) {
            return $this->notFound($projectId, $query, 'graph_snapshot_not_found');
        }

        $artifact = DB::table('artifacts')->where('id', $snapshot->graph_snapshot_artifact_id)->first();
        if (! $artifact || ! $artifact->storage_path || ! Storage::disk('local')->exists($artifact->storage_path)) {
            return $this->notFound($projectId, $query, 'graph_artifact_missing');
        }

        $payload = json_decode(Storage::disk('local')->get($artifact->storage_path), true);
        $nodes = array_values(array_filter(is_array($payload['nodes'] ?? null) ? $payload['nodes'] : [], 'is_array'));
        $relationships = $this->relationships(is_array($payload['relationships'] ?? null) ? $payload['relationships'] : []);
        $matchedNodes = $this->matchedNodes($nodes, $query, $limit);
        $matchedNodeIds = array_fill_keys(array_column($matchedNodes, 'id'), true);
        $matchedRelationships = array_values(array_filter(
            $relationships,
            static fn (array $edge): bool => isset($matchedNodeIds[$edge['from']]) || isset($matchedNodeIds[$edge['to']]),
        ));

        return [
            'tool' => $this->name(),
            'source_status' => 'verified_from_code',
            'found' => true,
            'project_id' => $projectId,
            'query' => $query,
            'snapshot_id' => (string) $snapshot->id,
            'artifact_id' => (string) $artifact->id,
            'stats' => [
                'nodes' => count($nodes),
                'relationships' => count($relationships),
            ],
            'nodes' => $matchedNodes,
            'relationships' => array_slice($matchedRelationships, 0, min(50, $limit * 3)),
        ];
    }

    /**
     * @param  array<string, mixed>  $sq
     * @return array<string, mixed>
     */
    private function structuredPayload(string $projectId, array $sq): array
    {
        $type = (string) ($sq['type'] ?? '');
        $params = ['project_id' => $projectId];

        if ($type === 'callers' || $type === 'callees') {
            $params['symbol_id'] = (string) ($sq['symbol_id'] ?? '');
            $params['limit'] = (int) ($sq['limit'] ?? 50);
        } elseif ($type === 'path') {
            $params['from_symbol_id'] = (string) ($sq['from_symbol_id'] ?? '');
            $params['to_symbol_id'] = (string) ($sq['to_symbol_id'] ?? '');
            $params['max_depth'] = (int) ($sq['max_depth'] ?? 5);
        } else {
            return $this->notFound($projectId, '', 'unsupported_query_type');
        }

        $service = $this->queryService ?? app(GraphQueryService::class);
        $result = $service->query($type, $params);

        return array_merge([
            'tool' => $this->name(),
            'source_status' => 'verified_from_code',
            'project_id' => $projectId,
            'query_type' => $type,
            'symbol_id' => $params['symbol_id'] ?? null,
            'from_symbol_id' => $params['from_symbol_id'] ?? null,
            'to_symbol_id' => $params['to_symbol_id'] ?? null,
            'max_depth' => $params['max_depth'] ?? null,
            'limit' => $params['limit'] ?? null,
        ], $result);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()
                ->description('The DevBoard project ULID whose latest graph should be queried.')
                ->required(),
            'query' => $schema->string()
                ->description('Case-insensitive text to match against node ids, labels, and properties. Empty query returns a bounded preview.'),
            'limit' => $schema->integer()
                ->description('Maximum number of nodes to return, capped at 25.')
                ->min(1)
                ->max(25),
            'structured_query' => $schema->object()
                ->description('Structured graph query (callers, callees, path) that uses Neo4j. Must include type field.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notFound(string $projectId, string $query, string $reason): array
    {
        return [
            'tool' => $this->name(),
            'source_status' => 'verified_from_code',
            'found' => false,
            'project_id' => $projectId,
            'query' => $query,
            'reason' => $reason,
            'nodes' => [],
            'relationships' => [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function matchedNodes(array $nodes, string $query, int $limit): array
    {
        $matches = [];

        foreach ($nodes as $node) {
            if ($query !== '' && stripos(json_encode($node, JSON_THROW_ON_ERROR), $query) === false) {
                continue;
            }

            $matches[] = [
                'id' => $this->nodeId($node),
                'labels' => array_values(array_filter(is_array($node['labels'] ?? null) ? $node['labels'] : [])),
                'name' => (string) data_get($node, 'properties.name', $this->nodeId($node)),
                'path' => data_get($node, 'properties.path'),
                'properties' => $this->boundedProperties(is_array($node['properties'] ?? null) ? $node['properties'] : []),
            ];

            if (count($matches) >= $limit) {
                break;
            }
        }

        return $matches;
    }

    /**
     * @param  list<mixed>  $relationships
     * @return list<array<string, mixed>>
     */
    private function relationships(array $relationships): array
    {
        $normalized = [];

        foreach ($relationships as $index => $relationship) {
            if (! is_array($relationship)) {
                continue;
            }

            $from = $relationship['from'] ?? $relationship['source_id'] ?? $relationship['source'] ?? null;
            $to = $relationship['to'] ?? $relationship['target_id'] ?? $relationship['target'] ?? null;

            if (! is_string($from) || ! is_string($to) || $from === '' || $to === '') {
                continue;
            }

            $normalized[] = [
                'id' => (string) ($relationship['id'] ?? 'edge-'.$index),
                'from' => $from,
                'to' => $to,
                'type' => (string) ($relationship['type'] ?? 'RELATED'),
            ];
        }

        return $normalized;
    }

    private function nodeId(array $node): string
    {
        foreach (['id', 'node_id', 'symbol_id'] as $key) {
            if (isset($node[$key]) && is_string($node[$key]) && $node[$key] !== '') {
                return $node[$key];
            }
        }

        return (string) data_get($node, 'properties.name', 'unknown');
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function boundedProperties(array $properties): array
    {
        return collect($properties)
            ->take(12)
            ->map(fn (mixed $value): mixed => is_string($value) ? substr($value, 0, 240) : $value)
            ->all();
    }
}
