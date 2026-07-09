<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Projects\ProjectLifecycleService;
use App\Services\Graph\GraphQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GraphQueryController extends Controller
{
    private const ALLOWED_TYPES = ['callers', 'callees', 'path'];

    public function __construct(
        private readonly ProjectLifecycleService $lifecycle,
        private readonly GraphQueryService $queryService,
    ) {
    }

    public function __invoke(Request $request, string $project): JsonResponse
    {
        if ($error = $this->lifecycle->pluginProjectWriteGuard($project)) {
            return $error;
        }

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(self::ALLOWED_TYPES)],
            'symbol_id' => ['required_if:type,callers,callees', 'string', 'max:1000'],
            'from_symbol_id' => ['required_if:type,path', 'string', 'max:1000'],
            'to_symbol_id' => ['required_if:type,path', 'string', 'max:1000'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'max_depth' => ['sometimes', 'integer', 'min:1', 'max:10'],
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

        $result = $this->queryService->query($type, $params);

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $project,
            'query_type' => $type,
            'symbol_id' => $params['symbol_id'] ?? null,
            'from_symbol_id' => $params['from_symbol_id'] ?? null,
            'to_symbol_id' => $params['to_symbol_id'] ?? null,
            'max_depth' => $params['max_depth'] ?? null,
            'limit' => $params['limit'] ?? null,
            'found' => $result['found'],
            'results' => $result['results'],
        ]);
    }
}
