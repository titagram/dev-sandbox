<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SystemShowController extends Controller
{
    use ChecksDashboardRoles;

    public function __invoke(Request $request): Response
    {
        abort_unless($this->canViewSystem($request), 403);

        $project = DB::table('projects')->orderBy('created_at')->first();

        return Inertia::render('System/Show', [
            'project' => $project,
            'health' => [
                'projects_total' => DB::table('projects')->count(),
                'repositories_total' => DB::table('repositories')->count(),
                'active_devices' => DB::table('devices')->where('status', 'active')->count(),
                'runs_total' => DB::table('runs')->count(),
                'failed_runs' => DB::table('runs')->where('status', 'failed')->count(),
                'artifacts_total' => DB::table('artifacts')->count(),
                'graph_enabled_repositories' => DB::table('repositories')->where('graph_enabled', true)->count(),
                'plugin_tokens_active' => DB::table('api_tokens')->whereNull('revoked_at')->count(),
            ],
            'runtime' => [
                'graph_enabled' => (bool) config('services.devboard.graph_enabled', true),
                'graph_import_mode' => config('services.devboard.graph_import_mode', 'neo4j'),
                'neo4j_uri' => config('services.neo4j.uri'),
                'queue_connection' => config('queue.default'),
            ],
            'dashboard' => [
                'user' => $this->dashboardUser($request->user()),
                'navigation' => $this->dashboardNavigation($request->user(), $project?->id),
            ],
        ]);
    }

    private function canViewSystem(Request $request): bool
    {
        $user = $request->user();

        return $this->userHasRole($user, 'Sysadmin') || $this->userHasRole($user, 'Admin');
    }
}
