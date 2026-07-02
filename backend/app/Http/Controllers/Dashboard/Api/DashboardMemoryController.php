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
use Symfony\Component\HttpFoundation\Response;

final class DashboardMemoryController extends Controller
{
    use ChecksDashboardRoles;

    public function index(Request $request, DashboardApiReader $reader, string $project): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->projectMemory(
            $project,
            $request->query('domain') ? (string) $request->query('domain') : null,
            $request->query('q', $request->query('query')) ? (string) $request->query('q', $request->query('query')) : null,
        ));
    }

    public function store(
        Request $request,
        DashboardApiReader $reader,
        ProjectLifecycleService $lifecycle,
        string $project,
    ): JsonResponse {
        $this->abortUnlessDashboardMutator($request);

        if ($error = $lifecycle->assertProjectActiveForDashboard($project)) {
            return $error;
        }

        $validated = $request->validate([
            'repository_id' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists('repositories', 'id')->where(fn ($query) => $query->where('project_id', $project)),
            ],
            'task_id' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists('tasks', 'id')->where(fn ($query) => $query->where('project_id', $project)),
            ],
            'run_id' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists('runs', 'id')->where(fn ($query) => $query->where('project_id', $project)),
            ],
            'agent_key' => ['sometimes', 'nullable', 'string', Rule::in(['socrates', 'platon', 'aristoteles', 'local_agent'])],
            'kind' => ['required', 'string', Rule::in(['decision', 'implementation', 'clarification', 'risk', 'verification', 'handoff', 'incident', 'agent_note'])],
            'completeness' => ['sometimes', 'string', Rule::in(['complete', 'incomplete'])],
            'summary' => ['required', 'string', 'min:8', 'max:240'],
            'payload' => ['required', 'array'],
        ]);

        $memoryId = (string) Str::ulid();
        $now = now();

        DB::table('project_memory_entries')->insert([
            'id' => $memoryId,
            'project_id' => $project,
            'repository_id' => $validated['repository_id'] ?? null,
            'task_id' => $validated['task_id'] ?? null,
            'run_id' => $validated['run_id'] ?? null,
            'author_user_id' => $request->user()->id,
            'agent_key' => $validated['agent_key'] ?? null,
            'source' => 'user_inserted',
            'kind' => $validated['kind'],
            'completeness' => $validated['completeness'] ?? 'complete',
            'summary' => $validated['summary'],
            'payload' => json_encode($validated['payload'], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json($reader->projectMemoryEntry($memoryId), 201);
    }

    public function destroy(Request $request, ProjectLifecycleService $lifecycle, string $project, string $memory): Response|JsonResponse
    {
        $this->abortUnlessDashboardMutator($request);

        if ($error = $lifecycle->assertProjectActiveForDashboard($project)) {
            return $error;
        }

        $entry = DB::table('project_memory_entries')
            ->where('project_id', $project)
            ->where('id', $memory)
            ->first();

        abort_unless($entry, Response::HTTP_NOT_FOUND);

        DB::transaction(function () use ($request, $project, $memory, $entry): void {
            DB::table('project_memory_entries')->where('id', $memory)->delete();

            DB::table('audit_logs')->insert([
                'id' => (string) Str::ulid(),
                'actor_user_id' => $request->user()->id,
                'actor_device_id' => null,
                'actor_type' => 'user',
                'action' => 'project_memory.deleted',
                'target_type' => 'project_memory_entry',
                'target_id' => $memory,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'payload' => json_encode([
                    'project_id' => $project,
                    'summary' => (string) $entry->summary,
                    'kind' => (string) $entry->kind,
                    'source' => (string) $entry->source,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        });

        return response()->noContent();
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
}
