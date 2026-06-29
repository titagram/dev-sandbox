<?php

namespace App\Assistants\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

final class ReadProjectTasksTool implements Tool
{
    public function name(): string
    {
        return 'read_project_tasks';
    }

    public function description(): Stringable|string
    {
        return 'Read a bounded list of DevBoard project tasks with status, priority, risk, owner, due date, and description excerpts. Does not mutate task or Kanban state.';
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
        $projectId = (string) ($arguments['project_id'] ?? '');
        $limit = min(50, max(1, (int) ($arguments['limit'] ?? 25)));
        $statusKeys = $this->statusKeys($arguments['status_keys'] ?? null);

        $project = DB::table('projects')
            ->where('id', $projectId)
            ->where('status', '!=', 'deleted')
            ->first();

        if (! $project) {
            return [
                'tool' => $this->name(),
                'source_status' => 'verified_from_code',
                'found' => false,
                'reason' => 'project_not_found_or_deleted',
                'tasks' => [],
            ];
        }

        $query = DB::table('tasks')
            ->join('kanban_columns', 'kanban_columns.id', '=', 'tasks.status_column_id')
            ->leftJoin('users as owners', 'owners.id', '=', 'tasks.owner_user_id')
            ->where('tasks.project_id', $projectId)
            ->select([
                'tasks.id',
                'tasks.title',
                'tasks.description',
                'tasks.priority',
                'tasks.risk_level',
                'tasks.due_at',
                'tasks.created_at',
                'tasks.updated_at',
                'owners.id as owner_id',
                'owners.name as owner_name',
                'kanban_columns.name as status_name',
                'kanban_columns.status_key',
                'kanban_columns.position',
            ]);

        if ($statusKeys !== []) {
            $query->whereIn('kanban_columns.status_key', $statusKeys);
        }

        $tasks = $query
            ->orderBy('kanban_columns.position')
            ->orderByRaw("case tasks.risk_level when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
            ->orderByDesc('tasks.updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (object $task): array => [
                'id' => (string) $task->id,
                'title' => (string) $task->title,
                'description_excerpt' => Str::limit((string) ($task->description ?? ''), 260, ''),
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
            ])
            ->all();

        return [
            'tool' => $this->name(),
            'source_status' => 'verified_from_code',
            'found' => true,
            'project' => [
                'id' => (string) $project->id,
                'name' => (string) $project->name,
                'slug' => (string) $project->slug,
                'status' => (string) $project->status,
            ],
            'limit' => $limit,
            'status_keys' => $statusKeys,
            'tasks' => $tasks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()
                ->description('The DevBoard project ULID whose tasks should be read.')
                ->required(),
            'status_keys' => $schema->array()
                ->description('Optional Kanban status keys to include.')
                ->items($schema->string()->enum(['backlog', 'ready', 'in_progress', 'blocked', 'review', 'done']))
                ->min(0)
                ->max(6),
            'limit' => $schema->integer()
                ->description('Maximum number of tasks to return, capped at 50.')
                ->min(1)
                ->max(50),
        ];
    }

    /**
     * @return list<string>
     */
    private function statusKeys(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $allowed = ['backlog', 'ready', 'in_progress', 'blocked', 'review', 'done'];

        return array_values(array_intersect(
            array_values(array_filter(array_map(fn (mixed $item): string => (string) $item, $value))),
            $allowed,
        ));
    }
}
