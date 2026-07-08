<?php

namespace App\Http\Controllers\Dashboard\Api;

use App\Dashboard\DashboardApiReader;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use App\Projects\ProjectLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class DashboardResourceController extends Controller
{
    use ChecksDashboardRoles;

    public function overview(Request $request, DashboardApiReader $reader): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->overview());
    }

    public function kanban(Request $request, DashboardApiReader $reader): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->kanban());
    }

    public function projectKanban(Request $request, DashboardApiReader $reader, string $project): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->kanban($project));
    }

    public function storeProjectTask(Request $request, DashboardApiReader $reader, ProjectLifecycleService $lifecycle, string $project): JsonResponse
    {
        $this->abortUnlessDashboardMutator($request);

        if ($error = $lifecycle->assertProjectActiveForDashboard($project)) {
            return $error;
        }

        $validated = $this->validateTaskPayload($request, $project, creating: true);
        $taskId = (string) Str::ulid();
        $now = now();

        DB::transaction(function () use ($validated, $project, $taskId, $request, $now): void {
            DB::table('tasks')->insert([
                'id' => $taskId,
                'project_id' => $project,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'acceptance_criteria' => json_encode($validated['acceptance_criteria'] ?? [], JSON_THROW_ON_ERROR),
                'status_column_id' => $this->defaultTaskColumnId($project),
                'priority' => $validated['priority'] ?? 'normal',
                'risk_level' => $validated['risk'] ?? 'low',
                'owner_user_id' => $validated['owner_user_id'] ?? null,
                'created_by_user_id' => $request->user()->id,
                'due_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->syncTaskRepositories($taskId, $project, $validated['repository_ids'] ?? []);

            if (($validated['assign_to_local_agent'] ?? false) === true) {
                $this->queueLocalAgentWorkForTask($taskId, $project, $request->user()->id);
            }
        });

        return response()->json($reader->task($taskId), 201);
    }

    public function updateTask(Request $request, DashboardApiReader $reader, ProjectLifecycleService $lifecycle, string $task): JsonResponse
    {
        $this->abortUnlessDashboardMutator($request);

        if ($error = $lifecycle->assertTaskProjectActive($task)) {
            return $error;
        }

        $row = DB::table('tasks')->where('id', $task)->first();
        abort_unless($row, 404);

        $validated = $this->validateTaskPayload($request, (string) $row->project_id, creating: false);
        $updates = [];

        if (array_key_exists('column', $validated) && $validated['column'] !== null) {
            $columnId = $this->taskColumnIdForProject((string) $row->project_id, $validated['column']);
            abort_unless($columnId, 422, 'Unknown task column.');

            $updates['status_column_id'] = $columnId;
        }

        foreach (['title', 'description', 'priority', 'owner_user_id'] as $field) {
            if (array_key_exists($field, $validated)) {
                $updates[$field] = $validated[$field];
            }
        }

        if (array_key_exists('risk', $validated)) {
            $updates['risk_level'] = $validated['risk'];
        }

        if (array_key_exists('acceptance_criteria', $validated)) {
            $updates['acceptance_criteria'] = json_encode($validated['acceptance_criteria'], JSON_THROW_ON_ERROR);
        }

        DB::transaction(function () use ($updates, $validated, $task, $row, $request): void {
            if ($updates !== [] || array_key_exists('repository_ids', $validated)) {
                DB::table('tasks')->where('id', $task)->update([
                    ...$updates,
                    'updated_at' => now(),
                ]);
            }

            if (array_key_exists('repository_ids', $validated)) {
                $this->syncTaskRepositories($task, (string) $row->project_id, $validated['repository_ids']);
            }

            if (($validated['assign_to_local_agent'] ?? false) === true) {
                $this->queueLocalAgentWorkForTask($task, (string) $row->project_id, $request->user()->id);
            }
        });

        return response()->json($reader->task($task));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTaskPayload(Request $request, string $projectId, bool $creating): array
    {
        $repositoryRule = Rule::exists('repositories', 'id')
            ->where(fn ($query) => $query->where('project_id', $projectId));

        return $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'min:3', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'column' => ['sometimes', 'nullable', 'string', Rule::in(['backlog', 'ready', 'in_progress', 'blocked', 'review', 'done'])],
            'priority' => ['sometimes', 'string', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'risk' => ['sometimes', 'string', Rule::in(['low', 'medium', 'high', 'critical'])],
            'owner_user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
            'repository_ids' => ['sometimes', 'array'],
            'repository_ids.*' => ['string', $repositoryRule],
            'acceptance_criteria' => ['sometimes', 'array', 'max:20'],
            'acceptance_criteria.*' => ['string', 'min:3', 'max:500'],
            'assign_to_local_agent' => ['sometimes', 'boolean'],
        ]);
    }

    private function defaultTaskColumnId(string $projectId): string
    {
        $columnId = DB::table('kanban_columns')
            ->join('kanban_boards', 'kanban_boards.id', '=', 'kanban_columns.board_id')
            ->where('kanban_boards.project_id', $projectId)
            ->where('kanban_boards.is_default', true)
            ->where('kanban_columns.status_key', 'backlog')
            ->value('kanban_columns.id');

        abort_unless($columnId, 422, 'Project kanban board has no backlog column.');

        return (string) $columnId;
    }

    private function taskColumnIdForProject(string $projectId, string $statusKey): ?string
    {
        $columnId = DB::table('kanban_columns')
            ->join('kanban_boards', 'kanban_boards.id', '=', 'kanban_columns.board_id')
            ->where('kanban_boards.project_id', $projectId)
            ->where('kanban_boards.is_default', true)
            ->where('kanban_columns.status_key', $statusKey)
            ->value('kanban_columns.id');

        return $columnId ? (string) $columnId : null;
    }

    /**
     * @param  list<string>  $repositoryIds
     */
    private function syncTaskRepositories(string $taskId, string $projectId, array $repositoryIds): void
    {
        DB::table('repository_task')->where('task_id', $taskId)->delete();

        if ($repositoryIds === []) {
            return;
        }

        $validRepositoryIds = DB::table('repositories')
            ->where('project_id', $projectId)
            ->whereIn('id', $repositoryIds)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        $now = now();

        foreach (array_values(array_unique($validRepositoryIds)) as $repositoryId) {
            DB::table('repository_task')->insert([
                'id' => (string) Str::ulid(),
                'task_id' => $taskId,
                'repository_id' => $repositoryId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function queueLocalAgentWorkForTask(string $taskId, string $projectId, int $userId): void
    {
        $hasActiveWorkItem = DB::table('agent_work_items')
            ->where('task_id', $taskId)
            ->where('assigned_agent_key', 'local_agent')
            ->whereIn('status', ['draft', 'queued', 'claimed', 'running'])
            ->exists();

        if ($hasActiveWorkItem) {
            return;
        }

        $task = DB::table('tasks')->where('id', $taskId)->first();

        if (! $task) {
            return;
        }

        $repositoryId = DB::table('repository_task')
            ->where('task_id', $taskId)
            ->orderBy('created_at')
            ->value('repository_id');
        $acceptanceCriteria = json_decode((string) $task->acceptance_criteria, true, 512, JSON_THROW_ON_ERROR);
        $workItemId = (string) Str::ulid();
        $now = now();
        $payload = [
            'schema' => 'hades.kanban_task_work.v1',
            'source' => 'dashboard_kanban',
            'task_id' => $taskId,
            'project_id' => $projectId,
            'repository_id' => $repositoryId,
            'title' => (string) $task->title,
            'description' => $task->description,
            'acceptance_criteria' => is_array($acceptanceCriteria) ? $acceptanceCriteria : [],
            'priority' => (string) $task->priority,
            'risk' => (string) $task->risk_level,
            'memory_required' => true,
            'created_from' => 'kanban_task',
        ];

        DB::table('agent_work_items')->insert([
            'id' => $workItemId,
            'project_id' => $projectId,
            'repository_id' => $repositoryId,
            'task_id' => $taskId,
            'requested_by_user_id' => $userId,
            'assigned_agent_key' => 'local_agent',
            'status' => 'queued',
            'priority' => (string) $task->priority,
            'title' => (string) $task->title,
            'prompt' => $this->localAgentPromptForTask($task, $payload['acceptance_criteria']),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'requires_memory_entry' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('agent_work_item_events')->insert([
            'id' => (string) Str::ulid(),
            'agent_work_item_id' => $workItemId,
            'actor_user_id' => $userId,
            'actor_device_id' => null,
            'event_type' => 'queued_from_kanban_task',
            'message' => 'Dashboard task queued for the local Hades agent.',
            'payload' => json_encode(['task_id' => $taskId, 'schema' => 'hades.kanban_task_work.v1'], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  list<string>  $acceptanceCriteria
     */
    private function localAgentPromptForTask(object $task, array $acceptanceCriteria): string
    {
        $lines = [
            'Work on this backend Kanban task using shared Hades project memory before making claims.',
            '',
            'Task: '.(string) $task->title,
        ];

        if ($task->description) {
            $lines[] = '';
            $lines[] = 'Description:';
            $lines[] = (string) $task->description;
        }

        if ($acceptanceCriteria !== []) {
            $lines[] = '';
            $lines[] = 'Acceptance criteria:';
            foreach ($acceptanceCriteria as $criterion) {
                $lines[] = '- '.(string) $criterion;
            }
        }

        $lines[] = '';
        $lines[] = 'Return a concise implementation/diagnosis summary and write a memory entry with evidence references when useful.';

        return implode(PHP_EOL, $lines);
    }

    public function task(Request $request, DashboardApiReader $reader, string $task): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->task($task));
    }

    public function projects(Request $request, DashboardApiReader $reader): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        $status = (string) $request->query('status', ProjectLifecycleService::ACTIVE);
        abort_unless(in_array($status, [
            ProjectLifecycleService::ACTIVE,
            ProjectLifecycleService::ARCHIVED,
            ProjectLifecycleService::DELETED,
        ], true), 422, 'Unknown project status filter.');

        if ($status === ProjectLifecycleService::DELETED) {
            abort_unless(
                $this->userHasRole($request->user(), 'Admin') || $this->userHasRole($request->user(), 'PM'),
                403,
            );
        }

        return response()->json($reader->projects($status));
    }

    public function createProject(Request $request, DashboardApiReader $reader): JsonResponse
    {
        $this->abortUnlessProjectMutator($request);

        $payload = $this->validatedProjectPayload($request);
        $now = now();
        $projectId = (string) Str::ulid();
        $boardId = (string) Str::ulid();

        DB::transaction(function () use ($payload, $projectId, $boardId, $request, $now): void {
            DB::table('projects')->insert([
                'id' => $projectId,
                'name' => $payload['name'],
                'slug' => $payload['slug'],
                'description' => $payload['description'],
                'status' => 'active',
                'default_code_exposure_policy' => 'full_code_artifacts',
                'created_by_user_id' => $request->user()->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('kanban_boards')->insert([
                'id' => $boardId,
                'project_id' => $projectId,
                'name' => 'Default Board',
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($this->defaultKanbanColumns() as $position => $column) {
                DB::table('kanban_columns')->insert([
                    'id' => (string) Str::ulid(),
                    'board_id' => $boardId,
                    'name' => $column['name'],
                    'position' => $position + 1,
                    'status_key' => $column['status_key'],
                    'wip_limit' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        return response()->json($reader->project($projectId), 201);
    }

    public function project(Request $request, DashboardApiReader $reader, string $project): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->project($project));
    }

    public function updateProject(Request $request, DashboardApiReader $reader, ProjectLifecycleService $lifecycle, string $project): JsonResponse
    {
        $this->abortUnlessProjectMutator($request);

        $existing = DB::table('projects')->where('id', $project)->first();
        abort_unless($existing, 404);

        if ($error = $lifecycle->assertProjectActiveForDashboard($project)) {
            return $error;
        }

        $payload = $this->validatedProjectPayload($request, $project, $existing);

        DB::table('projects')->where('id', $project)->update([
            'name' => $payload['name'],
            'slug' => $payload['slug'],
            'description' => $payload['description'],
            'updated_at' => now(),
        ]);

        return response()->json($reader->project($project));
    }

    public function runs(Request $request, DashboardApiReader $reader): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->runs());
    }

    public function projectRuns(Request $request, DashboardApiReader $reader, string $project): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->runs($project));
    }

    public function run(Request $request, DashboardApiReader $reader, string $run): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->run($run));
    }

    public function retryImport(Request $request, DashboardApiReader $reader, ProjectLifecycleService $lifecycle, string $run): JsonResponse
    {
        abort_unless($this->userHasRole($request->user(), 'Developer') || $this->userHasRole($request->user(), 'Admin'), 403);

        $runRow = DB::table('runs')->where('id', $run)->first();
        abort_unless($runRow, 404);

        if ($error = $lifecycle->assertRunProjectActive($run)) {
            return $error;
        }

        $target = DB::table('genesis_imports')->where('run_id', $run)->first()
            ?? DB::table('delta_syncs')->where('run_id', $run)->first();
        abort_unless($target, 409, 'Run has no retryable import.');

        $table = DB::table('genesis_imports')->where('run_id', $run)->exists() ? 'genesis_imports' : 'delta_syncs';

        DB::table($table)->where('id', $target->id)->update([
            'status' => 'active',
            'updated_at' => now(),
        ]);

        DB::table('run_events')->insert([
            'id' => (string) Str::ulid(),
            'run_id' => $run,
            'event_type' => 'graph.import_retried',
            'severity' => 'info',
            'message' => 'Graph import retried from dashboard API.',
            'payload' => json_encode([
                'target_type' => $table,
                'target_id' => $target->id,
                'retried_by_user_id' => $request->user()->id,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return response()->json($reader->run($run));
    }

    public function review(Request $request, DashboardApiReader $reader, ProjectLifecycleService $lifecycle, string $run): JsonResponse
    {
        abort_unless(
            $this->userHasRole($request->user(), 'PM')
            || $this->userHasRole($request->user(), 'Developer')
            || $this->userHasRole($request->user(), 'Sysadmin')
            || $this->userHasRole($request->user(), 'Admin'),
            403,
        );

        abort_unless(DB::table('runs')->where('id', $run)->exists(), 404);

        if ($error = $lifecycle->assertRunProjectActive($run)) {
            return $error;
        }

        if (! DB::table('run_events')->where('run_id', $run)->where('event_type', 'run.reviewed')->exists()) {
            DB::table('run_events')->insert([
                'id' => (string) Str::ulid(),
                'run_id' => $run,
                'event_type' => 'run.reviewed',
                'severity' => 'info',
                'message' => 'Run reviewed from dashboard API.',
                'payload' => json_encode([
                    'reviewed_by_user_id' => $request->user()->id,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        }

        return response()->json($reader->run($run));
    }

    public function wiki(Request $request, DashboardApiReader $reader): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->wiki());
    }

    public function projectWiki(Request $request, DashboardApiReader $reader, string $project): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->wiki($project));
    }

    public function wikiPage(Request $request, DashboardApiReader $reader, string $page): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->wikiPage($page));
    }

    public function projectWikiPage(Request $request, DashboardApiReader $reader, string $project, string $page): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->wikiPage($page, $project));
    }

    public function graph(Request $request, DashboardApiReader $reader): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->graph(
            snapshotId: $request->query('snapshot_id') ? (string) $request->query('snapshot_id') : null,
            runId: $request->query('run_id') ? (string) $request->query('run_id') : null,
        ));
    }

    public function projectGraph(Request $request, DashboardApiReader $reader, string $project): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->graph(
            projectId: $project,
            snapshotId: $request->query('snapshot_id') ? (string) $request->query('snapshot_id') : null,
            runId: $request->query('run_id') ? (string) $request->query('run_id') : null,
        ));
    }

    public function artifacts(Request $request, DashboardApiReader $reader): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->artifacts());
    }

    public function projectArtifacts(Request $request, DashboardApiReader $reader, string $project): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->artifacts($project));
    }

    public function downloadArtifact(Request $request, DashboardApiReader $reader, string $run, string $artifact): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->artifactDownload($run, $artifact));
    }

    private function abortUnlessDashboardReader(Request $request): void
    {
        abort_unless(
            $this->userHasRole($request->user(), 'PM')
            || $this->userHasRole($request->user(), 'Developer')
            || $this->userHasRole($request->user(), 'Sysadmin')
            || $this->userHasRole($request->user(), 'Admin'),
            403,
        );
    }

    private function abortUnlessDashboardMutator(Request $request): void
    {
        abort_unless(
            $this->userHasRole($request->user(), 'PM')
            || $this->userHasRole($request->user(), 'Developer')
            || $this->userHasRole($request->user(), 'Admin'),
            403,
        );
    }

    private function abortUnlessProjectMutator(Request $request): void
    {
        abort_unless($this->userHasRole($request->user(), 'Admin'), 403);
    }

    /**
     * @return array{name: string, slug: string, description: ?string}
     */
    private function validatedProjectPayload(Request $request, ?string $projectId = null, ?object $existing = null): array
    {
        $validated = $request->validate([
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:255'],
            'key' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $name = (string) ($validated['name'] ?? $existing?->name);
        $slug = (string) ($validated['key'] ?? $existing?->slug ?? Str::slug($name));

        if ($slug === '') {
            throw ValidationException::withMessages([
                'key' => 'Project key must contain at least one letter or number.',
            ]);
        }

        $exists = DB::table('projects')
            ->where('slug', $slug)
            ->when($projectId !== null, fn ($query) => $query->where('id', '!=', $projectId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'key' => 'Project key is already in use.',
            ]);
        }

        return [
            'name' => $name,
            'slug' => $slug,
            'description' => array_key_exists('description', $validated)
                ? (isset($validated['description']) ? (string) $validated['description'] : null)
                : (isset($existing?->description) ? (string) $existing->description : null),
        ];
    }

    /**
     * @return list<array{name: string, status_key: string}>
     */
    private function defaultKanbanColumns(): array
    {
        return [
            ['name' => 'Backlog', 'status_key' => 'backlog'],
            ['name' => 'Ready', 'status_key' => 'ready'],
            ['name' => 'In Progress', 'status_key' => 'in_progress'],
            ['name' => 'Blocked', 'status_key' => 'blocked'],
            ['name' => 'Review', 'status_key' => 'review'],
            ['name' => 'Done', 'status_key' => 'done'],
        ];
    }
}
