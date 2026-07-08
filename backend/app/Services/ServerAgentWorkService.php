<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ServerAgentWorkService
{
    private const SERVER_AGENT_KEYS = ['socrates', 'platon', 'aristoteles'];

    private const PROFILE_KEY_BY_AGENT = [
        'socrates' => 'socrate_supervisor',
        'platon' => 'task_clarifier',
        'aristoteles' => 'backlog_triage',
    ];

    public function shouldHandle(string $agentKey): bool
    {
        return in_array($agentKey, self::SERVER_AGENT_KEYS, true);
    }

    public function process(string $workItemId): void
    {
        $workItem = $this->startQueuedWork($workItemId);

        if (! $workItem) {
            return;
        }

        try {
            $modelProfile = $this->modelProfileForWorkItem($workItem);
            $context = $this->contextSnapshot($workItem);
            $answer = $this->askAgent($workItem, $modelProfile, $context);
            $this->completeWork($workItem, $modelProfile, $context, $answer);
        } catch (Throwable $e) {
            $this->failWork($workItem, $e);
        }
    }

    private function startQueuedWork(string $workItemId): ?object
    {
        return DB::transaction(function () use ($workItemId): ?object {
            $workItem = DB::table('agent_work_items')
                ->where('id', $workItemId)
                ->lockForUpdate()
                ->first();

            if (! $workItem || (string) $workItem->status !== 'queued') {
                return null;
            }

            $now = now();
            DB::table('agent_work_items')->where('id', $workItemId)->update([
                'status' => 'running',
                'claimed_at' => $now,
                'heartbeat_at' => $now,
                'updated_at' => $now,
            ]);

            $this->recordEvent(
                workItemId: $workItemId,
                eventType: 'running',
                userId: null,
                deviceId: null,
                message: 'Server-side agent execution started.',
                payload: [
                    'agent_key' => (string) $workItem->assigned_agent_key,
                    'execution_mode' => 'server_agent',
                ],
                now: $now,
            );

            return DB::table('agent_work_items')->where('id', $workItemId)->first();
        });
    }

    private function modelProfileForWorkItem(object $workItem): object
    {
        $agentKey = (string) $workItem->assigned_agent_key;
        $profileKey = self::PROFILE_KEY_BY_AGENT[$agentKey] ?? null;

        if ($profileKey === null) {
            throw new RuntimeException("Server-side agent {$agentKey} is not configured yet.");
        }

        $profile = DB::table('ai_agent_profiles')
            ->join('ai_model_profiles', 'ai_model_profiles.id', '=', 'ai_agent_profiles.default_model_profile_id')
            ->join('ai_model_providers', 'ai_model_providers.id', '=', 'ai_model_profiles.provider_id')
            ->select([
                'ai_agent_profiles.id as agent_profile_id',
                'ai_agent_profiles.agent_key as profile_agent_key',
                'ai_agent_profiles.display_name as agent_display_name',
                'ai_agent_profiles.description as agent_description',
                'ai_model_profiles.id as model_profile_id',
                'ai_model_profiles.profile_key as model_profile_key',
                'ai_model_profiles.model_name',
                'ai_model_profiles.max_output_tokens',
                'ai_model_profiles.temperature',
                'ai_model_profiles.timeout_seconds',
                'ai_model_profiles.enabled as model_profile_enabled',
                'ai_model_providers.id as model_provider_id',
                'ai_model_providers.provider_key',
                'ai_model_providers.provider_type',
                'ai_model_providers.base_url',
                'ai_model_providers.encrypted_api_key',
                'ai_model_providers.enabled as provider_enabled',
            ])
            ->where('ai_agent_profiles.agent_key', $profileKey)
            ->where('ai_agent_profiles.enabled', true)
            ->first();

        if (! $profile) {
            throw new RuntimeException("Agent profile {$profileKey} is not enabled or has no model profile.");
        }

        if (! (bool) $profile->model_profile_enabled || ! (bool) $profile->provider_enabled) {
            throw new RuntimeException('The selected AI model provider is disabled.');
        }

        if (! filled($profile->encrypted_api_key)) {
            throw new RuntimeException('The selected AI model provider has no API key configured.');
        }

        if ((string) $profile->provider_type !== 'openai_compatible') {
            throw new RuntimeException('Only OpenAI-compatible providers are supported for server-side agent work.');
        }

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function askAgent(object $workItem, object $modelProfile, array $context): string
    {
        $apiKey = Crypt::decryptString((string) $modelProfile->encrypted_api_key);
        $endpoint = $this->chatCompletionsEndpoint((string) ($modelProfile->base_url ?: 'https://api.openai.com/v1'));
        $timeout = max(5, (int) ($modelProfile->timeout_seconds ?? 30));
        $maxTokens = max(256, min(2048, (int) ($modelProfile->max_output_tokens ?? 1024)));

        $response = Http::timeout($timeout)
            ->acceptJson()
            ->withToken($apiKey)
            ->post($endpoint, [
                'model' => (string) $modelProfile->model_name,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $this->systemPrompt($workItem, $modelProfile),
                    ],
                    [
                        'role' => 'user',
                        'content' => $this->userPrompt($workItem, $context),
                    ],
                ],
                'temperature' => (float) ($modelProfile->temperature ?? 0),
                'max_tokens' => $maxTokens,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('AI provider returned HTTP '.$response->status().'.');
        }

        $answer = $this->extractAnswer($response->json());

        if ($answer === '') {
            throw new RuntimeException('AI provider returned an empty answer.');
        }

        return $answer;
    }

    private function systemPrompt(object $workItem, object $modelProfile): string
    {
        $displayName = (string) $modelProfile->agent_display_name;
        $agentKey = (string) $workItem->assigned_agent_key;

        return <<<PROMPT
You are {$displayName} ({$agentKey}) inside DevBoard.
Answer only from the provided project context. Start from memory_search_status and recent_memory; cite memory refs when they influence the answer. If information is missing, say what is missing and what evidence would be needed.
Do not claim to have inspected files unless the context contains that evidence.
Reply in the same language as the user's question when practical.
Keep the answer concise, actionable, and suitable for saving as project memory.
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function userPrompt(object $workItem, array $context): string
    {
        $encodedContext = json_encode(
            $context,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return <<<PROMPT
User request:
{$workItem->prompt}

DevBoard context snapshot:
{$encodedContext}
PROMPT;
    }

    private function chatCompletionsEndpoint(string $baseUrl): string
    {
        $base = rtrim($baseUrl, '/');
        $base = preg_replace('#/(chat/completions|models)$#', '', $base) ?: $base;

        return $base.'/chat/completions';
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function extractAnswer(?array $json): string
    {
        $content = data_get($json, 'choices.0.message.content');

        if (is_string($content)) {
            return trim($content);
        }

        $outputText = data_get($json, 'output_text');
        if (is_string($outputText)) {
            return trim($outputText);
        }

        $parts = data_get($json, 'content');
        if (is_array($parts)) {
            $text = collect($parts)
                ->map(fn (mixed $part): string => is_array($part) && is_string($part['text'] ?? null) ? $part['text'] : '')
                ->implode("\n");

            return trim($text);
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function contextSnapshot(object $workItem): array
    {
        $project = DB::table('projects')
            ->select(['id', 'name', 'slug', 'description', 'status'])
            ->where('id', $workItem->project_id)
            ->first();

        $recentMemory = $this->recentMemory((string) $workItem->project_id);

        return [
            'project' => $project ? [
                'id' => (string) $project->id,
                'name' => (string) $project->name,
                'slug' => (string) $project->slug,
                'description' => $project->description ? (string) $project->description : null,
                'status' => (string) $project->status,
            ] : null,
            'request' => [
                'id' => (string) $workItem->id,
                'title' => (string) $workItem->title,
                'prompt' => (string) $workItem->prompt,
                'payload' => $this->decodeJsonObject($workItem->payload),
            ],
            'memory_search_status' => $this->memorySearchStatus($recentMemory),
            'repositories' => $this->repositories((string) $workItem->project_id),
            'recent_memory' => $recentMemory,
            'wiki_pages' => $this->wikiPages((string) $workItem->project_id),
            'open_tasks' => $this->openTasks((string) $workItem->project_id),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function repositories(string $projectId): array
    {
        return DB::table('repositories')
            ->select(['id', 'name', 'slug', 'default_branch', 'local_only', 'code_exposure_policy'])
            ->where('project_id', $projectId)
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (object $repository): array => [
                'id' => (string) $repository->id,
                'name' => (string) $repository->name,
                'slug' => (string) $repository->slug,
                'default_branch' => (string) $repository->default_branch,
                'local_only' => (bool) $repository->local_only,
                'code_exposure_policy' => (string) $repository->code_exposure_policy,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentMemory(string $projectId): array
    {
        return DB::table('project_memory_entries')
            ->select(['id', 'agent_key', 'source', 'kind', 'completeness', 'summary', 'occurred_at'])
            ->where('project_id', $projectId)
            ->orderByDesc('occurred_at')
            ->limit(16)
            ->get()
            ->map(fn (object $entry): array => [
                'id' => (string) $entry->id,
                'agent_key' => $entry->agent_key ? (string) $entry->agent_key : null,
                'source' => (string) $entry->source,
                'kind' => (string) $entry->kind,
                'completeness' => (string) $entry->completeness,
                'summary' => (string) $entry->summary,
                'occurred_at' => (string) $entry->occurred_at,
            ])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $recentMemory
     * @return array<string, mixed>
     */
    private function memorySearchStatus(array $recentMemory): array
    {
        $refs = array_map(
            fn (array $entry): array => [
                'type' => 'project_memory',
                'id' => (string) $entry['id'],
                'source' => (string) ($entry['source'] ?? ''),
                'agent_key' => $entry['agent_key'] ?? null,
            ],
            array_slice($recentMemory, 0, 8),
        );

        return [
            'status' => $refs === [] ? 'empty' : 'available',
            'refs' => $refs,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function wikiPages(string $projectId): array
    {
        return DB::table('wiki_pages')
            ->leftJoin('wiki_revisions', 'wiki_revisions.id', '=', 'wiki_pages.current_revision_id')
            ->select([
                'wiki_pages.id',
                'wiki_pages.title',
                'wiki_pages.page_type',
                'wiki_pages.source_status',
                'wiki_pages.updated_at',
                'wiki_revisions.content_markdown',
                'wiki_revisions.evidence_refs',
            ])
            ->where('wiki_pages.project_id', $projectId)
            ->orderByDesc('wiki_pages.updated_at')
            ->limit(12)
            ->get()
            ->map(fn (object $page): array => [
                'id' => (string) $page->id,
                'title' => (string) $page->title,
                'page_type' => (string) $page->page_type,
                'source_status' => (string) $page->source_status,
                'excerpt' => Str::limit(trim((string) ($page->content_markdown ?? '')), 1200),
                'evidence_count' => count($this->decodeJsonList($page->evidence_refs)),
                'updated_at' => (string) $page->updated_at,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function openTasks(string $projectId): array
    {
        return DB::table('tasks')
            ->join('kanban_columns', 'kanban_columns.id', '=', 'tasks.status_column_id')
            ->select([
                'tasks.id',
                'tasks.title',
                'tasks.description',
                'tasks.priority',
                'tasks.risk_level',
                'kanban_columns.status_key',
                'tasks.updated_at',
            ])
            ->where('tasks.project_id', $projectId)
            ->where('kanban_columns.status_key', '!=', 'done')
            ->orderByDesc('tasks.updated_at')
            ->limit(20)
            ->get()
            ->map(fn (object $task): array => [
                'id' => (string) $task->id,
                'title' => (string) $task->title,
                'description' => $task->description ? Str::limit((string) $task->description, 500) : null,
                'priority' => (string) $task->priority,
                'risk_level' => (string) $task->risk_level,
                'status' => (string) $task->status_key,
                'updated_at' => (string) $task->updated_at,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function completeWork(object $workItem, object $modelProfile, array $context, string $answer): void
    {
        DB::transaction(function () use ($answer, $context, $modelProfile, $workItem): void {
            $now = now();
            $memoryEntryId = (string) Str::ulid();
            $assistantRunId = (string) Str::ulid();
            $contextSnapshot = json_encode($context, JSON_THROW_ON_ERROR);

            DB::table('project_memory_entries')->insert([
                'id' => $memoryEntryId,
                'project_id' => (string) $workItem->project_id,
                'repository_id' => $workItem->repository_id ? (string) $workItem->repository_id : null,
                'task_id' => $workItem->task_id ? (string) $workItem->task_id : null,
                'run_id' => null,
                'author_user_id' => null,
                'agent_key' => (string) $workItem->assigned_agent_key,
                'source' => 'server_agent',
                'kind' => 'agent_note',
                'completeness' => 'complete',
                'summary' => $this->summaryForAnswer((string) $workItem->assigned_agent_key, $answer),
                'payload' => json_encode([
                    'schema' => 'devboard.server_agent_answer.v1',
                    'agent_work_item_id' => (string) $workItem->id,
                    'question' => (string) $workItem->prompt,
                    'answer' => $answer,
                    'provider_key' => (string) $modelProfile->provider_key,
                    'model_name' => (string) $modelProfile->model_name,
                    'memory_search_status' => $context['memory_search_status'] ?? ['status' => 'unknown', 'refs' => []],
                    'context_counts' => [
                        'repositories' => count($context['repositories'] ?? []),
                        'recent_memory' => count($context['recent_memory'] ?? []),
                        'wiki_pages' => count($context['wiki_pages'] ?? []),
                        'memory_refs' => count(data_get($context, 'memory_search_status.refs', [])),
                        'open_tasks' => count($context['open_tasks'] ?? []),
                    ],
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('assistant_runs')->insert([
                'id' => $assistantRunId,
                'project_id' => (string) $workItem->project_id,
                'agent_profile_id' => (string) $modelProfile->agent_profile_id,
                'target_type' => 'agent_work_item',
                'target_id' => (string) $workItem->id,
                'triggered_by_user_id' => $workItem->requested_by_user_id === null ? null : (int) $workItem->requested_by_user_id,
                'status' => 'completed',
                'model_provider_id' => (string) $modelProfile->model_provider_id,
                'model_profile_id' => (string) $modelProfile->model_profile_id,
                'context_hash' => hash('sha256', $contextSnapshot),
                'context_snapshot' => $contextSnapshot,
                'result_summary' => $this->summaryForAnswer((string) $workItem->assigned_agent_key, $answer),
                'metadata' => json_encode([
                    'schema' => 'devboard.agent_work_chat.v1',
                    'agent_key' => (string) $workItem->assigned_agent_key,
                    'profile_agent_key' => (string) $modelProfile->profile_agent_key,
                    'agent_work_item_id' => (string) $workItem->id,
                    'memory_entry_id' => $memoryEntryId,
                    'memory_search_status' => $context['memory_search_status'] ?? ['status' => 'unknown', 'refs' => []],
                ], JSON_THROW_ON_ERROR),
                'started_at' => $workItem->claimed_at ?: $now,
                'finished_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ([
                ['role' => 'user', 'content' => (string) $workItem->prompt],
                ['role' => 'assistant', 'content' => $answer],
            ] as $message) {
                DB::table('assistant_messages')->insert([
                    'id' => (string) Str::ulid(),
                    'assistant_run_id' => $assistantRunId,
                    'role' => $message['role'],
                    'content' => $message['content'],
                    'metadata' => json_encode([
                        'agent_work_item_id' => (string) $workItem->id,
                        'agent_key' => (string) $workItem->assigned_agent_key,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                ]);
            }

            DB::table('agent_work_items')
                ->where('id', $workItem->id)
                ->where('status', 'running')
                ->update([
                    'status' => 'completed',
                    'result_memory_entry_id' => $memoryEntryId,
                    'completed_at' => $now,
                    'failure_reason' => null,
                    'updated_at' => $now,
                ]);

            $this->recordEvent(
                workItemId: (string) $workItem->id,
                eventType: 'completed',
                userId: null,
                deviceId: null,
                message: 'Server-side agent answered and wrote project memory.',
                payload: [
                    'memory_entry_id' => $memoryEntryId,
                    'provider_key' => (string) $modelProfile->provider_key,
                    'model_name' => (string) $modelProfile->model_name,
                    'memory_search_status' => $context['memory_search_status'] ?? ['status' => 'unknown', 'refs' => []],
                ],
                now: $now,
            );
        });
    }

    private function failWork(object $workItem, Throwable $e): void
    {
        DB::transaction(function () use ($e, $workItem): void {
            $now = now();
            $message = Str::limit($e->getMessage(), 1000);

            DB::table('agent_work_items')
                ->where('id', $workItem->id)
                ->where('status', 'running')
                ->update([
                    'status' => 'failed',
                    'failed_at' => $now,
                    'failure_reason' => $message,
                    'updated_at' => $now,
                ]);

            $this->recordEvent(
                workItemId: (string) $workItem->id,
                eventType: 'failed',
                userId: null,
                deviceId: null,
                message: $message,
                payload: ['exception' => class_basename($e)],
                now: $now,
            );
        });
    }

    private function summaryForAnswer(string $agentKey, string $answer): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $answer) ?? $answer);
        $label = Str::ucfirst($agentKey);

        return Str::limit("{$label}: {$normalized}", 240);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<mixed>
     */
    private function decodeJsonList(mixed $value): array
    {
        $decoded = $this->decodeJsonObject($value);

        return array_is_list($decoded) ? $decoded : [];
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
