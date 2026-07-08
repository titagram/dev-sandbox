<?php

namespace App\Assistants\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

final class ReadTaskDetailTool implements Tool
{
    public function name(): string
    {
        return 'read_task_detail';
    }

    public function description(): Stringable|string
    {
        return 'Read a single DevBoard task with project, owner, status, and latest assistant suggestion metadata. Does not mutate task or Kanban state.';
    }

    public function handle(Request $request): Stringable|string
    {
        return json_encode($this->payload($request->all()), JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(array $arguments): array
    {
        $taskId = (string) ($arguments['task_id'] ?? '');
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
                'tasks.due_at',
                'tasks.created_at',
                'tasks.updated_at',
                'projects.name as project_name',
                'projects.slug as project_slug',
                'projects.status as project_status',
                'owners.id as owner_id',
                'owners.name as owner_name',
                'kanban_columns.name as status_name',
                'kanban_columns.status_key',
            ])
            ->where('tasks.id', $taskId)
            ->where('projects.status', '!=', 'deleted')
            ->first();

        if (! $task) {
            return [
                'tool' => $this->name(),
                'source_status' => 'verified_from_code',
                'found' => false,
                'reason' => 'task_not_found_or_project_deleted',
            ];
        }

        return [
            'tool' => $this->name(),
            'source_status' => 'verified_from_code',
            'found' => true,
            'task' => [
                'id' => (string) $task->id,
                'title' => (string) $task->title,
                'description' => $task->description ? (string) $task->description : null,
                'priority' => (string) $task->priority,
                'risk_level' => (string) $task->risk_level,
                'status_key' => (string) $task->status_key,
                'status_name' => (string) $task->status_name,
                'owner' => $task->owner_id ? [
                    'id' => (string) $task->owner_id,
                    'name' => (string) $task->owner_name,
                ] : null,
                'due_at' => $task->due_at ? (string) $task->due_at : null,
                'created_at' => (string) $task->created_at,
                'updated_at' => (string) $task->updated_at,
            ],
            'project' => [
                'id' => (string) $task->project_id,
                'name' => (string) $task->project_name,
                'slug' => (string) $task->project_slug,
                'status' => (string) $task->project_status,
            ],
            'latest_assistant_suggestion' => $this->latestSuggestion((string) $task->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->string()
                ->description('The DevBoard task ULID to read.')
                ->required(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestSuggestion(string $taskId): ?array
    {
        $suggestion = DB::table('assistant_suggestions')
            ->where('target_type', 'task')
            ->where('target_id', $taskId)
            ->orderByDesc('created_at')
            ->first(['id', 'suggestion_type', 'title', 'status', 'confidence', 'created_at', 'resolved_at']);

        if (! $suggestion) {
            return null;
        }

        return [
            'id' => (string) $suggestion->id,
            'suggestion_type' => (string) $suggestion->suggestion_type,
            'title' => (string) $suggestion->title,
            'status' => (string) $suggestion->status,
            'confidence' => (float) $suggestion->confidence,
            'created_at' => (string) $suggestion->created_at,
            'resolved_at' => $suggestion->resolved_at ? (string) $suggestion->resolved_at : null,
        ];
    }
}
