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

final class DashboardMemoryController extends Controller
{
    use ChecksDashboardRoles;

    public function index(Request $request, DashboardApiReader $reader, string $project): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->projectMemory($project));
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
            'summary' => ['required', 'string', 'min:1', 'max:240'],
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
            'source' => 'dashboard_user',
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
