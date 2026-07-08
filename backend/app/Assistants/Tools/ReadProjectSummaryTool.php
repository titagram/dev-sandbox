<?php

namespace App\Assistants\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

final class ReadProjectSummaryTool implements Tool
{
    public function name(): string
    {
        return 'read_project_summary';
    }

    public function description(): Stringable|string
    {
        return 'Read a bounded DevBoard project summary: project metadata, repository counts, task counts, wiki freshness, and recent run metadata.';
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
        $project = DB::table('projects')
            ->where('id', $projectId)
            ->where('status', '!=', 'deleted')
            ->first();

        if (! $project) {
            return $this->notFound();
        }

        return [
            'tool' => $this->name(),
            'source_status' => 'verified_from_code',
            'found' => true,
            'project' => [
                'id' => (string) $project->id,
                'name' => (string) $project->name,
                'slug' => (string) $project->slug,
                'description' => $project->description ? (string) $project->description : null,
                'status' => (string) $project->status,
                'default_code_exposure_policy' => (string) $project->default_code_exposure_policy,
                'updated_at' => (string) $project->updated_at,
            ],
            'repositories' => $this->repositories((string) $project->id),
            'tasks' => $this->tasks((string) $project->id),
            'wiki' => $this->wiki((string) $project->id),
            'recent_runs' => $this->recentRuns((string) $project->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()
                ->description('The DevBoard project ULID to summarize.')
                ->required(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notFound(): array
    {
        return [
            'tool' => $this->name(),
            'source_status' => 'verified_from_code',
            'found' => false,
            'reason' => 'project_not_found_or_deleted',
        ];
    }

    /**
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    private function repositories(string $projectId): array
    {
        $repositories = DB::table('repositories')
            ->where('project_id', $projectId)
            ->orderBy('name')
            ->limit(10)
            ->get([
                'id',
                'name',
                'slug',
                'default_branch',
                'local_only',
                'code_exposure_policy',
                'graph_enabled',
            ]);

        return [
            'total' => DB::table('repositories')->where('project_id', $projectId)->count(),
            'items' => $repositories->map(fn (object $repository): array => [
                'id' => (string) $repository->id,
                'name' => (string) $repository->name,
                'slug' => (string) $repository->slug,
                'default_branch' => (string) $repository->default_branch,
                'local_only' => (bool) $repository->local_only,
                'code_exposure_policy' => (string) $repository->code_exposure_policy,
                'graph_enabled' => (bool) $repository->graph_enabled,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tasks(string $projectId): array
    {
        $byStatus = DB::table('kanban_columns')
            ->join('kanban_boards', 'kanban_boards.id', '=', 'kanban_columns.board_id')
            ->leftJoin('tasks', function ($join) use ($projectId): void {
                $join->on('tasks.status_column_id', '=', 'kanban_columns.id')
                    ->where('tasks.project_id', '=', $projectId);
            })
            ->where('kanban_boards.project_id', $projectId)
            ->groupBy('kanban_columns.id', 'kanban_columns.status_key', 'kanban_columns.name', 'kanban_columns.position')
            ->orderBy('kanban_columns.position')
            ->get([
                'kanban_columns.status_key',
                'kanban_columns.name',
                DB::raw('count(tasks.id) as task_count'),
            ])
            ->map(fn (object $row): array => [
                'status_key' => (string) $row->status_key,
                'name' => (string) $row->name,
                'count' => (int) $row->task_count,
            ])
            ->all();

        $byRisk = DB::table('tasks')
            ->where('project_id', $projectId)
            ->select(['risk_level', DB::raw('count(*) as task_count')])
            ->groupBy('risk_level')
            ->orderBy('risk_level')
            ->get()
            ->map(fn (object $row): array => [
                'risk_level' => (string) $row->risk_level,
                'count' => (int) $row->task_count,
            ])
            ->all();

        return [
            'total' => DB::table('tasks')->where('project_id', $projectId)->count(),
            'open' => DB::table('tasks')
                ->join('kanban_columns', 'kanban_columns.id', '=', 'tasks.status_column_id')
                ->where('tasks.project_id', $projectId)
                ->where('kanban_columns.status_key', '!=', 'done')
                ->count(),
            'by_status' => $byStatus,
            'by_risk' => $byRisk,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function wiki(string $projectId): array
    {
        $bySourceStatus = DB::table('wiki_pages')
            ->where('project_id', $projectId)
            ->select(['source_status', DB::raw('count(*) as page_count')])
            ->groupBy('source_status')
            ->orderBy('source_status')
            ->get()
            ->map(fn (object $row): array => [
                'source_status' => (string) $row->source_status,
                'count' => (int) $row->page_count,
            ])
            ->all();

        return [
            'pages_total' => DB::table('wiki_pages')->where('project_id', $projectId)->count(),
            'stale_pages' => DB::table('wiki_pages')
                ->where('project_id', $projectId)
                ->whereIn('source_status', ['stale', 'conflict_with_code'])
                ->count(),
            'by_source_status' => $bySourceStatus,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentRuns(string $projectId): array
    {
        return DB::table('runs')
            ->where('project_id', $projectId)
            ->orderByDesc('started_at')
            ->limit(5)
            ->get(['id', 'status', 'runtime_profile', 'risk_level', 'summary', 'started_at', 'finished_at'])
            ->map(fn (object $run): array => [
                'id' => (string) $run->id,
                'status' => (string) $run->status,
                'runtime_profile' => (string) $run->runtime_profile,
                'risk_level' => (string) $run->risk_level,
                'summary' => Str::limit((string) ($run->summary ?? ''), 240),
                'started_at' => (string) $run->started_at,
                'finished_at' => $run->finished_at ? (string) $run->finished_at : null,
            ])
            ->all();
    }
}
