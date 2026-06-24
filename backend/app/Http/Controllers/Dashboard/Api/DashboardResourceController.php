<?php

namespace App\Http\Controllers\Dashboard\Api;

use App\Dashboard\DashboardApiReader;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    public function updateTask(Request $request, DashboardApiReader $reader, string $task): JsonResponse
    {
        $this->abortUnlessDashboardMutator($request);

        $validated = $request->validate([
            'column' => ['nullable', 'string', 'in:backlog,ready,in_progress,blocked,review,done'],
        ]);

        if (isset($validated['column'])) {
            $columnId = DB::table('kanban_columns')
                ->where('status_key', $validated['column'])
                ->value('id');
            abort_unless($columnId, 422, 'Unknown task column.');

            DB::table('tasks')->where('id', $task)->update([
                'status_column_id' => $columnId,
                'updated_at' => now(),
            ]);
        }

        return response()->json($reader->task($task));
    }

    public function task(Request $request, DashboardApiReader $reader, string $task): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->task($task));
    }

    public function projects(Request $request, DashboardApiReader $reader): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->projects());
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

    public function updateProject(Request $request, DashboardApiReader $reader, string $project): JsonResponse
    {
        $this->abortUnlessProjectMutator($request);

        $existing = DB::table('projects')->where('id', $project)->first();
        abort_unless($existing, 404);

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

    public function retryImport(Request $request, DashboardApiReader $reader, string $run): JsonResponse
    {
        abort_unless($this->userHasRole($request->user(), 'Developer') || $this->userHasRole($request->user(), 'Admin'), 403);

        $runRow = DB::table('runs')->where('id', $run)->first();
        abort_unless($runRow, 404);

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

    public function review(Request $request, DashboardApiReader $reader, string $run): JsonResponse
    {
        abort_unless(
            $this->userHasRole($request->user(), 'PM')
            || $this->userHasRole($request->user(), 'Developer')
            || $this->userHasRole($request->user(), 'Sysadmin')
            || $this->userHasRole($request->user(), 'Admin'),
            403,
        );

        abort_unless(DB::table('runs')->where('id', $run)->exists(), 404);

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

    public function graph(Request $request, DashboardApiReader $reader): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->graph(
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
