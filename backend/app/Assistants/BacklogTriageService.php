<?php

namespace App\Assistants;

use App\Assistants\Agents\BacklogTriageAgent;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

final class BacklogTriageService
{
    /**
     * @return array{run: array<string, mixed>, suggestion: array<string, mixed>}
     */
    public function triage(string $projectId, int $userId): array
    {
        $project = $this->project($projectId);
        $agent = DB::table('ai_agent_profiles')->where('agent_key', 'backlog_triage')->first();
        abort_unless($agent, 500, 'Backlog Triage agent profile is not configured.');

        $context = $this->contextSnapshot($project);
        $execution = $this->generateSuggestion($project, $agent, $context);
        $structured = $execution['structured'];
        $evidenceRefs = $this->evidenceRefs($project, $context);
        $body = $this->bodyMarkdown($structured);
        $now = now();
        $runId = (string) Str::ulid();
        $suggestionId = (string) Str::ulid();

        DB::transaction(function () use ($agent, $body, $context, $evidenceRefs, $execution, $project, $runId, $structured, $suggestionId, $userId, $now): void {
            DB::table('projects')->where('id', $project->id)->lockForUpdate()->value('id');

            DB::table('assistant_runs')->insert([
                'id' => $runId,
                'project_id' => $project->id,
                'agent_profile_id' => $agent->id,
                'target_type' => 'project',
                'target_id' => $project->id,
                'triggered_by_user_id' => $userId,
                'status' => 'completed',
                'model_provider_id' => $execution['model_provider_id'],
                'model_profile_id' => $execution['model_profile_id'],
                'context_hash' => hash('sha256', json_encode($context, JSON_THROW_ON_ERROR)),
                'context_snapshot' => json_encode($context, JSON_THROW_ON_ERROR),
                'result_summary' => 'Backlog triage suggestion created.',
                'metadata' => json_encode([
                    'execution_mode' => $execution['execution_mode'],
                    'external_provider_call' => $execution['external_provider_call'],
                    'provider_key' => $execution['provider_key'],
                    'model_name' => $execution['model_name'],
                    'sdk_agent' => BacklogTriageAgent::class,
                    'provider_failure' => $execution['provider_failure'],
                ], JSON_THROW_ON_ERROR),
                'started_at' => $now,
                'finished_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('assistant_messages')->insert([
                [
                    'id' => (string) Str::ulid(),
                    'assistant_run_id' => $runId,
                    'role' => 'user',
                    'content' => $execution['prompt'],
                    'metadata' => json_encode(['context_hash' => hash('sha256', json_encode($context, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                ],
                [
                    'id' => (string) Str::ulid(),
                    'assistant_run_id' => $runId,
                    'role' => 'assistant',
                    'content' => $body,
                    'metadata' => json_encode([
                        'structured' => true,
                        'execution_mode' => $execution['execution_mode'],
                        'provider_failure' => $execution['provider_failure'],
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                ],
            ]);

            DB::table('assistant_suggestions')->insert([
                'id' => $suggestionId,
                'assistant_run_id' => $runId,
                'project_id' => $project->id,
                'target_type' => 'project',
                'target_id' => $project->id,
                'suggestion_type' => 'backlog_triage',
                'title' => 'Triage backlog before planning',
                'body_markdown' => $body,
                'structured_payload' => json_encode($structured, JSON_THROW_ON_ERROR),
                'evidence_refs' => json_encode($evidenceRefs, JSON_THROW_ON_ERROR),
                'confidence' => $structured['confidence'],
                'approval_required' => true,
                'status' => 'pending',
                'created_by_user_id' => $userId,
                'resolved_by_user_id' => null,
                'resolved_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('audit_logs')->insert([
                'id' => (string) Str::ulid(),
                'actor_user_id' => $userId,
                'actor_device_id' => null,
                'actor_type' => 'user',
                'action' => 'assistant.backlog_triage.created',
                'target_type' => 'project',
                'target_id' => $project->id,
                'ip_address' => null,
                'user_agent' => null,
                'payload' => json_encode([
                    'assistant_run_id' => $runId,
                    'assistant_suggestion_id' => $suggestionId,
                    'agent_key' => 'backlog_triage',
                    'external_provider_call' => $execution['external_provider_call'],
                    'execution_mode' => $execution['execution_mode'],
                    'mutated_target' => false,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);
        });

        return [
            'run' => $this->runPayload($runId),
            'suggestion' => $this->suggestionPayload($suggestionId),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestSuggestionForProject(string $projectId): ?array
    {
        $suggestion = DB::table('assistant_suggestions')
            ->where('target_type', 'project')
            ->where('target_id', $projectId)
            ->where('suggestion_type', 'backlog_triage')
            ->orderByDesc('created_at')
            ->first();

        return $suggestion ? $this->suggestionPayload((string) $suggestion->id) : null;
    }

    private function project(string $projectId): object
    {
        $project = DB::table('projects')
            ->where('id', $projectId)
            ->where('status', '!=', 'deleted')
            ->first();

        abort_unless($project, 404);

        return $project;
    }

    /**
     * @return array<string, mixed>
     */
    private function contextSnapshot(object $project): array
    {
        $taskRows = DB::table('tasks')
            ->join('kanban_columns', 'kanban_columns.id', '=', 'tasks.status_column_id')
            ->leftJoin('users as owners', 'owners.id', '=', 'tasks.owner_user_id')
            ->where('tasks.project_id', $project->id)
            ->where('kanban_columns.status_key', '!=', 'done')
            ->select([
                'tasks.id',
                'tasks.title',
                'tasks.description',
                'tasks.priority',
                'tasks.risk_level',
                'tasks.due_at',
                'tasks.updated_at',
                'owners.name as owner_name',
                'kanban_columns.status_key',
                'kanban_columns.name as status_name',
                'kanban_columns.position',
            ])
            ->orderBy('kanban_columns.position')
            ->orderByDesc('tasks.updated_at')
            ->limit(50)
            ->get();

        return [
            'source_status' => 'verified_from_code',
            'context_kind' => 'backlog_triage',
            'project' => [
                'id' => (string) $project->id,
                'name' => (string) $project->name,
                'slug' => (string) $project->slug,
                'status' => (string) $project->status,
                'description' => (string) ($project->description ?? ''),
            ],
            'tasks' => [
                'open_count' => $taskRows->count(),
                'items' => $taskRows->map(fn (object $task): array => [
                    'id' => (string) $task->id,
                    'title' => (string) $task->title,
                    'description_excerpt' => Str::limit((string) ($task->description ?? ''), 260, ''),
                    'priority' => (string) $task->priority,
                    'risk_level' => (string) $task->risk_level,
                    'status_key' => (string) $task->status_key,
                    'status_name' => (string) $task->status_name,
                    'owner_name' => (string) ($task->owner_name ?? 'Unassigned'),
                    'due_at' => $task->due_at ? (string) $task->due_at : null,
                    'updated_at' => (string) $task->updated_at,
                ])->all(),
                'by_status' => $this->taskCountsBy($project, 'kanban_columns.status_key'),
                'by_risk' => $this->taskCountsBy($project, 'tasks.risk_level'),
            ],
            'wiki' => [
                'page_count' => DB::table('wiki_pages')->where('project_id', $project->id)->count(),
                'stale_count' => DB::table('wiki_pages')
                    ->where('project_id', $project->id)
                    ->whereIn('source_status', ['stale', 'conflict_with_code'])
                    ->count(),
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function taskCountsBy(object $project, string $column): array
    {
        return DB::table('tasks')
            ->join('kanban_columns', 'kanban_columns.id', '=', 'tasks.status_column_id')
            ->where('tasks.project_id', $project->id)
            ->select($column, DB::raw('count(*) as aggregate'))
            ->groupBy($column)
            ->pluck('aggregate', $column)
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @param array<string, mixed> $context
     * @return list<array<string, string>>
     */
    private function evidenceRefs(object $project, array $context): array
    {
        $refs = [
            ['type' => 'project', 'id' => (string) $project->id, 'source_status' => 'verified_from_code'],
        ];

        foreach (array_slice($context['tasks']['items'] ?? [], 0, 20) as $task) {
            if (is_array($task) && isset($task['id'])) {
                $refs[] = ['type' => 'task', 'id' => (string) $task['id'], 'source_status' => 'verified_from_code'];
            }
        }

        return $refs;
    }

    /**
     * @param array<string, mixed> $context
     * @return array{structured: array{summary: string, groups: list<array{label: string, task_ids: list<string>, reason: string}>, recommendations: list<array{title: string, body: string, task_ids: list<string>, priority: string}>, risks: list<string>, confidence: float}, prompt: string, execution_mode: string, external_provider_call: bool, model_provider_id: ?string, model_profile_id: ?string, provider_key: ?string, model_name: ?string, provider_failure: ?array<string, string>}
     */
    private function generateSuggestion(object $project, object $agentProfile, array $context): array
    {
        $prompt = $this->promptForContext($context);
        $modelProfile = $this->modelProfileForAgent($agentProfile);
        $shouldUseSdk = BacklogTriageAgent::isFaked() || $this->modelProfileCanCallProvider($modelProfile);

        if (! $shouldUseSdk) {
            return [
                'structured' => $this->structuredSuggestion($context),
                'prompt' => $prompt,
                'execution_mode' => 'deterministic_fallback',
                'external_provider_call' => false,
                'model_provider_id' => $modelProfile?->model_provider_id ? (string) $modelProfile->model_provider_id : null,
                'model_profile_id' => $modelProfile?->model_profile_id ? (string) $modelProfile->model_profile_id : null,
                'provider_key' => $modelProfile?->provider_key ? (string) $modelProfile->provider_key : null,
                'model_name' => $modelProfile?->model_name ? (string) $modelProfile->model_name : null,
                'provider_failure' => null,
            ];
        }

        if ($modelProfile) {
            $this->configureLaravelAiProvider($modelProfile);
        }

        try {
            $response = BacklogTriageAgent::make()->prompt(
                $prompt,
                provider: $modelProfile?->provider_key ? (string) $modelProfile->provider_key : null,
                model: $modelProfile?->model_name ? (string) $modelProfile->model_name : null,
                timeout: $modelProfile?->timeout_seconds ? (int) $modelProfile->timeout_seconds : null,
            );
        } catch (Throwable $exception) {
            return [
                'structured' => $this->structuredSuggestion($context),
                'prompt' => $prompt,
                'execution_mode' => 'provider_failed_fallback',
                'external_provider_call' => ! BacklogTriageAgent::isFaked(),
                'model_provider_id' => $modelProfile?->model_provider_id ? (string) $modelProfile->model_provider_id : null,
                'model_profile_id' => $modelProfile?->model_profile_id ? (string) $modelProfile->model_profile_id : null,
                'provider_key' => $modelProfile?->provider_key ? (string) $modelProfile->provider_key : null,
                'model_name' => $modelProfile?->model_name ? (string) $modelProfile->model_name : null,
                'provider_failure' => [
                    'class' => $exception::class,
                    'message' => Str::limit($exception->getMessage(), 500, ''),
                ],
            ];
        }

        return [
            'structured' => $this->normalizeStructuredSuggestion(
                $response instanceof StructuredAgentResponse
                    ? $response->structured
                    : json_decode($response->text, true) ?? [],
                $context,
            ),
            'prompt' => $prompt,
            'execution_mode' => BacklogTriageAgent::isFaked() ? 'laravel_ai_sdk_fake' : 'laravel_ai_sdk_provider',
            'external_provider_call' => ! BacklogTriageAgent::isFaked(),
            'model_provider_id' => $modelProfile?->model_provider_id ? (string) $modelProfile->model_provider_id : null,
            'model_profile_id' => $modelProfile?->model_profile_id ? (string) $modelProfile->model_profile_id : null,
            'provider_key' => $modelProfile?->provider_key ? (string) $modelProfile->provider_key : null,
            'model_name' => $modelProfile?->model_name ? (string) $modelProfile->model_name : null,
            'provider_failure' => null,
        ];
    }

    private function modelProfileForAgent(object $agentProfile): ?object
    {
        if (! $agentProfile->default_model_profile_id) {
            return null;
        }

        return DB::table('ai_model_profiles')
            ->join('ai_model_providers', 'ai_model_providers.id', '=', 'ai_model_profiles.provider_id')
            ->select([
                'ai_model_profiles.id as model_profile_id',
                'ai_model_profiles.model_name',
                'ai_model_profiles.timeout_seconds',
                'ai_model_profiles.enabled as model_profile_enabled',
                'ai_model_providers.id as model_provider_id',
                'ai_model_providers.provider_key',
                'ai_model_providers.provider_type',
                'ai_model_providers.base_url',
                'ai_model_providers.encrypted_api_key',
                'ai_model_providers.enabled as provider_enabled',
            ])
            ->where('ai_model_profiles.id', $agentProfile->default_model_profile_id)
            ->first();
    }

    private function modelProfileCanCallProvider(?object $modelProfile): bool
    {
        return $modelProfile
            && (bool) $modelProfile->model_profile_enabled
            && (bool) $modelProfile->provider_enabled
            && filled($modelProfile->encrypted_api_key);
    }

    private function configureLaravelAiProvider(object $modelProfile): void
    {
        $providerKey = (string) $modelProfile->provider_key;
        $current = config("ai.providers.{$providerKey}", ['driver' => $providerKey]);
        $driver = (string) ($modelProfile->provider_type === 'openai_compatible'
            ? 'openai'
            : ($current['driver'] ?? $providerKey));

        config([
            "ai.providers.{$providerKey}" => array_replace_recursive($current, [
                'driver' => $driver,
                'key' => filled($modelProfile->encrypted_api_key)
                    ? Crypt::decryptString((string) $modelProfile->encrypted_api_key)
                    : ($current['key'] ?? null),
                'url' => $modelProfile->base_url ?: ($current['url'] ?? null),
                'models' => [
                    'text' => [
                        'default' => (string) $modelProfile->model_name,
                    ],
                ],
            ]),
        ]);

        Ai::forgetInstance($providerKey);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function promptForContext(array $context): string
    {
        $encodedContext = json_encode(
            $context,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return <<<PROMPT
Review this DevBoard project backlog and produce a backlog_triage suggestion.

Use only the provided context. If a task id is not present in context, do not reference it. Do not mutate project, task, Kanban, wiki, run, or local-agent data. Return summary, groups, recommendations, risks, and confidence.

Context:
{$encodedContext}
PROMPT;
    }

    /**
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $context
     * @return array{summary: string, groups: list<array{label: string, task_ids: list<string>, reason: string}>, recommendations: list<array{title: string, body: string, task_ids: list<string>, priority: string}>, risks: list<string>, confidence: float}
     */
    private function normalizeStructuredSuggestion(array $structured, array $context): array
    {
        $fallback = $this->structuredSuggestion($context);
        $validTaskIds = $this->validTaskIds($context);

        return [
            'summary' => Str::limit(trim((string) ($structured['summary'] ?? $fallback['summary'])), 500, ''),
            'groups' => $this->groups($structured['groups'] ?? null, $fallback['groups'], $validTaskIds),
            'recommendations' => $this->recommendations($structured['recommendations'] ?? null, $fallback['recommendations'], $validTaskIds),
            'risks' => $this->stringList($structured['risks'] ?? null, $fallback['risks'], 6),
            'confidence' => round(max(0, min(1, (float) ($structured['confidence'] ?? $fallback['confidence']))), 2),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array{summary: string, groups: list<array{label: string, task_ids: list<string>, reason: string}>, recommendations: list<array{title: string, body: string, task_ids: list<string>, priority: string}>, risks: list<string>, confidence: float}
     */
    private function structuredSuggestion(array $context): array
    {
        $tasks = array_values(array_filter($context['tasks']['items'] ?? [], 'is_array'));
        $openCount = count($tasks);
        $vagueTasks = array_values(array_filter($tasks, fn (array $task): bool => $this->isVagueTask($task)));
        $highRiskTasks = array_values(array_filter($tasks, fn (array $task): bool => in_array((string) ($task['risk_level'] ?? 'low'), ['high', 'critical'], true)));
        $focusTasks = array_slice($vagueTasks !== [] ? $vagueTasks : $highRiskTasks, 0, 8);
        $focusTaskIds = array_values(array_map(fn (array $task): string => (string) $task['id'], $focusTasks));

        if ($openCount === 0) {
            return [
                'summary' => 'The project has no open backlog tasks to triage.',
                'groups' => [[
                    'label' => 'No open backlog work',
                    'task_ids' => [],
                    'reason' => 'No non-done tasks were present in the project context.',
                ]],
                'recommendations' => [[
                    'title' => 'Keep backlog empty before planning',
                    'body' => 'No backlog action is required until new work is created or moved out of done.',
                    'task_ids' => [],
                    'priority' => 'low',
                ]],
                'risks' => [],
                'confidence' => 0.86,
            ];
        }

        return [
            'summary' => "The project has {$openCount} open task(s); ".count($vagueTasks).' need clearer wording and '.count($highRiskTasks).' carry high risk.',
            'groups' => [[
                'label' => $vagueTasks !== [] ? 'Needs clarification' : 'Risk review',
                'task_ids' => $focusTaskIds,
                'reason' => $vagueTasks !== []
                    ? 'These tasks have short or underspecified descriptions that can lead to divergent implementation choices.'
                    : 'These tasks carry elevated risk and should be reviewed before developers pull more work.',
            ]],
            'recommendations' => [[
                'title' => $vagueTasks !== [] ? 'Clarify vague backlog tasks' : 'Review high-risk backlog tasks',
                'body' => $vagueTasks !== []
                    ? 'Ask PMs to add observable acceptance criteria, affected workflow, and done/not-done checks before development starts.'
                    : 'Confirm risk drivers, expected mitigation, and owner before moving these tasks forward.',
                'task_ids' => $focusTaskIds,
                'priority' => ($vagueTasks !== [] || $highRiskTasks !== []) ? 'high' : 'normal',
            ]],
            'risks' => $vagueTasks !== []
                ? ['Developers may interpret vague backlog items differently and produce inconsistent implementation scope.']
                : ['The backlog is understandable, but high-risk tasks still need explicit review before execution.'],
            'confidence' => $vagueTasks !== [] ? 0.78 : 0.82,
        ];
    }

    /**
     * @param array<string, mixed> $task
     */
    private function isVagueTask(array $task): bool
    {
        $description = trim((string) ($task['description_excerpt'] ?? ''));

        return $description === '' || strlen($description) < 60 || ! str_contains(strtolower($description), 'when');
    }

    /**
     * @param array<string, mixed> $context
     * @return list<string>
     */
    private function validTaskIds(array $context): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $task): ?string => is_array($task) && isset($task['id']) ? (string) $task['id'] : null,
            $context['tasks']['items'] ?? [],
        )));
    }

    /**
     * @param list<array{label: string, task_ids: list<string>, reason: string}> $fallback
     * @param list<string> $validTaskIds
     * @return list<array{label: string, task_ids: list<string>, reason: string}>
     */
    private function groups(mixed $value, array $fallback, array $validTaskIds): array
    {
        if (! is_array($value)) {
            return $fallback;
        }

        $groups = [];

        foreach (array_slice($value, 0, 6) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $label = trim((string) ($item['label'] ?? ''));
            $reason = trim((string) ($item['reason'] ?? ''));

            if ($label === '' || $reason === '') {
                continue;
            }

            $groups[] = [
                'label' => Str::limit($label, 120, ''),
                'task_ids' => $this->taskIds($item['task_ids'] ?? [], $validTaskIds),
                'reason' => Str::limit($reason, 360, ''),
            ];
        }

        return $groups === [] ? $fallback : $groups;
    }

    /**
     * @param list<array{title: string, body: string, task_ids: list<string>, priority: string}> $fallback
     * @param list<string> $validTaskIds
     * @return list<array{title: string, body: string, task_ids: list<string>, priority: string}>
     */
    private function recommendations(mixed $value, array $fallback, array $validTaskIds): array
    {
        if (! is_array($value)) {
            return $fallback;
        }

        $recommendations = [];

        foreach (array_slice($value, 0, 8) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = trim((string) ($item['title'] ?? ''));
            $body = trim((string) ($item['body'] ?? ''));

            if ($title === '' || $body === '') {
                continue;
            }

            $priority = (string) ($item['priority'] ?? 'normal');

            $recommendations[] = [
                'title' => Str::limit($title, 160, ''),
                'body' => Str::limit($body, 500, ''),
                'task_ids' => $this->taskIds($item['task_ids'] ?? [], $validTaskIds),
                'priority' => in_array($priority, ['low', 'normal', 'high'], true) ? $priority : 'normal',
            ];
        }

        return $recommendations === [] ? $fallback : $recommendations;
    }

    /**
     * @param list<string> $validTaskIds
     * @return list<string>
     */
    private function taskIds(mixed $value, array $validTaskIds): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_slice(array_values(array_intersect(
            array_values(array_map(fn (mixed $item): string => (string) $item, $value)),
            $validTaskIds,
        )), 0, 12);
    }

    /**
     * @param list<string> $fallback
     * @return list<string>
     */
    private function stringList(mixed $value, array $fallback, int $limit): array
    {
        if (! is_array($value)) {
            return array_slice($fallback, 0, $limit);
        }

        $items = array_values(array_filter(array_map(
            fn (mixed $item): string => trim((string) $item),
            $value,
        )));

        return $items === [] ? array_slice($fallback, 0, $limit) : array_slice($items, 0, $limit);
    }

    /**
     * @param array{summary: string, groups: list<array{label: string, task_ids: list<string>, reason: string}>, recommendations: list<array{title: string, body: string, task_ids: list<string>, priority: string}>, risks: list<string>} $structured
     */
    private function bodyMarkdown(array $structured): string
    {
        $groups = implode("\n", array_map(
            fn (array $group): string => '- '.$group['label'].': '.$group['reason'].$this->taskSuffix($group['task_ids']),
            $structured['groups'],
        ));
        $recommendations = implode("\n", array_map(
            fn (array $recommendation): string => '- '.$recommendation['title'].' ['.$recommendation['priority'].']: '.$recommendation['body'].$this->taskSuffix($recommendation['task_ids']),
            $structured['recommendations'],
        ));
        $risks = $structured['risks'] === []
            ? '- None.'
            : implode("\n", array_map(fn (string $risk): string => "- {$risk}", $structured['risks']));

        return "### Summary\n{$structured['summary']}\n\n### Groups\n{$groups}\n\n### Recommendations\n{$recommendations}\n\n### Risks\n{$risks}";
    }

    /**
     * @param list<string> $taskIds
     */
    private function taskSuffix(array $taskIds): string
    {
        return $taskIds === [] ? '' : ' Tasks: '.implode(', ', $taskIds).'.';
    }

    /**
     * @return array<string, mixed>
     */
    private function runPayload(string $runId): array
    {
        $run = DB::table('assistant_runs')
            ->leftJoin('ai_agent_profiles', 'ai_agent_profiles.id', '=', 'assistant_runs.agent_profile_id')
            ->select([
                'assistant_runs.id',
                'assistant_runs.project_id',
                'assistant_runs.target_type',
                'assistant_runs.target_id',
                'assistant_runs.status',
                'assistant_runs.context_hash',
                'assistant_runs.result_summary',
                'assistant_runs.started_at',
                'assistant_runs.finished_at',
                'ai_agent_profiles.agent_key',
            ])
            ->where('assistant_runs.id', $runId)
            ->first();

        return [
            'id' => (string) $run->id,
            'project_id' => (string) $run->project_id,
            'agent_key' => (string) $run->agent_key,
            'target_type' => (string) $run->target_type,
            'target_id' => (string) $run->target_id,
            'status' => (string) $run->status,
            'context_hash' => (string) $run->context_hash,
            'result_summary' => (string) $run->result_summary,
            'started_at' => (string) $run->started_at,
            'finished_at' => $run->finished_at ? (string) $run->finished_at : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function suggestionPayload(string $suggestionId): array
    {
        $suggestion = DB::table('assistant_suggestions')->where('id', $suggestionId)->first();

        return [
            'id' => (string) $suggestion->id,
            'assistant_run_id' => (string) $suggestion->assistant_run_id,
            'project_id' => (string) $suggestion->project_id,
            'target_type' => (string) $suggestion->target_type,
            'target_id' => (string) $suggestion->target_id,
            'suggestion_type' => (string) $suggestion->suggestion_type,
            'title' => (string) $suggestion->title,
            'body_markdown' => (string) $suggestion->body_markdown,
            'structured_payload' => json_decode((string) $suggestion->structured_payload, true, flags: JSON_THROW_ON_ERROR),
            'evidence_refs' => json_decode((string) $suggestion->evidence_refs, true, flags: JSON_THROW_ON_ERROR),
            'confidence' => (float) $suggestion->confidence,
            'approval_required' => (bool) $suggestion->approval_required,
            'status' => (string) $suggestion->status,
            'resolved_by_user_id' => $suggestion->resolved_by_user_id ? (string) $suggestion->resolved_by_user_id : null,
            'resolved_at' => $suggestion->resolved_at ? (string) $suggestion->resolved_at : null,
            'created_at' => (string) $suggestion->created_at,
            'updated_at' => (string) $suggestion->updated_at,
        ];
    }
}
