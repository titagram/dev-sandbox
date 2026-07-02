<?php

namespace App\Http\Controllers\Dashboard\Api;

use App\Dashboard\DashboardApiReader;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use App\Models\User;
use App\Projects\ProjectLifecycleService;
use App\Services\ServerAgentWorkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class DashboardAgentWorkController extends Controller
{
    use ChecksDashboardRoles;

    public function index(Request $request, DashboardApiReader $reader, string $project): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->projectAgentWork($project));
    }

    public function show(Request $request, DashboardApiReader $reader, string $project, string $workItem): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);

        return response()->json($reader->agentWorkDetail($project, $workItem));
    }

    public function store(
        Request $request,
        DashboardApiReader $reader,
        ProjectLifecycleService $lifecycle,
        ServerAgentWorkService $serverAgentWork,
        string $project,
    ): JsonResponse {
        $user = $this->abortUnlessDashboardMutator($request);

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
            'assigned_agent_key' => ['required', 'string', Rule::in(['socrates', 'platon', 'aristoteles', 'local_agent'])],
            'priority' => ['sometimes', 'string', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'title' => ['required', 'string', 'min:4', 'max:180'],
            'prompt' => ['required', 'string', 'min:8', 'max:8000'],
            'payload' => ['sometimes', 'array'],
            'requires_memory_entry' => ['sometimes', 'boolean'],
        ]);

        $workItemId = (string) Str::ulid();
        $now = now();

        DB::transaction(function () use ($project, $validated, $user, $workItemId, $now): void {
            DB::table('agent_work_items')->insert([
                'id' => $workItemId,
                'project_id' => $project,
                'repository_id' => $validated['repository_id'] ?? null,
                'task_id' => $validated['task_id'] ?? null,
                'requested_by_user_id' => $user->id,
                'assigned_agent_key' => $validated['assigned_agent_key'],
                'status' => 'queued',
                'priority' => $validated['priority'] ?? 'normal',
                'title' => $validated['title'],
                'prompt' => $validated['prompt'],
                'payload' => json_encode($validated['payload'] ?? [], JSON_THROW_ON_ERROR),
                'requires_memory_entry' => $validated['requires_memory_entry'] ?? true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->recordEvent(
                workItemId: $workItemId,
                eventType: 'queued',
                userId: $user->id,
                deviceId: null,
                message: 'Dashboard user queued work for an agent.',
                payload: [],
                now: $now,
            );
        });

        if ($serverAgentWork->shouldHandle((string) $validated['assigned_agent_key'])) {
            $serverAgentWork->process($workItemId);
        }

        return response()->json($reader->agentWorkItemById($workItemId), 201);
    }

    public function cancel(
        Request $request,
        DashboardApiReader $reader,
        ProjectLifecycleService $lifecycle,
        string $workItem,
    ): JsonResponse {
        $user = $this->abortUnlessDashboardMutator($request);
        $validated = $request->validate([
            'message' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $item = DB::table('agent_work_items')->where('id', $workItem)->first();
        abort_unless($item, 404);

        if ($error = $lifecycle->assertProjectActiveForDashboard((string) $item->project_id)) {
            return $error;
        }

        abort_if(
            in_array((string) $item->status, ['completed', 'completed_with_incomplete_memory'], true),
            409,
            'Completed work cannot be canceled.',
        );

        abort_if(
            in_array((string) $item->status, ['claimed', 'running'], true)
            || $item->claimed_by_device_id !== null
            || $item->claimed_at !== null,
            409,
            'Claimed or running work cannot be canceled.',
        );

        abort_unless(
            (string) $item->status === 'queued' && $item->heartbeat_at === null,
            409,
            'Work item is no longer cancelable.',
        );

        $now = now();

        DB::transaction(function () use ($validated, $user, $workItem, $now): void {
            $updated = DB::table('agent_work_items')
                ->where('id', $workItem)
                ->where('status', 'queued')
                ->whereNull('claimed_by_device_id')
                ->whereNull('claimed_at')
                ->whereNull('heartbeat_at')
                ->update([
                    'status' => 'canceled',
                    'canceled_at' => $now,
                    'updated_at' => $now,
                ]);

            abort_if($updated === 0, 409, 'Work item is no longer cancelable.');

            $this->recordEvent(
                workItemId: $workItem,
                eventType: 'canceled',
                userId: $user->id,
                deviceId: null,
                message: $validated['message'] ?? null,
                payload: [],
                now: $now,
            );
        });

        return response()->json($reader->agentWorkItemById($workItem));
    }

    private function abortUnlessDashboardReader(Request $request): User
    {
        $user = $this->activeDashboardUser($request);

        abort_unless(
            $this->userHasRole($user, 'PM')
            || $this->userHasRole($user, 'Developer')
            || $this->userHasRole($user, 'Sysadmin')
            || $this->userHasRole($user, 'Admin'),
            403,
        );

        return $user;
    }

    private function abortUnlessDashboardMutator(Request $request): User
    {
        $user = $this->activeDashboardUser($request);

        abort_unless(
            $this->userHasRole($user, 'PM')
            || $this->userHasRole($user, 'Developer')
            || $this->userHasRole($user, 'Admin'),
            403,
        );

        return $user;
    }

    private function activeDashboardUser(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->status === 'active', 403);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordEvent(
        string $workItemId,
        string $eventType,
        ?int $userId,
        ?string $deviceId,
        ?string $message,
        array $payload,
        mixed $now,
    ): void {
        DB::table('agent_work_item_events')->insert([
            'id' => (string) Str::ulid(),
            'agent_work_item_id' => $workItemId,
            'actor_user_id' => $userId,
            'actor_device_id' => $deviceId,
            'event_type' => $eventType,
            'message' => $message,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
