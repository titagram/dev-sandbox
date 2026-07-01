<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class WorkspaceBindController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'agent_id' => ['nullable', 'string', 'max:191'],
            'local_project_id' => ['nullable', 'string', 'max:191'],
            'workspace_fingerprint' => ['required', 'string', 'max:191'],
            'display_path' => ['required', 'string', 'max:512'],
            'git_remote_display' => ['nullable', 'string', 'max:512'],
            'git_remote_hash' => ['nullable', 'string', 'max:191'],
            'head_commit' => ['nullable', 'string', 'max:80'],
            'platform' => ['nullable', 'string', 'max:191'],
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

        $conflict = DB::table('hades_workspace_bindings')
            ->where('workspace_fingerprint', $validated['workspace_fingerprint'])
            ->where('project_id', '!=', $validated['project_id'])
            ->where('status', 'linked')
            ->first();

        if ($conflict) {
            return response()->json([
                'error' => [
                    'code' => 'workspace_project_conflict',
                    'message' => 'Workspace fingerprint is already linked to another project.',
                    'current_project_id' => $conflict->project_id,
                    'requested_project_id' => $validated['project_id'],
                ],
            ], Response::HTTP_CONFLICT);
        }

        $now = now();
        $attributes = [
            'external_agent_id' => $agent->external_agent_id,
            'local_project_id' => $validated['local_project_id'] ?? null,
            'display_path' => $validated['display_path'],
            'git_remote_display' => $validated['git_remote_display'] ?? null,
            'git_remote_hash' => $validated['git_remote_hash'] ?? null,
            'head_commit' => $validated['head_commit'] ?? null,
            'platform' => $validated['platform'] ?? $agent->platform,
            'status' => 'linked',
            'last_seen_at' => $now,
            'unlinked_at' => null,
            'updated_at' => $now,
        ];

        $binding = DB::transaction(function () use ($agent, $attributes, $now, $validated) {
            $existing = DB::table('hades_workspace_bindings')
                ->where('project_id', $validated['project_id'])
                ->where('hades_agent_id', $agent->id)
                ->where('workspace_fingerprint', $validated['workspace_fingerprint'])
                ->first();

            if ($existing) {
                DB::table('hades_workspace_bindings')->where('id', $existing->id)->update($attributes);

                return DB::table('hades_workspace_bindings')->where('id', $existing->id)->first();
            }

            $id = (string) Str::ulid();

            DB::table('hades_workspace_bindings')->insert(array_merge($attributes, [
                'id' => $id,
                'project_id' => $validated['project_id'],
                'hades_agent_id' => $agent->id,
                'workspace_fingerprint' => $validated['workspace_fingerprint'],
                'linked_at' => $now,
                'created_at' => $now,
            ]));

            return DB::table('hades_workspace_bindings')->where('id', $id)->first();
        });

        return response()->json($this->payload($binding, $agent));
    }

    private function payload(object $binding, object $agent): array
    {
        return [
            'protocol_version' => 'v1',
            'project_id' => $binding->project_id,
            'agent_id' => $agent->external_agent_id,
            'backend_agent_id' => $agent->id,
            'workspace_binding_id' => $binding->id,
            'status' => $binding->status,
            'workspace' => [
                'workspace_binding_id' => $binding->id,
                'workspace_fingerprint' => $binding->workspace_fingerprint,
                'display_path' => $binding->display_path,
                'git_remote_display' => $binding->git_remote_display,
                'git_remote_hash' => $binding->git_remote_hash,
                'head_commit' => $binding->head_commit,
                'platform' => $binding->platform,
                'last_seen_at' => optional($binding->last_seen_at)->toISOString(),
            ],
            'server_time' => now()->toISOString(),
        ];
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
