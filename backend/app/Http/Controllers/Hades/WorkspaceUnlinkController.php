<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class WorkspaceUnlinkController extends Controller
{
    public function __invoke(Request $request, string $workspaceBinding): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'agent_id' => ['nullable', 'string', 'max:191'],
        ]);

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];

        if ($agent->project_id !== $validated['project_id']) {
            return $this->error(
                'project_mismatch',
                'Hades agent token is scoped to a different project.',
                Response::HTTP_FORBIDDEN,
            );
        }

        if (($validated['agent_id'] ?? null) && $validated['agent_id'] !== $agent->external_agent_id) {
            return $this->error(
                'agent_mismatch',
                'Hades agent token is scoped to a different external agent.',
                Response::HTTP_FORBIDDEN,
            );
        }

        $binding = DB::table('hades_workspace_bindings')
            ->where('id', $workspaceBinding)
            ->where('project_id', $validated['project_id'])
            ->where('hades_agent_id', $agent->id)
            ->first();

        if (! $binding) {
            return $this->error('workspace_binding_not_found', 'Workspace binding was not found.', Response::HTTP_NOT_FOUND);
        }

        $now = now();
        DB::transaction(function () use ($binding, $now): void {
            DB::table('hades_workspace_bindings')->where('id', $binding->id)->update([
                'status' => 'unlinked',
                'unlinked_at' => $now,
                'updated_at' => $now,
            ]);

            if (! Schema::hasTable('hades_agent_jobs')) {
                return;
            }

            DB::table('hades_agent_jobs')
                ->where('workspace_binding_id', $binding->id)
                ->whereNotIn('status', ['completed', 'failed', 'expired', 'cancelled', 'unlinked'])
                ->update([
                    'status' => 'unlinked',
                    'updated_at' => $now,
                ]);
        });

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $binding->project_id,
            'agent_id' => $agent->external_agent_id,
            'backend_agent_id' => $agent->id,
            'workspace_binding_id' => $binding->id,
            'status' => 'unlinked',
            'server_time' => now()->toISOString(),
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
