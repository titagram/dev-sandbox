<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Projects\ProjectLifecycleService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class AgentWorkItemController extends Controller
{
    private const ACTIVE_STATUSES = ['queued', 'claimed', 'running'];
    private const TERMINAL_STATUSES = ['completed', 'completed_with_incomplete_memory', 'failed', 'canceled'];
    private const MEMORY_KINDS = [
        'decision',
        'implementation',
        'clarification',
        'risk',
        'verification',
        'handoff',
        'incident',
        'agent_note',
    ];

    public function __construct(private readonly ProjectLifecycleService $lifecycle)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $device = $this->activeDevice($request);

        if ($device instanceof JsonResponse) {
            return $device;
        }

        $validated = $request->validate([
            'project_id' => ['sometimes', 'nullable', 'string'],
            'repository_id' => ['sometimes', 'nullable', 'string'],
        ]);

        $projectId = $validated['project_id'] ?? null;
        $repositoryId = $validated['repository_id'] ?? null;
        $repository = null;

        if ($projectId !== null && $error = $this->lifecycle->pluginProjectWriteGuard($projectId)) {
            return $error;
        }

        if ($repositoryId !== null) {
            $repository = DB::table('repositories')->where('id', $repositoryId)->first();
            abort_unless($repository, Response::HTTP_NOT_FOUND);

            if ($projectId !== null && (string) $repository->project_id !== $projectId) {
                abort(Response::HTTP_NOT_FOUND);
            }

            if ($error = $this->lifecycle->pluginProjectWriteGuard((string) $repository->project_id)) {
                return $error;
            }
        }

        $items = DB::table('agent_work_items')
            ->join('projects', 'projects.id', '=', 'agent_work_items.project_id')
            ->select('agent_work_items.*')
            ->where('projects.status', 'active')
            ->whereNull('projects.deleted_at')
            ->where('agent_work_items.assigned_agent_key', 'local_agent')
            ->whereIn('agent_work_items.status', self::ACTIVE_STATUSES)
            ->where(function ($query) use ($device): void {
                $query->whereNull('agent_work_items.claimed_by_device_id')
                    ->orWhere('agent_work_items.claimed_by_device_id', $device->id);
            })
            ->when($projectId !== null, fn ($query) => $query->where('agent_work_items.project_id', $projectId))
            ->when($repositoryId !== null, function ($query) use ($repositoryId, $repository): void {
                $query->where(function ($query) use ($repositoryId, $repository): void {
                    $query->where('agent_work_items.repository_id', $repositoryId)
                        ->orWhere(function ($query) use ($repository): void {
                            $query->whereNull('agent_work_items.repository_id')
                                ->where('agent_work_items.project_id', (string) $repository->project_id);
                        });
                });
            })
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 when 'low' then 3 else 4 end")
            ->orderBy('agent_work_items.created_at')
            ->limit(50)
            ->get()
            ->map(fn (object $item): array => $this->item($item))
            ->all();

        return response()->json([
            'protocol_version' => 'v1',
            'items' => $items,
        ]);
    }

    public function claim(Request $request, string $workItem): JsonResponse
    {
        $device = $this->activeDevice($request);

        if ($device instanceof JsonResponse) {
            return $device;
        }

        $validated = $request->validate([
            'local_workspace_id' => ['required', 'string', 'exists:local_workspaces,id'],
        ]);

        $workspace = DB::table('local_workspaces')
            ->join('repositories', 'repositories.id', '=', 'local_workspaces.repository_id')
            ->select('local_workspaces.*', 'repositories.project_id as workspace_project_id')
            ->where('local_workspaces.id', $validated['local_workspace_id'])
            ->where('local_workspaces.device_id', $device->id)
            ->first();

        if (! $workspace) {
            return $this->error('workspace_not_found', 'Local workspace is not registered for this device.', Response::HTTP_NOT_FOUND);
        }

        $leaseToken = Str::random(64);

        $item = DB::transaction(function () use ($workItem, $device, $workspace, $leaseToken): object {
            $item = $this->workItemOrFail($workItem);

            if ($error = $this->lifecycle->pluginProjectWriteGuard((string) $item->project_id)) {
                throw new HttpResponseException($error);
            }

            if ((string) $item->assigned_agent_key !== 'local_agent') {
                abort(Response::HTTP_NOT_FOUND);
            }

            if ($item->repository_id !== null && (string) $workspace->repository_id !== (string) $item->repository_id) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Local workspace does not match the work item repository.');
            }

            if ($item->repository_id === null && (string) $workspace->workspace_project_id !== (string) $item->project_id) {
                throw new HttpResponseException($this->error('workspace_project_mismatch', 'Local workspace project does not match the work item project.', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            if (in_array((string) $item->status, self::TERMINAL_STATUSES, true)) {
                abort(Response::HTTP_CONFLICT, 'Work item is already terminal.');
            }

            $now = now();

            if ((string) $item->status === 'queued') {
                $updated = DB::table('agent_work_items')
                    ->where('id', $workItem)
                    ->where('status', 'queued')
                    ->whereNull('claimed_by_device_id')
                    ->update([
                        'status' => 'claimed',
                        'claimed_by_device_id' => $device->id,
                        'claimed_at' => $now,
                        'heartbeat_at' => $now,
                        'updated_at' => $now,
                    ]);

                abort_if($updated === 0, Response::HTTP_CONFLICT, 'Work item could not be claimed.');
            } elseif (in_array((string) $item->status, ['claimed', 'running'], true) && (string) $item->claimed_by_device_id === (string) $device->id) {
                $updated = DB::table('agent_work_items')
                    ->where('id', $workItem)
                    ->where('claimed_by_device_id', $device->id)
                    ->whereIn('status', ['claimed', 'running'])
                    ->update([
                        'heartbeat_at' => $now,
                        'updated_at' => $now,
                    ]);

                if ($updated === 0) {
                    throw new HttpResponseException($this->error('work_item_claim_conflict', 'Work item could not be claimed.', Response::HTTP_CONFLICT));
                }
            } else {
                abort(Response::HTTP_CONFLICT, 'Work item is already claimed.');
            }

            $this->releaseActiveLeases($workItem, $now);
            $this->createLease($workItem, (string) $device->id, $leaseToken, $now);
            $this->recordEvent(
                workItemId: $workItem,
                eventType: 'claimed',
                userId: null,
                deviceId: (string) $device->id,
                message: 'Local agent claimed work item.',
                payload: ['local_workspace_id' => (string) $workspace->id],
                now: $now,
            );

            return $this->workItemOrFail($workItem);
        });

        return response()->json([
            'protocol_version' => 'v1',
            'item' => $this->item($item),
            'lease_token' => $leaseToken,
        ]);
    }

    public function heartbeat(Request $request, string $workItem): JsonResponse
    {
        $device = $this->activeDevice($request);

        if ($device instanceof JsonResponse) {
            return $device;
        }

        $validated = $request->validate([
            'lease_token' => ['required', 'string'],
        ]);

        $item = DB::transaction(function () use ($workItem, $device, $validated): object {
            $item = $this->workItemOrFail($workItem);
            $this->assertOwnedRunningItem($item, $device);
            $this->assertValidLease($workItem, (string) $device->id, $validated['lease_token']);

            if ($error = $this->lifecycle->pluginProjectWriteGuard((string) $item->project_id)) {
                throw new HttpResponseException($error);
            }

            $now = now();
            $updated = DB::table('agent_work_items')
                ->where('id', $workItem)
                ->where('claimed_by_device_id', $device->id)
                ->whereIn('status', ['claimed', 'running'])
                ->update([
                    'status' => 'running',
                    'heartbeat_at' => $now,
                    'updated_at' => $now,
                ]);

            abort_if($updated === 0, Response::HTTP_CONFLICT, 'Work item heartbeat was rejected.');

            $this->renewLease($workItem, (string) $device->id, $validated['lease_token'], $now);
            $this->recordEvent($workItem, 'heartbeat', null, (string) $device->id, 'Local agent heartbeat.', [], $now);

            return $this->workItemOrFail($workItem);
        });

        return response()->json([
            'protocol_version' => 'v1',
            'item' => $this->item($item),
        ]);
    }

    public function complete(Request $request, string $workItem): JsonResponse
    {
        $device = $this->activeDevice($request);

        if ($device instanceof JsonResponse) {
            return $device;
        }

        $validated = $request->validate([
            'lease_token' => ['required', 'string'],
            'memory_entry' => ['sometimes', 'array'],
            'memory_entry.kind' => ['required_with:memory_entry', 'string', Rule::in(self::MEMORY_KINDS)],
            'memory_entry.summary' => ['required_with:memory_entry', 'string', 'min:8', 'max:240'],
            'memory_entry.payload' => ['present_with:memory_entry', 'array'],
            'memory_entry.payload.why' => ['required_with:memory_entry', 'string'],
            'memory_entry.payload.changed' => ['present_with:memory_entry', 'array'],
            'memory_entry.payload.tests' => ['present_with:memory_entry', 'array'],
            'memory_entry.payload.skipped_checks' => ['present_with:memory_entry', 'array'],
            'memory_entry.payload.risks' => ['present_with:memory_entry', 'array'],
        ]);

        $result = DB::transaction(function () use ($workItem, $device, $validated): array {
            $item = $this->workItemOrFail($workItem);
            $this->assertOwnedRunningItem($item, $device);
            $this->assertValidLease($workItem, (string) $device->id, $validated['lease_token']);

            if ($error = $this->lifecycle->pluginProjectWriteGuard((string) $item->project_id)) {
                throw new HttpResponseException($error);
            }

            $now = now();
            $memoryEntry = null;
            $memoryEntryId = null;

            if (array_key_exists('memory_entry', $validated)) {
                $memoryEntryId = (string) Str::ulid();
                DB::table('project_memory_entries')->insert([
                    'id' => $memoryEntryId,
                    'project_id' => (string) $item->project_id,
                    'repository_id' => $item->repository_id ? (string) $item->repository_id : null,
                    'task_id' => $item->task_id ? (string) $item->task_id : null,
                    'run_id' => null,
                    'author_user_id' => null,
                    'agent_key' => 'local_agent',
                    'source' => 'local_agent',
                    'kind' => $validated['memory_entry']['kind'],
                    'completeness' => 'complete',
                    'summary' => $validated['memory_entry']['summary'],
                    'payload' => json_encode($validated['memory_entry']['payload'], JSON_THROW_ON_ERROR),
                    'occurred_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $memoryEntry = $this->memoryEntry(DB::table('project_memory_entries')->where('id', $memoryEntryId)->first());
            }

            $status = ((bool) $item->requires_memory_entry && $memoryEntryId === null)
                ? 'completed_with_incomplete_memory'
                : 'completed';

            $updated = DB::table('agent_work_items')
                ->where('id', $workItem)
                ->where('claimed_by_device_id', $device->id)
                ->whereIn('status', ['claimed', 'running'])
                ->update([
                    'status' => $status,
                    'result_memory_entry_id' => $memoryEntryId,
                    'completed_at' => $now,
                    'updated_at' => $now,
                ]);

            abort_if($updated === 0, Response::HTTP_CONFLICT, 'Work item completion was rejected.');

            $this->releaseActiveLeases($workItem, $now);
            $this->recordEvent(
                workItemId: $workItem,
                eventType: 'completed',
                userId: null,
                deviceId: (string) $device->id,
                message: 'Local agent completed work item.',
                payload: ['memory_entry_id' => $memoryEntryId],
                now: $now,
            );

            return [
                'item' => $this->workItemOrFail($workItem),
                'memory_entry' => $memoryEntry,
            ];
        });

        return response()->json([
            'protocol_version' => 'v1',
            'item' => $this->item($result['item']),
            'memory_entry' => $result['memory_entry'],
        ]);
    }

    public function fail(Request $request, string $workItem): JsonResponse
    {
        $device = $this->activeDevice($request);

        if ($device instanceof JsonResponse) {
            return $device;
        }

        $validated = $request->validate([
            'lease_token' => ['required', 'string'],
            'failure_reason' => ['required', 'string', 'min:4', 'max:2000'],
        ]);

        $item = DB::transaction(function () use ($workItem, $device, $validated): object {
            $item = $this->workItemOrFail($workItem);
            $this->assertOwnedRunningItem($item, $device);
            $this->assertValidLease($workItem, (string) $device->id, $validated['lease_token']);

            if ($error = $this->lifecycle->pluginProjectWriteGuard((string) $item->project_id)) {
                throw new HttpResponseException($error);
            }

            $now = now();
            $updated = DB::table('agent_work_items')
                ->where('id', $workItem)
                ->where('claimed_by_device_id', $device->id)
                ->whereIn('status', ['claimed', 'running'])
                ->update([
                    'status' => 'failed',
                    'failed_at' => $now,
                    'failure_reason' => $validated['failure_reason'],
                    'updated_at' => $now,
                ]);

            abort_if($updated === 0, Response::HTTP_CONFLICT, 'Work item failure was rejected.');

            $this->releaseActiveLeases($workItem, $now);
            $this->recordEvent($workItem, 'failed', null, (string) $device->id, $validated['failure_reason'], [], $now);

            return $this->workItemOrFail($workItem);
        });

        return response()->json([
            'protocol_version' => 'v1',
            'item' => $this->item($item),
        ]);
    }

    private function activeDevice(Request $request): mixed
    {
        $auth = $request->attributes->get('plugin_auth');
        $device = is_array($auth) ? ($auth['device'] ?? null) : null;

        if (! $device || $request->header('X-DevBoard-Device-Id') !== (string) $device->id) {
            return $this->error('device_required', 'A registered active plugin device is required.', Response::HTTP_UNAUTHORIZED);
        }

        return $device;
    }

    private function workItemOrFail(string $workItem): object
    {
        $item = DB::table('agent_work_items')->where('id', $workItem)->first();
        abort_unless($item, Response::HTTP_NOT_FOUND);

        return $item;
    }

    private function assertOwnedRunningItem(object $item, object $device): void
    {
        abort_unless(
            in_array((string) $item->status, ['claimed', 'running'], true)
            && (string) $item->claimed_by_device_id === (string) $device->id,
            Response::HTTP_CONFLICT,
            'Work item is not claimed by this device.',
        );
    }

    private function assertValidLease(string $workItem, string $deviceId, string $leaseToken): void
    {
        $lease = DB::table('agent_work_item_leases')
            ->where('agent_work_item_id', $workItem)
            ->where('device_id', $deviceId)
            ->whereNull('released_at')
            ->orderByDesc('created_at')
            ->first();

        abort_unless($lease, Response::HTTP_CONFLICT, 'Active lease is required.');

        if (Carbon::parse($lease->expires_at)->isPast()) {
            DB::table('agent_work_item_leases')->where('id', $lease->id)->update([
                'released_at' => now(),
                'updated_at' => now(),
            ]);

            abort(Response::HTTP_CONFLICT, 'Active lease has expired.');
        }

        abort_unless(
            hash_equals((string) $lease->lease_token_hash, hash('sha256', $leaseToken)),
            Response::HTTP_CONFLICT,
            'Lease token is invalid.',
        );
    }

    private function createLease(string $workItem, string $deviceId, string $leaseToken, mixed $now): void
    {
        DB::table('agent_work_item_leases')->insert([
            'id' => (string) Str::ulid(),
            'agent_work_item_id' => $workItem,
            'device_id' => $deviceId,
            'lease_token_hash' => hash('sha256', $leaseToken),
            'expires_at' => $now->copy()->addMinutes(30),
            'released_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function renewLease(string $workItem, string $deviceId, string $leaseToken, mixed $now): void
    {
        $updated = DB::table('agent_work_item_leases')
            ->where('agent_work_item_id', $workItem)
            ->where('device_id', $deviceId)
            ->where('lease_token_hash', hash('sha256', $leaseToken))
            ->whereNull('released_at')
            ->update([
                'expires_at' => $now->copy()->addMinutes(30),
                'updated_at' => $now,
            ]);

        if ($updated === 0) {
            throw new HttpResponseException($this->error('lease_renewal_conflict', 'Active lease could not be renewed.', Response::HTTP_CONFLICT));
        }
    }

    private function releaseActiveLeases(string $workItem, mixed $now): void
    {
        DB::table('agent_work_item_leases')
            ->where('agent_work_item_id', $workItem)
            ->whereNull('released_at')
            ->update([
                'released_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /**
     * @param array<string, mixed> $payload
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

    /**
     * @return array<string, mixed>
     */
    private function item(object $item): array
    {
        return [
            'id' => (string) $item->id,
            'project_id' => (string) $item->project_id,
            'repository_id' => $item->repository_id ? (string) $item->repository_id : null,
            'task_id' => $item->task_id ? (string) $item->task_id : null,
            'assigned_agent_key' => (string) $item->assigned_agent_key,
            'status' => (string) $item->status,
            'priority' => (string) $item->priority,
            'title' => (string) $item->title,
            'prompt' => (string) $item->prompt,
            'payload' => json_decode((string) $item->payload, true, flags: JSON_THROW_ON_ERROR),
            'requires_memory_entry' => (bool) $item->requires_memory_entry,
            'result_memory_entry_id' => $item->result_memory_entry_id ? (string) $item->result_memory_entry_id : null,
            'claimed_by_device_id' => $item->claimed_by_device_id ? (string) $item->claimed_by_device_id : null,
            'claimed_at' => $item->claimed_at ? (string) $item->claimed_at : null,
            'heartbeat_at' => $item->heartbeat_at ? (string) $item->heartbeat_at : null,
            'completed_at' => $item->completed_at ? (string) $item->completed_at : null,
            'failed_at' => $item->failed_at ? (string) $item->failed_at : null,
            'canceled_at' => $item->canceled_at ? (string) $item->canceled_at : null,
            'failure_reason' => $item->failure_reason ? (string) $item->failure_reason : null,
            'created_at' => (string) $item->created_at,
            'updated_at' => (string) $item->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function memoryEntry(?object $entry): ?array
    {
        if (! $entry) {
            return null;
        }

        return [
            'id' => (string) $entry->id,
            'project_id' => (string) $entry->project_id,
            'repository_id' => $entry->repository_id ? (string) $entry->repository_id : null,
            'task_id' => $entry->task_id ? (string) $entry->task_id : null,
            'run_id' => $entry->run_id ? (string) $entry->run_id : null,
            'agent_key' => $entry->agent_key ? (string) $entry->agent_key : null,
            'source' => (string) $entry->source,
            'kind' => (string) $entry->kind,
            'completeness' => (string) $entry->completeness,
            'summary' => (string) $entry->summary,
            'payload' => json_decode((string) $entry->payload, true, flags: JSON_THROW_ON_ERROR),
            'occurred_at' => (string) $entry->occurred_at,
        ];
    }

    /**
     * @param array<string, mixed> $details
     */
    private function error(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json(['error' => $error], $status);
    }
}
