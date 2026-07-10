<?php

namespace App\Http\Controllers\Dashboard\Api;

use App\Dashboard\DashboardApiReader;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use App\Projects\ProjectLifecycleService;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class DashboardMemoryController extends Controller
{
    use ChecksDashboardRoles;

    private const DASHBOARD_MANUAL_KINDS = [
        'decision',
        'implementation',
        'clarification',
        'risk',
        'verification',
        'handoff',
        'incident',
        'logbook',
    ];

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
            'kind' => ['required', 'string', Rule::in(self::DASHBOARD_MANUAL_KINDS)],
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
            'agent_key' => null,
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

    public function update(
        Request $request,
        DashboardApiReader $reader,
        ProjectLifecycleService $lifecycle,
        string $project,
        string $memory,
    ): JsonResponse {
        $this->abortUnlessDashboardMutator($request);

        if ($error = $lifecycle->assertProjectActiveForDashboard($project)) {
            return $error;
        }

        $entry = DB::table('project_memory_entries')
            ->where('project_id', $project)
            ->where('id', $memory)
            ->first();

        abort_unless($entry, Response::HTTP_NOT_FOUND);

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
            'kind' => ['required', 'string', Rule::in($this->editableKindsFor($entry))],
            'completeness' => ['required', 'string', Rule::in(['complete', 'incomplete'])],
            'summary' => ['required', 'string', 'min:8', 'max:240'],
            'payload' => ['required', 'array'],
        ]);

        DB::transaction(function () use ($request, $project, $memory, $entry, $validated): void {
            DB::table('project_memory_entries')->where('id', $memory)->update([
                'repository_id' => $validated['repository_id'] ?? null,
                'task_id' => $validated['task_id'] ?? null,
                'run_id' => $validated['run_id'] ?? null,
                'kind' => $validated['kind'],
                'completeness' => $validated['completeness'],
                'summary' => $validated['summary'],
                'payload' => json_encode($validated['payload'], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

            app(AuditLogger::class)->record('project_memory.updated', 'project_memory_entry', $memory, [
                'project_id' => $project,
                'previous_summary' => (string) $entry->summary,
                'summary' => $validated['summary'],
                'previous_kind' => (string) $entry->kind,
                'kind' => $validated['kind'],
                'source' => (string) $entry->source,
            ], [
                'type' => 'user',
                'user_id' => $request->user()->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        });

        return response()->json($reader->projectMemoryEntry($memory));
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

            app(AuditLogger::class)->record('project_memory.deleted', 'project_memory_entry', $memory, [
                'project_id' => $project,
                'summary' => (string) $entry->summary,
                'kind' => (string) $entry->kind,
                'source' => (string) $entry->source,
            ], [
                'type' => 'user',
                'user_id' => $request->user()->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
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

    /**
     * @return list<string>
     */
    private function editableKindsFor(object $entry): array
    {
        if ((string) $entry->kind === 'agent_note'
            || in_array((string) $entry->source, ['server_agent', 'hades_agent', 'local_agent'], true)
            || $entry->agent_key !== null) {
            return [...self::DASHBOARD_MANUAL_KINDS, 'agent_note'];
        }

        return self::DASHBOARD_MANUAL_KINDS;
    }
}
