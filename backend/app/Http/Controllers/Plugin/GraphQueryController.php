<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Projects\ProjectLifecycleService;
use App\Services\Graph\CanonicalGraphQueryService;
use App\Services\Graph\CanonicalGraphRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GraphQueryController extends Controller
{
    private const ALLOWED_TYPES = ['callers', 'callees', 'path'];

    public function __construct(
        private readonly ProjectLifecycleService $lifecycle,
        private readonly CanonicalGraphQueryService $queryService,
        private readonly CanonicalGraphRepository $graphs,
    ) {}

    public function __invoke(Request $request, string $project): JsonResponse
    {
        if ($error = $this->lifecycle->pluginProjectWriteGuard($project)) {
            return $error;
        }

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(self::ALLOWED_TYPES)],
            'graph_version' => ['prohibited'],
            'symbol_id' => ['required_if:type,callers,callees', 'string', 'max:1000'],
            'from_symbol_id' => ['required_if:type,path', 'string', 'max:1000'],
            'to_symbol_id' => ['required_if:type,path', 'string', 'max:1000'],
            'start' => ['required_if:type,traverse', 'string', 'max:1000'],
            'direction' => ['sometimes', 'string', Rule::in(['any', 'out', 'in'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'max_depth' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'repository_id' => ['nullable', 'string', 'max:191', Rule::prohibitedIf(fn (): bool => $request->filled('workspace_binding_id'))],
            'workspace_binding_id' => ['nullable', 'string', 'max:191', Rule::prohibitedIf(fn (): bool => $request->filled('repository_id'))],
        ]);

        $type = (string) $validated['type'];

        $params = [];
        if ($type === 'callers' || $type === 'callees') {
            $params['symbol_id'] = (string) ($validated['symbol_id'] ?? '');
            $params['limit'] = (int) ($validated['limit'] ?? 50);
        } elseif ($type === 'path') {
            $params['from_symbol_id'] = (string) ($validated['from_symbol_id'] ?? '');
            $params['to_symbol_id'] = (string) ($validated['to_symbol_id'] ?? '');
            $params['max_depth'] = (int) ($validated['max_depth'] ?? 5);
        }

        $scopeType = isset($validated['workspace_binding_id']) ? 'workspace_binding' : 'repository';
        $scopeId = (string) ($validated['workspace_binding_id'] ?? $validated['repository_id'] ?? '');
        if ($scopeId === '') {
            $scopes = $this->graphs->listScopes($project);
            if (count($scopes) !== 1) {
                return response()->json(['protocol_version' => 'v1', 'project_id' => $project, 'query_type' => $type, 'found' => false, 'reason' => 'scope_required', 'graph_version' => null, 'quality' => null, 'results' => [], 'edges' => [], 'metadata' => ['scopes' => $scopes]], 422);
            }
            $scopeType = $scopes[0]['source_scope_type'];
            $scopeId = $scopes[0]['source_scope_id'];
        }
        $params['project_id'] = $project;
        $params['scope_type'] = $scopeType;
        $params['scope_id'] = $scopeId;

        $result = $this->queryService->query($project, $scopeType, $scopeId, $type, $params);

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $project,
            'query_type' => $type,
            'source_scope_type' => $scopeType,
            'source_scope_id' => $scopeId,
            'symbol_id' => $params['symbol_id'] ?? null,
            'from_symbol_id' => $params['from_symbol_id'] ?? null,
            'to_symbol_id' => $params['to_symbol_id'] ?? null,
            'max_depth' => $params['max_depth'] ?? null,
            'limit' => $params['limit'] ?? null,
            'found' => $result['found'],
            'reason' => $result['reason'],
            'graph_version' => $result['graph_version'],
            'quality' => $result['quality'],
            'results' => $result['results'],
            'edges' => $result['edges'],
            'metadata' => $result['metadata'],
        ]);
    }
}
