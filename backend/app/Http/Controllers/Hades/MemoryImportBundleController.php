<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use App\Services\MemoryImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class MemoryImportBundleController extends Controller
{
    public function __invoke(Request $request, MemoryImportService $imports): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'workspace_binding_id' => ['required', 'string'],
            'source' => ['nullable', 'array'],
            'entries' => ['required', 'array', 'min:1', 'max:100'],
            'entries.*.source_local_id' => ['nullable', 'string', 'max:191'],
            'entries.*.source_hash' => ['required', 'string', 'max:191'],
            'entries.*.kind' => ['nullable', 'string', 'max:80'],
            'entries.*.summary' => ['required', 'string', 'min:8', 'max:4000'],
            'entries.*.payload' => ['nullable', 'array'],
            'entries.*.provenance' => ['nullable', 'array'],
        ]);

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];

        if ($agent->project_id !== $validated['project_id']) {
            return $this->error('project_mismatch', 'Hades agent token is scoped to a different project.', Response::HTTP_FORBIDDEN);
        }

        $binding = DB::table('hades_workspace_bindings')
            ->where('id', $validated['workspace_binding_id'])
            ->where('project_id', $validated['project_id'])
            ->where('hades_agent_id', $agent->id)
            ->first();

        if (! $binding) {
            return $this->error('workspace_binding_not_found', 'Workspace binding was not found.', Response::HTTP_NOT_FOUND);
        }

        if ($binding->status !== 'linked') {
            return $this->error('workspace_binding_unlinked', 'Workspace binding is not linked.', Response::HTTP_CONFLICT);
        }

        $batch = $imports->createBatch(
            $validated['project_id'],
            null,
            $binding->id,
            null,
            $agent->id,
            $validated['entries'],
            null,
            $validated['source'] ?? [],
        );

        return response()->json(['import_batch' => $batch], Response::HTTP_CREATED);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
