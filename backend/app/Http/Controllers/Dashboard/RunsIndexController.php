<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RunsIndexController extends Controller
{
    use ChecksDashboardRoles;

    public function __invoke(Request $request): Response
    {
        $project = DB::table('projects')->orderBy('created_at')->first();

        $runs = DB::table('runs')
            ->leftJoin('repositories', 'repositories.id', '=', 'runs.repository_id')
            ->leftJoin('tasks', 'tasks.id', '=', 'runs.task_id')
            ->select([
                'runs.id',
                'runs.project_id',
                'runs.repository_id',
                'runs.task_id',
                'runs.status',
                'runs.risk_level',
                'runs.branch',
                'runs.summary',
                'runs.created_at',
                'repositories.name as repository_name',
                'tasks.title as task_title',
            ])
            ->orderByDesc('runs.created_at')
            ->limit(50)
            ->get()
            ->map(fn (object $run): array => [
                'id' => $run->id,
                'status' => $run->status,
                'risk_level' => $run->risk_level,
                'branch' => $run->branch,
                'summary' => $run->summary,
                'repository_name' => $run->repository_name,
                'kind' => $this->runKind($run->id),
                'source_label' => 'local_plugin_snapshot',
                'detail_href' => "/runs/{$run->id}",
                'graph_href' => DB::table('snapshots')->where('created_by_run_id', $run->id)->whereNotNull('graph_snapshot_artifact_id')->exists()
                    ? "/graph?run={$run->id}"
                    : null,
                'task' => $run->task_id ? [
                    'id' => $run->task_id,
                    'title' => $run->task_title,
                    'href' => "/tasks/{$run->task_id}",
                ] : null,
                'created_at' => $run->created_at,
            ]);

        return Inertia::render('Runs/Index', [
            'project' => $project,
            'runs' => $runs,
            'dashboard' => [
                'user' => $this->dashboardUser($request->user()),
                'navigation' => $this->dashboardNavigation($request->user(), $project?->id),
            ],
        ]);
    }

    private function runKind(string $runId): string
    {
        if (DB::table('delta_syncs')->where('run_id', $runId)->exists()) {
            return 'delta_sync';
        }

        if (DB::table('genesis_imports')->where('run_id', $runId)->exists()) {
            return 'genesis_import';
        }

        return 'run';
    }
}
