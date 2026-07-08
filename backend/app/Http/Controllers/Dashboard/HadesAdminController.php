<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class HadesAdminController extends Controller
{
    use ChecksDashboardRoles;

    public function __invoke(Request $request): Response
    {
        abort_unless($this->userHasRole($request->user(), 'Admin'), 403);

        return Inertia::render('Admin/Hades', [
            'dashboard' => [
                'user' => $this->dashboardUser($request->user()),
                'navigation' => $this->dashboardNavigation($request->user()),
            ],
            'projects' => DB::table('projects')
                ->select(['id', 'name', 'slug'])
                ->where('status', 'active')
                ->orderBy('name')
                ->limit(100)
                ->get(),
            'bootstrapTokens' => DB::table('hades_bootstrap_tokens')
                ->join('projects', 'projects.id', '=', 'hades_bootstrap_tokens.project_id')
                ->select([
                    'hades_bootstrap_tokens.id',
                    'hades_bootstrap_tokens.project_id',
                    'projects.name as project_name',
                    'hades_bootstrap_tokens.token_prefix',
                    'hades_bootstrap_tokens.name',
                    'hades_bootstrap_tokens.expires_at',
                    'hades_bootstrap_tokens.revoked_at',
                    'hades_bootstrap_tokens.last_used_at',
                ])
                ->orderByDesc('hades_bootstrap_tokens.created_at')
                ->limit(100)
                ->get(),
            'workspaces' => DB::table('hades_workspace_bindings')
                ->join('projects', 'projects.id', '=', 'hades_workspace_bindings.project_id')
                ->join('hades_agents', 'hades_agents.id', '=', 'hades_workspace_bindings.hades_agent_id')
                ->select([
                    'hades_workspace_bindings.id',
                    'hades_workspace_bindings.project_id',
                    'projects.name as project_name',
                    'hades_workspace_bindings.display_path',
                    'hades_workspace_bindings.status',
                    'hades_agents.label as agent_label',
                ])
                ->orderByDesc('hades_workspace_bindings.updated_at')
                ->limit(100)
                ->get(),
            'jobs' => DB::table('hades_agent_jobs')
                ->select(['id', 'project_id', 'workspace_binding_id', 'capability', 'status', 'policy', 'created_at', 'completed_at', 'failed_at'])
                ->orderByDesc('created_at')
                ->limit(100)
                ->get(),
            'memoryProposals' => DB::table('hades_memory_proposals')
                ->select(['id', 'project_id', 'workspace_binding_id', 'action', 'intent', 'summary', 'status', 'reason_code', 'created_at'])
                ->orderByDesc('created_at')
                ->limit(100)
                ->get(),
        ]);
    }
}
