<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use App\Services\Hades\HadesProjectAwareness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class GraphTraversalController extends Controller
{
    private const DIRECTIONS = ['any', 'out', 'in'];

    public function __construct(private readonly HadesProjectAwareness $awareness) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'workspace_binding_id' => ['required', 'string'],
            'start' => ['required', 'string', 'max:1000'],
            'direction' => ['nullable', 'string', Rule::in(self::DIRECTIONS)],
            'max_depth' => ['nullable', 'integer', 'min:1', 'max:3'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $binding = $this->linkedBinding($agent, $validated['project_id'], $validated['workspace_binding_id']);

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        $artifact = DB::table('hades_agent_artifacts')
            ->where('project_id', $validated['project_id'])
            ->where('workspace_binding_id', $binding->id)
            ->whereIn('schema', ['hades.php_graph.v1', 'hades.code_graph.v1'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if (! $artifact) {
            return $this->error('graph_artifact_not_found', 'No Hades code graph artifact is available for this workspace.', Response::HTTP_NOT_FOUND);
        }

        $graph = $this->decodePayload($artifact->artifact);
        $traversal = $this->traverse(
            graph: $graph,
            start: (string) $validated['start'],
            direction: (string) ($validated['direction'] ?? 'any'),
            maxDepth: (int) ($validated['max_depth'] ?? 2),
            limit: (int) ($validated['limit'] ?? 20),
        );
        $version = 'graph_traversal_'.hash('sha256', $artifact->id.'|'.$artifact->updated_at.'|'.$validated['start'].'|'.json_encode($traversal['edge_ids'], JSON_THROW_ON_ERROR));

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'workspace_binding_id' => $binding->id,
            'version' => $version,
            'etag' => $version,
            'artifact_id' => (string) $artifact->id,
            'schema' => (string) $artifact->schema,
            'head_commit' => $this->payloadString($graph, ['head_commit', 'commit']),
            'start' => (string) $validated['start'],
            'direction' => (string) ($validated['direction'] ?? 'any'),
            'max_depth' => (int) ($validated['max_depth'] ?? 2),
            'limit' => (int) ($validated['limit'] ?? 20),
            'count' => count($traversal['nodes']),
            'edge_count' => count($traversal['edges']),
            'truncated' => $traversal['truncated'],
            'match_fields' => $traversal['match_fields'],
            'freshness' => $this->awareness->freshnessForBinding($binding),
            'provenance' => [
                'artifact_id' => (string) $artifact->id,
                'schema' => (string) $artifact->schema,
                'artifact_version' => 'artifact_'.hash('sha256', $artifact->id.'|'.$artifact->updated_at.'|'.$artifact->sha256),
            ],
            'nodes' => $traversal['nodes'],
            'edges' => $traversal['edges'],
            'server_time' => now()->toISOString(),
        ]);
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>, edge_ids: list<string>, match_fields: list<string>, truncated: bool}
     */
    private function traverse(array $graph, string $start, string $direction, int $maxDepth, int $limit): array
    {
        [$nodesById, $edges] = $this->normaliseGraph($graph);
        $startNeedle = Str::lower(trim($start));
        $startIds = [];
        $matchFields = [];

        foreach ($nodesById as $id => $node) {
            $fields = $this->matchingFields($node, $startNeedle);
            if ($fields !== []) {
                $startIds[] = $id;
                $matchFields = array_merge($matchFields, $fields);
            }
        }

        if ($startIds === []) {
            foreach ($edges as $edge) {
                if (str_contains(Str::lower(implode(' ', [$edge['kind'], $edge['from'], $edge['to']])), $startNeedle)) {
                    $startIds[] = $edge['from'];
                    $startIds[] = $edge['to'];
                    $matchFields[] = 'edge';
                }
            }
        }

        $startIds = array_values(array_unique(array_filter($startIds)));
        $visited = [];
        $includedEdges = [];
        $queue = [];
        foreach ($startIds as $id) {
            $queue[] = [$id, 0];
        }

        while ($queue !== [] && count($visited) < $limit) {
            [$id, $depth] = array_shift($queue);
            if (isset($visited[$id])) {
                continue;
            }
            if (! isset($nodesById[$id])) {
                $nodesById[$id] = $this->node($id, 'reference', ['name' => $id]);
            }
            $visited[$id] = true;
            if ($depth >= $maxDepth) {
                continue;
            }

            foreach ($edges as $idx => $edge) {
                $next = null;
                if ($direction !== 'in' && $edge['from'] === $id) {
                    $next = $edge['to'];
                }
                if ($direction !== 'out' && $edge['to'] === $id) {
                    $next = $edge['from'];
                }
                if ($next === null) {
                    continue;
                }
                $includedEdges[$idx] = true;
                if (! isset($visited[$next])) {
                    $queue[] = [$next, $depth + 1];
                }
            }
        }

        $nodeIds = array_keys($visited);
        $nodeSet = array_fill_keys($nodeIds, true);
        $edgeRows = [];
        $edgeIds = [];
        foreach ($edges as $idx => $edge) {
            if (! isset($includedEdges[$idx])) {
                continue;
            }
            if (! isset($nodeSet[$edge['from']]) || ! isset($nodeSet[$edge['to']])) {
                continue;
            }
            $edgeRows[] = $edge;
            $edgeIds[] = $edge['id'];
            if (count($edgeRows) >= $limit) {
                break;
            }
        }

        $nodeRows = [];
        foreach (array_slice($nodeIds, 0, $limit) as $id) {
            $nodeRows[] = $nodesById[$id];
        }

        return [
            'nodes' => $nodeRows,
            'edges' => $edgeRows,
            'edge_ids' => $edgeIds,
            'match_fields' => array_values(array_unique($matchFields)),
            'truncated' => count($visited) > count($nodeRows) || count($includedEdges) > count($edgeRows),
        ];
    }

    /**
     * @return array{0: array<string, array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function normaliseGraph(array $graph): array
    {
        $nodes = [];
        foreach (($graph['routes'] ?? []) as $route) {
            if (! is_array($route)) {
                continue;
            }
            $name = trim((string) ($route['name'] ?? ''));
            $method = trim((string) ($route['method'] ?? ''));
            $uri = trim((string) ($route['uri'] ?? ''));
            $id = $name !== '' ? 'route:'.$name : 'route:'.trim($method.' '.$uri);
            if ($id === 'route:') {
                continue;
            }
            $nodes[$id] = $this->node($id, 'route', [
                'method' => $method,
                'uri' => $uri,
                'name' => $name,
                'handler' => trim((string) ($route['handler'] ?? '')),
                'path' => trim((string) ($route['path'] ?? '')),
            ]);
        }

        foreach (($graph['symbols'] ?? []) as $symbol) {
            if (! is_array($symbol)) {
                continue;
            }
            $id = trim((string) ($symbol['name'] ?? ''));
            if ($id === '') {
                continue;
            }
            $nodes[$id] = $this->node($id, trim((string) ($symbol['kind'] ?? 'symbol')) ?: 'symbol', [
                'name' => $id,
                'class' => trim((string) ($symbol['class'] ?? '')),
                'role' => trim((string) ($symbol['role'] ?? '')),
                'path' => trim((string) ($symbol['path'] ?? '')),
                'line' => $symbol['line'] ?? null,
            ]);
        }

        $edges = [];
        foreach (($graph['edges'] ?? []) as $idx => $edge) {
            if (! is_array($edge)) {
                continue;
            }
            $from = trim((string) ($edge['from'] ?? ''));
            $to = trim((string) ($edge['to'] ?? ''));
            if ($from === '' || $to === '') {
                continue;
            }
            if (! isset($nodes[$from])) {
                $nodes[$from] = $this->node($from, 'reference', ['name' => $from]);
            }
            if (! isset($nodes[$to])) {
                $nodes[$to] = $this->node($to, 'reference', ['name' => $to]);
            }
            $kind = trim((string) ($edge['kind'] ?? 'edge')) ?: 'edge';
            $edges[] = [
                'id' => 'edge:'.hash('sha256', $idx.'|'.$kind.'|'.$from.'|'.$to),
                'kind' => $kind,
                'from' => $from,
                'to' => $to,
                'provenance' => isset($edge['provenance']) && is_array($edge['provenance']) ? $edge['provenance'] : [],
            ];
        }

        return [$nodes, $edges];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function node(string $id, string $kind, array $attributes): array
    {
        return [
            'id' => $id,
            'kind' => $kind,
            'label' => $this->compact((string) ($attributes['name'] ?? $attributes['uri'] ?? $id), 240),
            'path' => $attributes['path'] ?? null,
            'attributes' => array_filter($attributes, fn (mixed $value): bool => $value !== null && $value !== ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function matchingFields(array $node, string $needle): array
    {
        if ($needle === '') {
            return [];
        }
        $fields = [];
        foreach (['id', 'kind', 'label', 'path'] as $field) {
            if (isset($node[$field]) && str_contains(Str::lower((string) $node[$field]), $needle)) {
                $fields[] = $field;
            }
        }
        $attributes = isset($node['attributes']) && is_array($node['attributes']) ? $node['attributes'] : [];
        foreach ($attributes as $key => $value) {
            if (is_scalar($value) && str_contains(Str::lower((string) $value), $needle)) {
                $fields[] = 'attributes.'.$key;
            }
        }

        return $fields;
    }

    private function linkedBinding(object $agent, string $projectId, string $bindingId): mixed
    {
        if ($agent->project_id !== $projectId) {
            return $this->error('project_mismatch', 'Hades agent token is scoped to a different project.', Response::HTTP_FORBIDDEN);
        }

        $binding = DB::table('hades_workspace_bindings')
            ->where('id', $bindingId)
            ->where('project_id', $projectId)
            ->where('hades_agent_id', $agent->id)
            ->first();

        if (! $binding) {
            return $this->error('workspace_binding_not_found', 'Workspace binding was not found.', Response::HTTP_NOT_FOUND);
        }

        if ($binding->status !== 'linked') {
            return $this->error('workspace_binding_unlinked', 'Workspace binding is not linked.', Response::HTTP_CONFLICT);
        }

        return $binding;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(mixed $payload): array
    {
        $decoded = is_string($payload) ? json_decode($payload, true) : $payload;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function payloadString(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key]) && trim((string) $payload[$key]) !== '') {
                return trim((string) $payload[$key]);
            }
        }

        return '';
    }

    private function compact(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        if (strlen($value) <= $max) {
            return $value;
        }

        return rtrim(substr($value, 0, $max - 3)).'...';
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
