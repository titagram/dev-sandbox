<?php

namespace App\Http\Controllers\Dashboard\Api;

use App\Dashboard\DashboardApiReader;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use App\Models\User;
use App\Projects\ProjectLifecycleService;
use App\Services\ServerAgentWorkService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class DashboardAgentChatController extends Controller
{
    use ChecksDashboardRoles;

    public function __construct(
        private readonly DashboardApiReader $reader,
        private readonly ProjectLifecycleService $lifecycle,
        private readonly ServerAgentWorkService $serverAgentWork,
    ) {}

    public function index(Request $request, string $project): JsonResponse
    {
        $this->authorizeReader($this->activeDashboardUser($request));

        return response()->json($this->reader->projectAgentChats($project));
    }

    public function show(Request $request, string $project, string $thread): JsonResponse
    {
        $this->authorizeReader($this->activeDashboardUser($request));

        return response()->json($this->reader->agentChatThreadDetail($project, $thread));
    }

    public function store(Request $request, string $project): JsonResponse
    {
        $user = $this->activeDashboardUser($request);
        $this->authorizeMutator($user);
        if ($response = $this->lifecycle->assertProjectActiveForDashboard($project)) {
            return $response;
        }

        $data = $request->validate([
            'agent_key' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail) use ($project): void {
                    if (! is_string($value) || ! $this->serverAgentWork->isAssignableAgentKey($value, $project)) {
                        $fail('The selected agent key is invalid.');
                    }
                },
            ],
            'title' => ['sometimes', 'nullable', 'string', 'max:180'],
            'repository_id' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists('repositories', 'id')->where('project_id', $project),
            ],
            'task_id' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists('tasks', 'id')->where('project_id', $project),
            ],
            'metadata' => ['sometimes', 'array'],
            'initial_message' => ['sometimes', 'nullable', 'string', 'min:1', 'max:8000'],
        ]);

        $threadId = (string) Str::ulid();
        $agentKey = (string) $data['agent_key'];
        $initialMessage = $this->normalizedContent($data['initial_message'] ?? null);
        $now = now();

        DB::table('agent_chat_threads')->insert([
            'id' => $threadId,
            'project_id' => $project,
            'repository_id' => $data['repository_id'] ?? null,
            'task_id' => $data['task_id'] ?? null,
            'created_by_user_id' => $user->id,
            'agent_key' => $agentKey,
            'title' => $this->threadTitle($agentKey, $data['title'] ?? null, $initialMessage),
            'status' => 'active',
            'latest_agent_work_item_id' => null,
            'latest_assistant_run_id' => null,
            'last_message_at' => null,
            'metadata' => json_encode($data['metadata'] ?? [], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($initialMessage !== null) {
            $this->appendUserTurn($project, $threadId, $user, $initialMessage, []);
        }

        return response()->json($this->reader->agentChatThreadDetail($project, $threadId), 201);
    }

    public function storeMessage(Request $request, string $project, string $thread): JsonResponse
    {
        $user = $this->activeDashboardUser($request);
        $this->authorizeMutator($user);
        if ($response = $this->lifecycle->assertProjectActiveForDashboard($project)) {
            return $response;
        }

        $data = $request->validate([
            'content' => ['required', 'string', 'min:1', 'max:8000'],
            'metadata' => ['sometimes', 'array'],
        ]);

        $this->assertThreadWritable($project, $thread);
        $this->appendUserTurn($project, $thread, $user, $this->normalizedContent($data['content']) ?? '', $data['metadata'] ?? []);

        return response()->json($this->reader->agentChatThreadDetail($project, $thread));
    }

    public function destroy(Request $request, string $project, string $thread): JsonResponse
    {
        $user = $this->activeDashboardUser($request);
        $this->authorizeMutator($user);
        if ($response = $this->lifecycle->assertProjectActiveForDashboard($project)) {
            return $response;
        }

        $data = $request->validate([
            'message' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $row = DB::table('agent_chat_threads')
            ->where('project_id', $project)
            ->where('id', $thread)
            ->first();
        abort_unless($row, 404);
        abort_if((string) $row->status === 'archived' || $row->archived_at !== null, 409, 'Agent chat thread is already archived.');

        $now = now();
        $message = $data['message'] ?? null;

        DB::transaction(function () use ($project, $row, $thread, $user, $message, $now): void {
            if ($row->latest_agent_work_item_id) {
                $this->archiveLinkedWorkItemForThread(
                    projectId: $project,
                    workItemId: (string) $row->latest_agent_work_item_id,
                    userId: $user->id,
                    message: $message,
                    now: $now,
                );
            }

            DB::table('agent_chat_threads')
                ->where('project_id', $project)
                ->where('id', $thread)
                ->update([
                    'status' => 'archived',
                    'archived_at' => $now,
                    'archived_by_user_id' => $user->id,
                    'archive_reason' => $message,
                    'updated_at' => $now,
                ]);
        });

        return response()->json($this->reader->agentChatThreadDetail($project, $thread));
    }

    private function appendUserTurn(string $projectId, string $threadId, User $user, string $content, array $metadata): void
    {
        $thread = DB::table('agent_chat_threads')
            ->where('project_id', $projectId)
            ->where('id', $threadId)
            ->first();
        abort_unless($thread, 404);

        $agentKey = (string) $thread->agent_key;
        $serverHandled = $this->serverAgentWork->shouldHandle($agentKey, $projectId);
        $workItemId = (string) Str::ulid();
        $messageId = (string) Str::ulid();
        $now = now();

        DB::transaction(function () use ($projectId, $thread, $user, $content, $metadata, $agentKey, $serverHandled, $workItemId, $messageId, $now): void {
            DB::table('agent_work_items')->insert([
                'id' => $workItemId,
                'project_id' => $projectId,
                'repository_id' => $thread->repository_id,
                'task_id' => $thread->task_id,
                'requested_by_user_id' => $user->id,
                'assigned_agent_key' => $agentKey,
                'status' => 'queued',
                'priority' => 'normal',
                'title' => $this->workItemTitle($agentKey, $content),
                'prompt' => $content,
                'payload' => json_encode([
                    'schema' => 'devboard.agent_chat_turn.v1',
                    'source' => 'agent_chat',
                    'agent_chat_thread_id' => (string) $thread->id,
                    'agent_chat_message_id' => $messageId,
                ], JSON_THROW_ON_ERROR),
                'requires_memory_entry' => true,
                'result_memory_entry_id' => null,
                'claimed_by_device_id' => null,
                'claimed_at' => null,
                'heartbeat_at' => null,
                'completed_at' => null,
                'failed_at' => null,
                'canceled_at' => null,
                'failure_reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('agent_chat_messages')->insert([
                'id' => $messageId,
                'agent_chat_thread_id' => $thread->id,
                'author_user_id' => $user->id,
                'assistant_run_id' => null,
                'agent_work_item_id' => $workItemId,
                'role' => 'user',
                'content' => $content,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);

            DB::table('agent_work_item_events')->insert([
                'id' => (string) Str::ulid(),
                'agent_work_item_id' => $workItemId,
                'actor_user_id' => $user->id,
                'actor_device_id' => null,
                'event_type' => 'queued',
                'message' => 'Dashboard user queued an agent chat turn.',
                'payload' => json_encode([
                    'source' => 'agent_chat',
                    'agent_chat_thread_id' => (string) $thread->id,
                    'agent_chat_message_id' => $messageId,
                    'agent_key' => $agentKey,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('agent_chat_threads')->where('id', $thread->id)->update([
                'status' => $serverHandled ? 'waiting_for_agent' : 'pending_local_agent',
                'latest_agent_work_item_id' => $workItemId,
                'last_message_at' => $now,
                'updated_at' => $now,
            ]);
        });

        if (! $serverHandled) {
            return;
        }

        $this->serverAgentWork->process($workItemId);
        $this->syncServerAgentResponse($projectId, $threadId, $workItemId);
    }

    private function syncServerAgentResponse(string $projectId, string $threadId, string $workItemId): void
    {
        $workItem = DB::table('agent_work_items')->where('id', $workItemId)->first();
        abort_unless($workItem && (string) $workItem->project_id === $projectId, 404);

        $run = DB::table('assistant_runs')
            ->where('target_type', 'agent_work_item')
            ->where('target_id', $workItemId)
            ->orderByDesc('started_at')
            ->first();

        $assistantMessage = $run
            ? DB::table('assistant_messages')
                ->where('assistant_run_id', $run->id)
                ->where('role', 'assistant')
                ->orderBy('created_at')
                ->orderBy('id')
                ->first()
            : null;

        if ($assistantMessage) {
            $now = now();

            DB::transaction(function () use ($threadId, $workItemId, $workItem, $run, $assistantMessage, $now): void {
                $exists = DB::table('agent_chat_messages')
                    ->where('agent_chat_thread_id', $threadId)
                    ->where('assistant_run_id', $run->id)
                    ->where('role', 'assistant')
                    ->exists();

                if (! $exists) {
                    DB::table('agent_chat_messages')->insert([
                        'id' => (string) Str::ulid(),
                        'agent_chat_thread_id' => $threadId,
                        'author_user_id' => null,
                        'assistant_run_id' => $run->id,
                        'agent_work_item_id' => $workItemId,
                        'role' => 'assistant',
                        'content' => (string) $assistantMessage->content,
                        'metadata' => json_encode([
                            'schema' => 'devboard.agent_chat_response.v1',
                            'agent_key' => (string) $workItem->assigned_agent_key,
                        ], JSON_THROW_ON_ERROR),
                        'created_at' => $now,
                    ]);
                }

                DB::table('agent_chat_threads')->where('id', $threadId)->update([
                    'status' => 'active',
                    'latest_assistant_run_id' => $run->id,
                    'last_message_at' => $now,
                    'updated_at' => $now,
                ]);
            });

            return;
        }

        if ((string) $workItem->status !== 'failed') {
            return;
        }

        $now = now();
        $failureReason = (string) ($workItem->failure_reason ?: 'Agent execution failed.');

        DB::transaction(function () use ($threadId, $workItemId, $workItem, $failureReason, $now): void {
            DB::table('agent_chat_messages')->insert([
                'id' => (string) Str::ulid(),
                'agent_chat_thread_id' => $threadId,
                'author_user_id' => null,
                'assistant_run_id' => null,
                'agent_work_item_id' => $workItemId,
                'role' => 'system',
                'content' => 'Agent failed: '.$failureReason,
                'metadata' => json_encode([
                    'schema' => 'devboard.agent_chat_error.v1',
                    'agent_key' => (string) $workItem->assigned_agent_key,
                    'failure_reason' => $failureReason,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);

            DB::table('agent_chat_threads')->where('id', $threadId)->update([
                'status' => 'failed',
                'last_message_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    private function assertThreadWritable(string $projectId, string $threadId): void
    {
        $thread = DB::table('agent_chat_threads')
            ->where('project_id', $projectId)
            ->where('id', $threadId)
            ->first();
        abort_unless($thread, 404);

        abort_if((string) $thread->status === 'archived', 409, 'Agent chat thread is archived.');
    }

    private function archiveLinkedWorkItemForThread(
        string $projectId,
        string $workItemId,
        int $userId,
        ?string $message,
        mixed $now,
    ): void {
        $item = DB::table('agent_work_items')
            ->where('project_id', $projectId)
            ->where('id', $workItemId)
            ->lockForUpdate()
            ->first();

        if (! $item || $item->archived_at !== null) {
            return;
        }

        abort_if(
            in_array((string) $item->status, ['claimed', 'running'], true)
            || $item->claimed_by_device_id !== null
            || $item->claimed_at !== null
            || $item->heartbeat_at !== null,
            409,
            'Latest work item is running and cannot be archived.',
        );

        $updates = [
            'archived_at' => $now,
            'archived_by_user_id' => $userId,
            'archive_reason' => $message,
            'updated_at' => $now,
        ];

        if ((string) $item->status === 'queued') {
            $updates['status'] = 'canceled';
            $updates['canceled_at'] = $now;
        }

        DB::table('agent_work_items')->where('id', $workItemId)->update($updates);

        if ((string) $item->status === 'queued') {
            $this->recordWorkEvent($workItemId, 'canceled', $userId, $message, ['source' => 'agent_chat_archive'], $now);
        }

        $this->recordWorkEvent($workItemId, 'archived', $userId, $message, ['source' => 'agent_chat_archive'], $now);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordWorkEvent(string $workItemId, string $eventType, int $userId, ?string $message, array $payload, mixed $now): void
    {
        DB::table('agent_work_item_events')->insert([
            'id' => (string) Str::ulid(),
            'agent_work_item_id' => $workItemId,
            'actor_user_id' => $userId,
            'actor_device_id' => null,
            'event_type' => $eventType,
            'message' => $message,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function authorizeReader(User $user): void
    {
        abort_unless(
            $this->userHasRole($user, 'PM')
            || $this->userHasRole($user, 'Developer')
            || $this->userHasRole($user, 'Sysadmin')
            || $this->userHasRole($user, 'Admin'),
            403,
        );
    }

    private function authorizeMutator(User $user): void
    {
        abort_unless(
            $this->userHasRole($user, 'PM')
            || $this->userHasRole($user, 'Developer')
            || $this->userHasRole($user, 'Admin'),
            403,
        );
    }

    private function activeDashboardUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->status === 'active', 403);

        return $user;
    }

    private function normalizedContent(mixed $content): ?string
    {
        if (! is_string($content)) {
            return null;
        }

        $normalized = trim($content);

        return $normalized === '' ? null : $normalized;
    }

    private function threadTitle(string $agentKey, ?string $title, ?string $initialMessage): string
    {
        $normalizedTitle = is_string($title) ? trim($title) : '';

        if ($normalizedTitle !== '') {
            return $normalizedTitle;
        }

        if ($initialMessage !== null) {
            return (string) Str::limit($initialMessage, 80);
        }

        return 'Chat with '.Str::headline($agentKey);
    }

    private function workItemTitle(string $agentKey, string $content): string
    {
        return Str::headline($agentKey).' chat: '.Str::limit($content, 80);
    }
}
