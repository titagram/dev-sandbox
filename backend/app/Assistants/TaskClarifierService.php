<?php

namespace App\Assistants;

use App\Assistants\Agents\TaskClarifierAgent;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

final class TaskClarifierService
{
    public function __construct(private readonly ProviderHttpClient $httpClient)
    {
    }

    /**
     * @return array{run: array<string, mixed>, suggestion: array<string, mixed>}
     */
    public function clarify(string $taskId, int $userId): array
    {
        $task = $this->task($taskId);
        $agent = DB::table('ai_agent_profiles')->where('agent_key', 'task_clarifier')->first();
        abort_unless($agent, 500, 'Task Clarifier agent profile is not configured.');

        $context = $this->contextSnapshot($task);
        $execution = $this->generateSuggestion($task, $agent, $context);
        $structured = $execution['structured'];
        $evidenceRefs = [
            ['type' => 'task', 'id' => (string) $task->id, 'source_status' => 'verified_from_code'],
            ['type' => 'project', 'id' => (string) $task->project_id, 'source_status' => 'verified_from_code'],
        ];
        $body = $this->bodyMarkdown($structured);
        $now = now();
        $runId = (string) Str::ulid();
        $suggestionId = (string) Str::ulid();

        DB::transaction(function () use ($agent, $body, $context, $evidenceRefs, $execution, $runId, $structured, $suggestionId, $task, $userId, $now): void {
            DB::table('tasks')->where('id', $task->id)->lockForUpdate()->value('id');

            DB::table('assistant_runs')->insert([
                'id' => $runId,
                'project_id' => $task->project_id,
                'agent_profile_id' => $agent->id,
                'target_type' => 'task',
                'target_id' => $task->id,
                'triggered_by_user_id' => $userId,
                'status' => 'completed',
                'model_provider_id' => $execution['model_provider_id'],
                'model_profile_id' => $execution['model_profile_id'],
                'context_hash' => hash('sha256', json_encode($context, JSON_THROW_ON_ERROR)),
                'context_snapshot' => json_encode($context, JSON_THROW_ON_ERROR),
                'result_summary' => 'Task clarification suggestion created.',
                'metadata' => json_encode([
                    'execution_mode' => $execution['execution_mode'],
                    'external_provider_call' => $execution['external_provider_call'],
                    'provider_key' => $execution['provider_key'],
                    'model_name' => $execution['model_name'],
                    'sdk_agent' => TaskClarifierAgent::class,
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

            $this->supersedePendingTaskClarificationSuggestions($task, $userId, $runId, $suggestionId, $now);

            DB::table('assistant_suggestions')->insert([
                'id' => $suggestionId,
                'assistant_run_id' => $runId,
                'project_id' => $task->project_id,
                'target_type' => 'task',
                'target_id' => $task->id,
                'suggestion_type' => 'task_clarification',
                'title' => 'Clarify task before development',
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

            app(AuditLogger::class)->record('assistant.task_clarification.created', 'task', $task->id, [
                    'assistant_run_id' => $runId,
                    'assistant_suggestion_id' => $suggestionId,
                    'agent_key' => 'task_clarifier',
                    'external_provider_call' => $execution['external_provider_call'],
                    'execution_mode' => $execution['execution_mode'],
            ], ['type' => 'user', 'user_id' => $userId]);
        });

        return [
            'run' => $this->runPayload($runId),
            'suggestion' => $this->suggestionPayload($suggestionId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function latestSuggestionForTask(string $taskId): ?array
    {
        $suggestion = DB::table('assistant_suggestions')
            ->where('target_type', 'task')
            ->where('target_id', $taskId)
            ->where('suggestion_type', 'task_clarification')
            ->orderByDesc('created_at')
            ->first();

        return $suggestion ? $this->suggestionPayload((string) $suggestion->id) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveSuggestion(string $suggestionId, int $userId, string $status): array
    {
        abort_unless(in_array($status, ['accepted', 'rejected'], true), 422);

        DB::transaction(function () use ($status, $suggestionId, $userId): void {
            $suggestion = DB::table('assistant_suggestions')
                ->where('id', $suggestionId)
                ->where('suggestion_type', 'task_clarification')
                ->where('target_type', 'task')
                ->lockForUpdate()
                ->first();

            abort_unless($suggestion, 404);
            abort_if($suggestion->status !== 'pending', 409, 'Assistant suggestion is already resolved.');

            $now = now();

            DB::table('assistant_suggestions')
                ->where('id', $suggestionId)
                ->update([
                    'status' => $status,
                    'resolved_by_user_id' => $userId,
                    'resolved_at' => $now,
                    'updated_at' => $now,
                ]);

            app(AuditLogger::class)->record("assistant.suggestion.{$status}", 'assistant_suggestion', $suggestionId, [
                    'assistant_run_id' => (string) $suggestion->assistant_run_id,
                    'project_id' => (string) $suggestion->project_id,
                    'target_type' => (string) $suggestion->target_type,
                    'target_id' => (string) $suggestion->target_id,
                    'suggestion_type' => (string) $suggestion->suggestion_type,
                    'status' => $status,
                    'mutated_target' => false,
            ], ['type' => 'user', 'user_id' => $userId]);
        });

        return $this->suggestionPayload($suggestionId);
    }

    /**
     * @return array{suggestion: array<string, mixed>, task_id: string}
     */
    public function applySuggestion(string $suggestionId, int $userId): array
    {
        $taskId = null;

        DB::transaction(function () use ($suggestionId, $userId, &$taskId): void {
            $suggestion = DB::table('assistant_suggestions')
                ->where('id', $suggestionId)
                ->where('suggestion_type', 'task_clarification')
                ->where('target_type', 'task')
                ->lockForUpdate()
                ->first();

            abort_unless($suggestion, 404);
            abort_if($suggestion->status !== 'accepted', 409, 'Assistant suggestion must be accepted before it can be applied.');

            $task = DB::table('tasks')
                ->where('id', $suggestion->target_id)
                ->lockForUpdate()
                ->first();

            abort_unless($task, 404);

            $now = now();
            $previousDescription = (string) ($task->description ?? '');
            $appliedBlock = $this->appliedDescriptionBlock(
                json_decode((string) $suggestion->structured_payload, true, flags: JSON_THROW_ON_ERROR),
            );
            $newDescription = trim($previousDescription) === ''
                ? $appliedBlock
                : trim($previousDescription)."\n\n{$appliedBlock}";

            DB::table('tasks')
                ->where('id', $task->id)
                ->update([
                    'description' => $newDescription,
                    'updated_at' => $now,
                ]);

            DB::table('assistant_suggestions')
                ->where('id', $suggestionId)
                ->update([
                    'status' => 'applied',
                    'updated_at' => $now,
                ]);

            app(AuditLogger::class)->record('assistant.suggestion.applied', 'assistant_suggestion', $suggestionId, [
                    'assistant_run_id' => (string) $suggestion->assistant_run_id,
                    'project_id' => (string) $suggestion->project_id,
                    'target_type' => 'task',
                    'target_id' => (string) $task->id,
                    'suggestion_type' => (string) $suggestion->suggestion_type,
                    'status' => 'applied',
                    'mutated_target' => true,
                    'applied_fields' => ['description'],
                    'previous_description_sha256' => hash('sha256', $previousDescription),
                    'new_description_sha256' => hash('sha256', $newDescription),
            ], ['type' => 'user', 'user_id' => $userId]);

            $taskId = (string) $task->id;
        });

        return [
            'suggestion' => $this->suggestionPayload($suggestionId),
            'task_id' => (string) $taskId,
        ];
    }

    private function supersedePendingTaskClarificationSuggestions(object $task, int $userId, string $runId, string $suggestionId, Carbon $now): void
    {
        $pendingSuggestions = DB::table('assistant_suggestions')
            ->where('project_id', $task->project_id)
            ->where('target_type', 'task')
            ->where('target_id', $task->id)
            ->where('suggestion_type', 'task_clarification')
            ->where('status', 'pending')
            ->lockForUpdate()
            ->get([
                'id',
                'assistant_run_id',
                'project_id',
                'target_type',
                'target_id',
                'suggestion_type',
            ]);

        if ($pendingSuggestions->isEmpty()) {
            return;
        }

        DB::table('assistant_suggestions')
            ->whereIn('id', $pendingSuggestions->pluck('id')->all())
            ->update([
                'status' => 'superseded',
                'resolved_by_user_id' => $userId,
                'resolved_at' => $now,
                'updated_at' => $now,
            ]);

        app(AuditLogger::class)->recordMany(
            $pendingSuggestions->map(fn (object $suggestion): array => [
                'action' => 'assistant.suggestion.superseded',
                'target_type' => 'assistant_suggestion',
                'target_id' => (string) $suggestion->id,
                'payload' => [
                    'assistant_run_id' => (string) $suggestion->assistant_run_id,
                    'project_id' => (string) $suggestion->project_id,
                    'target_type' => (string) $suggestion->target_type,
                    'target_id' => (string) $suggestion->target_id,
                    'suggestion_type' => (string) $suggestion->suggestion_type,
                    'status' => 'superseded',
                    'superseded_by_assistant_run_id' => $runId,
                    'superseded_by_assistant_suggestion_id' => $suggestionId,
                    'mutated_target' => false,
                ],
                'actor' => ['type' => 'user', 'user_id' => $userId],
            ])->all()
        );
    }

    private function task(string $taskId): object
    {
        $task = DB::table('tasks')
            ->join('projects', 'projects.id', '=', 'tasks.project_id')
            ->leftJoin('users as owners', 'owners.id', '=', 'tasks.owner_user_id')
            ->leftJoin('kanban_columns', 'kanban_columns.id', '=', 'tasks.status_column_id')
            ->select([
                'tasks.id',
                'tasks.project_id',
                'tasks.title',
                'tasks.description',
                'tasks.priority',
                'tasks.risk_level',
                'tasks.created_at',
                'tasks.updated_at',
                'projects.name as project_name',
                'projects.status as project_status',
                'owners.name as owner_name',
                'kanban_columns.status_key',
            ])
            ->where('tasks.id', $taskId)
            ->first();

        abort_unless($task && $task->project_status !== 'deleted', 404);

        return $task;
    }

    /**
     * @return array<string, mixed>
     */
    private function contextSnapshot(object $task): array
    {
        return [
            'source_status' => 'verified_from_code',
            'context_kind' => 'task_clarification',
            'task' => [
                'id' => (string) $task->id,
                'title' => (string) $task->title,
                'description' => (string) ($task->description ?? ''),
                'priority' => (string) $task->priority,
                'risk_level' => (string) $task->risk_level,
                'status_key' => (string) $task->status_key,
                'owner_name' => (string) ($task->owner_name ?? 'Unassigned'),
            ],
            'project' => [
                'id' => (string) $task->project_id,
                'name' => (string) $task->project_name,
            ],
            'wiki' => [
                'page_count' => DB::table('wiki_pages')->where('project_id', $task->project_id)->count(),
                'stale_count' => DB::table('wiki_pages')
                    ->where('project_id', $task->project_id)
                    ->whereIn('source_status', ['stale', 'conflict_with_code'])
                    ->count(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array{structured: array{questions: list<string>, acceptance_criteria: list<string>, risks: list<string>, missing_context: list<string>, confidence: float}, prompt: string, execution_mode: string, external_provider_call: bool, model_provider_id: ?string, model_profile_id: ?string, provider_key: ?string, model_name: ?string, provider_failure: ?array<string, string>}
     */
    private function generateSuggestion(object $task, object $agentProfile, array $context): array
    {
        $prompt = $this->promptForContext($context);
        $modelProfile = $this->modelProfileForAgent($agentProfile);
        $shouldUseProvider = $this->modelProfileCanCallProvider($modelProfile);

        if (! TaskClarifierAgent::isFaked() && ! $shouldUseProvider) {
            return [
                'structured' => $this->structuredSuggestion($task),
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

        if (TaskClarifierAgent::isFaked() && $modelProfile) {
            $this->configureLaravelAiProvider($modelProfile);
        }

        try {
            $response = TaskClarifierAgent::isFaked()
                ? TaskClarifierAgent::make()->prompt(
                    $prompt,
                    provider: $modelProfile?->provider_key ? (string) $modelProfile->provider_key : null,
                    model: $modelProfile?->model_name ? (string) $modelProfile->model_name : null,
                    timeout: $modelProfile?->timeout_seconds ? (int) $modelProfile->timeout_seconds : null,
                )
                : $this->callProvider($modelProfile, $prompt);
        } catch (Throwable $exception) {
            return [
                'structured' => $this->structuredSuggestion($task),
                'prompt' => $prompt,
                'execution_mode' => 'provider_failed_fallback',
                'external_provider_call' => ! TaskClarifierAgent::isFaked(),
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
                $task,
            ),
            'prompt' => $prompt,
            'execution_mode' => TaskClarifierAgent::isFaked() ? 'laravel_ai_sdk_fake' : 'laravel_ai_sdk_provider',
            'external_provider_call' => ! TaskClarifierAgent::isFaked(),
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
            && (string) $modelProfile->provider_type === 'openai_compatible'
            && filled($modelProfile->encrypted_api_key)
            && app(ProviderEndpointPolicy::class)->isAllowed($this->responsesEndpoint((string) ($modelProfile->base_url ?: 'https://api.openai.com/v1')));
    }

    private function callProvider(object $modelProfile, string $prompt): object
    {
        $apiKey = Crypt::decryptString((string) $modelProfile->encrypted_api_key);
        $response = $this->httpClient
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout((int) ($modelProfile->timeout_seconds ?: 60))
            ->post($this->responsesEndpoint((string) ($modelProfile->base_url ?: 'https://api.openai.com/v1')), [
                'model' => (string) $modelProfile->model_name,
                'instructions' => (string) TaskClarifierAgent::make()->instructions(),
                'input' => $prompt,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('HTTP request returned status code '.$response->status().'.');
        }

        return (object) ['text' => $this->extractProviderText($response->json())];
    }

    private function responsesEndpoint(string $baseUrl): string
    {
        $base = rtrim($baseUrl, '/');
        $base = preg_replace('#/(responses|chat/completions|models)$#', '', $base) ?: $base;

        return $base.'/responses';
    }

    private function extractProviderText(mixed $payload): string
    {
        if (! is_array($payload)) {
            return '';
        }

        foreach (['output_text', 'choices.0.message.content', 'output.0.content.0.text'] as $path) {
            $text = data_get($payload, $path);
            if (is_string($text) && trim($text) !== '') {
                return trim($text);
            }
        }

        return '';
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
Review this DevBoard task draft and produce a task_clarification suggestion.

Use only the provided context. If something is not present, list it as missing context. Do not mutate task data and do not propose implementation code.

Context:
{$encodedContext}

Return questions, acceptance_criteria, risks, missing_context, and confidence.
PROMPT;
    }

    /**
     * @param array<string, mixed> $structured
     * @return array{questions: list<string>, acceptance_criteria: list<string>, risks: list<string>, missing_context: list<string>, confidence: float}
     */
    private function normalizeStructuredSuggestion(array $structured, object $task): array
    {
        $fallback = $this->structuredSuggestion($task);

        return [
            'questions' => $this->stringList($structured['questions'] ?? null, $fallback['questions'], 6),
            'acceptance_criteria' => $this->stringList($structured['acceptance_criteria'] ?? null, $fallback['acceptance_criteria'], 8),
            'risks' => $this->stringList($structured['risks'] ?? null, $fallback['risks'], 6),
            'missing_context' => $this->stringList($structured['missing_context'] ?? null, $fallback['missing_context'], 8),
            'confidence' => round(max(0, min(1, (float) ($structured['confidence'] ?? $fallback['confidence']))), 2),
        ];
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
     * @return array{questions: list<string>, acceptance_criteria: list<string>, risks: list<string>, missing_context: list<string>, confidence: float}
     */
    private function structuredSuggestion(object $task): array
    {
        $description = trim((string) ($task->description ?? ''));
        $isVague = strlen($description) < 40 || ! str_contains(strtolower($description), 'when');
        $missingContext = ['acceptance_criteria', 'repository_scope'];

        if ($isVague) {
            $missingContext[] = 'user_outcome';
        }

        return [
            'questions' => [
                'Which user or role needs this outcome?',
                'What observable behavior proves this task is complete?',
                'Which repository, product area, or workflow is in scope?',
            ],
            'acceptance_criteria' => [
                'The task states the user-visible outcome in one sentence.',
                'The task includes done/not-done checks that a developer can verify.',
                'The task names the affected area or explicitly says the area is unknown.',
            ],
            'risks' => $isVague
                ? ['The current wording can lead to divergent developer interpretations.']
                : ['The task is understandable but still needs explicit acceptance criteria.'],
            'missing_context' => array_values(array_unique($missingContext)),
            'confidence' => $isVague ? 0.72 : 0.84,
        ];
    }

    /**
     * @param array{questions: list<string>, acceptance_criteria: list<string>, risks: list<string>, missing_context: list<string>} $structured
     */
    private function bodyMarkdown(array $structured): string
    {
        $questions = implode("\n", array_map(fn (string $question): string => "- {$question}", $structured['questions']));
        $criteria = implode("\n", array_map(fn (string $criterion): string => "- {$criterion}", $structured['acceptance_criteria']));
        $risks = implode("\n", array_map(fn (string $risk): string => "- {$risk}", $structured['risks']));
        $missingContext = implode("\n", array_map(fn (string $item): string => "- {$item}", $structured['missing_context']));

        return "### Questions\n{$questions}\n\n### Suggested acceptance criteria\n{$criteria}\n\n### Risks\n{$risks}\n\n### Missing context\n{$missingContext}";
    }

    /**
     * @param array<string, mixed> $structured
     */
    private function appliedDescriptionBlock(array $structured): string
    {
        $questions = $this->stringList($structured['questions'] ?? null, [], 6);
        $criteria = $this->stringList($structured['acceptance_criteria'] ?? null, [], 8);
        $risks = $this->stringList($structured['risks'] ?? null, [], 6);
        $missingContext = $this->stringList($structured['missing_context'] ?? null, [], 8);

        return "## Assistant clarification\n\n"
            .'### Questions'."\n".$this->markdownList($questions)."\n\n"
            .'### Acceptance criteria'."\n".$this->markdownList($criteria)."\n\n"
            .'### Risks'."\n".$this->markdownList($risks)."\n\n"
            .'### Missing context'."\n".$this->markdownList($missingContext);
    }

    /**
     * @param list<string> $items
     */
    private function markdownList(array $items): string
    {
        if ($items === []) {
            return '- None.';
        }

        return implode("\n", array_map(fn (string $item): string => "- {$item}", $items));
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
