<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class KanbanController extends Controller
{
    use ChecksDashboardRoles;

    public function __invoke(Request $request): Response
    {
        $project = DB::table('projects')->orderBy('created_at')->first();
        $board = $project
            ? DB::table('kanban_boards')->where('project_id', $project->id)->where('is_default', true)->first()
            : null;

        $columns = $board
            ? DB::table('kanban_columns')
                ->where('board_id', $board->id)
                ->orderBy('position')
                ->get()
                ->map(fn (object $column) => [
                    'id' => $column->id,
                    'name' => $column->name,
                    'status_key' => $column->status_key,
                    'position' => $column->position,
                    'tasks' => $this->tasksForColumn($column->id),
                ])
            : collect();

        $recentRuns = DB::table('runs')
            ->leftJoin('repositories', 'repositories.id', '=', 'runs.repository_id')
            ->select([
                'runs.id',
                'runs.status',
                'runs.risk_level',
                'runs.branch',
                'runs.created_at',
                'repositories.name as repository_name',
            ])
            ->orderByDesc('runs.created_at')
            ->limit(5)
            ->get()
            ->map(fn (object $run) => [
                'id' => $run->id,
                'status' => $run->status,
                'risk_level' => $run->risk_level,
                'branch' => $run->branch,
                'repository_name' => $run->repository_name,
                'source_label' => 'local_plugin_snapshot',
                'created_at' => $run->created_at,
            ]);

        return Inertia::render('Kanban/Index', [
            'project' => $project,
            'columns' => $columns,
            'recentRuns' => $recentRuns,
            'dashboard' => [
                'user' => $this->dashboardUser($request->user()),
                'navigation' => $this->dashboardNavigation($request->user(), $project?->id),
            ],
            'health' => [
                'repositories' => $project ? DB::table('repositories')->where('project_id', $project->id)->count() : 0,
                'repositories_total' => $project ? DB::table('repositories')->where('project_id', $project->id)->count() : 0,
                'initialized' => $project ? DB::table('genesis_imports')->where('project_id', $project->id)->where('status', 'active')->distinct('repository_id')->count('repository_id') : 0,
                'staleWikiPages' => $project ? DB::table('wiki_pages')->where('project_id', $project->id)->whereIn('source_status', ['stale', 'conflict_with_code'])->count() : 0,
                'needsGenesis' => $project
                    ? DB::table('repositories')->where('project_id', $project->id)->count()
                        - DB::table('genesis_imports')->where('project_id', $project->id)->where('status', 'active')->distinct('repository_id')->count('repository_id')
                    : 0,
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tasksForColumn(string $columnId): array
    {
        return DB::table('tasks')
            ->leftJoin('users as owners', 'owners.id', '=', 'tasks.owner_user_id')
            ->where('tasks.status_column_id', $columnId)
            ->select([
                'tasks.id',
                'tasks.title',
                'tasks.priority',
                'tasks.risk_level',
                'tasks.description',
                'owners.name as owner_name',
            ])
            ->orderByDesc('tasks.updated_at')
            ->get()
            ->map(function (object $task) {
                $latestRun = DB::table('runs')
                    ->leftJoin('repositories', 'repositories.id', '=', 'runs.repository_id')
                    ->where('runs.task_id', $task->id)
                    ->select([
                        'runs.id',
                        'runs.status',
                        'repositories.name as repository_name',
                    ])
                    ->orderByDesc('runs.created_at')
                    ->first();

                $wikiStatus = DB::table('wiki_pages')
                    ->whereIn('source_status', ['stale', 'conflict_with_code'])
                    ->exists()
                    ? 'needs_review'
                    : 'current';
                $agentWork = $this->taskAgentWork((string) $task->id);

                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'href' => "/tasks/{$task->id}",
                    'owner' => $task->owner_name ?? 'Unassigned',
                    'priority' => $task->priority,
                    'risk_level' => $task->risk_level,
                    'repository_badges' => $latestRun?->repository_name ? [$latestRun->repository_name] : [],
                    'linked_run' => $latestRun ? [
                        'id' => $latestRun->id,
                        'status' => $latestRun->status,
                        'href' => "/runs/{$latestRun->id}",
                    ] : null,
                    'wiki_source_status' => $wikiStatus,
                    'agent_work' => $agentWork,
                    'blocked' => $task->risk_level === 'high',
                ];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function taskAgentWork(string $taskId): array
    {
        $items = DB::table('agent_work_items')
            ->where('task_id', $taskId)
            ->whereNull('archived_at')
            ->orderByDesc('created_at')
            ->get();

        if ($items->isEmpty()) {
            return [
                'status' => 'not_assigned',
                'label' => 'Not assigned',
                'count' => 0,
                'by_status' => [],
                'latest_work_item_id' => null,
                'latest_status' => null,
                'assigned_agent_key' => null,
                'next_step' => 'Assign local agent when the task is ready for agent work.',
            ];
        }

        $byStatus = [];
        foreach ($items as $item) {
            $status = (string) $item->status;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
        }

        $latest = $items->first();
        $status = $this->agentWorkAggregateStatus($byStatus, (string) $latest->status);

        return [
            'status' => $status,
            'label' => $this->agentWorkStatusLabel($status),
            'count' => $items->count(),
            'by_status' => $byStatus,
            'latest_work_item_id' => (string) $latest->id,
            'latest_status' => (string) $latest->status,
            'assigned_agent_key' => (string) $latest->assigned_agent_key,
            'next_step' => $this->agentWorkNextStep($status),
        ];
    }

    /**
     * @param  array<string, int>  $byStatus
     */
    private function agentWorkAggregateStatus(array $byStatus, string $fallback): string
    {
        foreach (['failed', 'claimed', 'running', 'queued', 'completed', 'canceled'] as $status) {
            if (($byStatus[$status] ?? 0) > 0) {
                return $status;
            }
        }

        return $fallback !== '' ? $fallback : 'unknown';
    }

    private function agentWorkStatusLabel(string $status): string
    {
        return match ($status) {
            'queued' => 'Queued for local agent',
            'claimed', 'running' => 'Local agent running',
            'completed' => 'Agent work completed',
            'failed' => 'Agent work failed',
            'canceled' => 'Agent work canceled',
            default => 'Agent work '.$status,
        };
    }

    private function agentWorkNextStep(string $status): string
    {
        return match ($status) {
            'queued' => 'Run the local Hades task worker to claim this task.',
            'claimed', 'running' => 'Wait for heartbeat or inspect the active local worker.',
            'completed' => 'Review the agent result and linked memory/evidence.',
            'failed' => 'Open the work item events and retry after fixing the failure.',
            'canceled' => 'Reassign the task if agent work is still needed.',
            default => 'Assign local agent when the task is ready for agent work.',
        };
    }

}
