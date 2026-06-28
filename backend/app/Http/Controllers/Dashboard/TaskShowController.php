<?php

namespace App\Http\Controllers\Dashboard;

use App\Assistants\TaskClarifierService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TaskShowController extends Controller
{
    use ChecksDashboardRoles;

    public function __invoke(Request $request, TaskClarifierService $clarifier, string $task): Response
    {
        $taskRow = DB::table('tasks')
            ->leftJoin('users as owners', 'owners.id', '=', 'tasks.owner_user_id')
            ->leftJoin('kanban_columns', 'kanban_columns.id', '=', 'tasks.status_column_id')
            ->select([
                'tasks.id',
                'tasks.project_id',
                'tasks.title',
                'tasks.description',
                'tasks.priority',
                'tasks.risk_level',
                'tasks.due_at',
                'tasks.updated_at',
                'owners.name as owner_name',
                'kanban_columns.id as status_id',
                'kanban_columns.name as status_name',
                'kanban_columns.status_key',
            ])
            ->where('tasks.id', $task)
            ->firstOrFail();

        $latestRun = DB::table('runs')
            ->leftJoin('repositories', 'repositories.id', '=', 'runs.repository_id')
            ->select([
                'runs.id',
                'runs.status',
                'runs.risk_level',
                'runs.summary',
                'runs.created_at',
                'repositories.name as repository_name',
            ])
            ->where('runs.task_id', $taskRow->id)
            ->orderByDesc('runs.created_at')
            ->first();

        $staleWikiExists = DB::table('wiki_pages')
            ->where('project_id', $taskRow->project_id)
            ->whereIn('source_status', ['stale', 'conflict_with_code'])
            ->exists();

        return Inertia::render('Tasks/Show', [
            'task' => [
                'id' => $taskRow->id,
                'project_id' => $taskRow->project_id,
                'title' => $taskRow->title,
                'description' => $taskRow->description,
                'priority' => $taskRow->priority,
                'risk_level' => $taskRow->risk_level,
                'due_at' => $taskRow->due_at,
                'updated_at' => $taskRow->updated_at,
                'owner' => [
                    'name' => $taskRow->owner_name ?? 'Unassigned',
                ],
                'status' => [
                    'id' => $taskRow->status_id,
                    'name' => $taskRow->status_name,
                    'status_key' => $taskRow->status_key,
                ],
                'linked_run' => $latestRun ? [
                    'id' => $latestRun->id,
                    'status' => $latestRun->status,
                    'risk_level' => $latestRun->risk_level,
                    'summary' => $latestRun->summary,
                    'repository_name' => $latestRun->repository_name,
                    'created_at' => $latestRun->created_at,
                    'href' => "/runs/{$latestRun->id}",
                ] : null,
                'source_label' => 'local_plugin_snapshot',
                'wiki_source_status' => $staleWikiExists ? 'needs_review' : 'current',
                'blocked' => $taskRow->risk_level === 'high',
            ],
            'assistant' => [
                'clarify_href' => "/api/dashboard/tasks/{$taskRow->id}/assistant/clarify",
                'resolve_suggestion_href' => '/api/dashboard/assistant-suggestions',
                'can_clarify' => $this->userHasRole($request->user(), 'Admin') || $this->userHasRole($request->user(), 'PM'),
                'latest_suggestion' => $clarifier->latestSuggestionForTask((string) $taskRow->id),
            ],
            'dashboard' => [
                'user' => $this->dashboardUser($request->user()),
                'navigation' => $this->dashboardNavigation($request->user(), $taskRow->project_id),
            ],
        ]);
    }
}
