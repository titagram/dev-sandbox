<?php

namespace App\Http\Controllers\Dashboard\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DashboardWorkspaceBindingController extends Controller
{
    use ChecksDashboardRoles;

    public function __invoke(Request $request, string $project): JsonResponse
    {
        $this->abortUnlessReader($request);
        abort_unless(DB::table('projects')->where('id', $project)->where('status', '!=', 'deleted')->exists(), 404);

        $bindings = DB::table('hades_workspace_bindings')
            ->join('hades_agents', 'hades_agents.id', '=', 'hades_workspace_bindings.hades_agent_id')
            ->where('hades_workspace_bindings.project_id', $project)
            ->where('hades_workspace_bindings.status', 'linked')
            ->select([
                'hades_workspace_bindings.id',
                'hades_workspace_bindings.project_id',
                'hades_workspace_bindings.hades_agent_id',
                'hades_workspace_bindings.external_agent_id',
                'hades_workspace_bindings.local_project_id',
                'hades_workspace_bindings.workspace_fingerprint',
                'hades_workspace_bindings.display_path',
                'hades_workspace_bindings.git_remote_display',
                'hades_workspace_bindings.git_remote_hash',
                'hades_workspace_bindings.head_commit',
                'hades_workspace_bindings.status',
                'hades_workspace_bindings.last_seen_at',
                'hades_workspace_bindings.updated_at',
                'hades_agents.label as agent_label',
            ])
            ->orderByDesc('hades_workspace_bindings.updated_at')
            ->limit(100)
            ->get()
            ->map(fn (object $binding): array => [
                'id' => (string) $binding->id,
                'project_id' => (string) $binding->project_id,
                'hades_agent_id' => (string) $binding->hades_agent_id,
                'external_agent_id' => (string) $binding->external_agent_id,
                'agent_label' => (string) $binding->agent_label,
                'local_project_id' => $binding->local_project_id ? (string) $binding->local_project_id : null,
                'workspace_fingerprint' => (string) $binding->workspace_fingerprint,
                'display_path' => (string) $binding->display_path,
                'git_remote_display' => $binding->git_remote_display ? (string) $binding->git_remote_display : null,
                'git_remote_hash' => $binding->git_remote_hash ? (string) $binding->git_remote_hash : null,
                'head_commit' => $binding->head_commit ? (string) $binding->head_commit : null,
                'status' => (string) $binding->status,
                'last_seen_at' => $binding->last_seen_at ? (string) $binding->last_seen_at : null,
                'memory_counts' => [
                    'entries' => DB::table('project_memory_entries')->where('project_id', $project)->count(),
                    'proposals' => DB::table('hades_memory_proposals')->where('workspace_binding_id', $binding->id)->count(),
                    'imports' => DB::table('memory_import_batches')->where('target_workspace_binding_id', $binding->id)->count(),
                ],
                'updated_at' => (string) $binding->updated_at,
            ])
            ->all();

        return response()->json(['workspace_bindings' => $bindings]);
    }

    private function abortUnlessReader(Request $request): void
    {
        abort_unless(
            $this->userHasRole($request->user(), 'PM')
            || $this->userHasRole($request->user(), 'Developer')
            || $this->userHasRole($request->user(), 'Sysadmin')
            || $this->userHasRole($request->user(), 'Admin'),
            403,
        );
    }
}
