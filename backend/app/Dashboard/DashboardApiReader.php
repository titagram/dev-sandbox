<?php

namespace App\Dashboard;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class DashboardApiReader
{
    /**
     * @return list<array<string, mixed>>
     */
    public function projects(): array
    {
        return DB::table('projects')
            ->orderBy('name')
            ->get()
            ->map(fn (object $project): array => $this->projectSummary($project))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function project(string $projectId): array
    {
        $project = DB::table('projects')->where('id', $projectId)->first();
        abort_unless($project, 404);

        return [
            ...$this->projectSummary($project),
            'repositories' => $this->repositories($projectId),
            'policy' => [
                'code_write_allowed' => true,
                'destructive_scans_allowed' => false,
                'auto_import_on_snapshot' => false,
                'require_review_above_risk' => 'medium',
                'retention_days' => (int) config('services.devboard.artifact_retention_days', 90),
            ],
            'recent_run_ids' => DB::table('runs')
                ->where('project_id', $projectId)
                ->orderByDesc('created_at')
                ->limit(8)
                ->pluck('id')
                ->map(fn (mixed $id): string => (string) $id)
                ->all(),
            'latest_artifact_ids' => DB::table('artifacts')
                ->where('project_id', $projectId)
                ->orderByDesc('created_at')
                ->limit(8)
                ->pluck('id')
                ->map(fn (mixed $id): string => (string) $id)
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function kanban(): array
    {
        $project = DB::table('projects')->orderBy('created_at')->first();
        $board = $project
            ? DB::table('kanban_boards')->where('project_id', $project->id)->where('is_default', true)->first()
            : null;

        if (! $board) {
            return ['columns' => [], 'tasks' => []];
        }

        $columns = [];
        $tasks = [];

        foreach (DB::table('kanban_columns')->where('board_id', $board->id)->orderBy('position')->get() as $column) {
            $taskRows = DB::table('tasks')
                ->where('status_column_id', $column->id)
                ->orderByDesc('updated_at')
                ->get();

            $taskIds = [];

            foreach ($taskRows as $task) {
                $taskIds[] = (string) $task->id;
                $tasks[(string) $task->id] = $this->taskCard($task);
            }

            $columns[] = [
                'id' => $this->taskColumn((string) $column->status_key),
                'title' => (string) $column->name,
                'task_ids' => $taskIds,
            ];
        }

        return ['columns' => $columns, 'tasks' => $tasks];
    }

    /**
     * @return array<string, mixed>
     */
    public function task(string $taskId): array
    {
        $task = DB::table('tasks')->where('id', $taskId)->first();
        abort_unless($task, 404);

        return [
            ...$this->taskCard($task),
            'description' => (string) ($task->description ?? ''),
            'acceptance_criteria' => [],
            'audit_ids' => DB::table('audit_logs')
                ->where('target_type', 'task')
                ->where('target_id', $taskId)
                ->pluck('id')
                ->map(fn (mixed $id): string => (string) $id)
                ->all(),
            'graph_node_ids' => [],
            'source' => $this->sourceMeta(ref: $taskId),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function runs(): array
    {
        return DB::table('runs')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (object $run): array => $this->runSummary($run))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function run(string $runId): array
    {
        $run = DB::table('runs')->where('id', $runId)->first();
        abort_unless($run, 404);

        $events = DB::table('run_events')->where('run_id', $runId)->orderBy('created_at')->get();
        $artifacts = DB::table('artifacts')->where('run_id', $runId)->orderByDesc('created_at')->get();
        $repository = $run->repository_id ? DB::table('repositories')->where('id', $run->repository_id)->first() : null;
        $review = $this->reviewEvent($runId);

        return [
            ...$this->runSummary($run),
            'status' => $this->runStatus((string) $run->status, $review !== null),
            'reviewed_by' => $review['reviewed_by'] ?? null,
            'local_source_labels' => [
                [
                    'label' => 'Local plugin snapshot',
                    'value' => (string) ($run->head_sha ?? $run->base_sha),
                    'source' => $this->sourceMeta(ref: $runId),
                ],
            ],
            'timeline' => $events->map(fn (object $event): array => [
                'id' => (string) $event->id,
                'ts' => (string) $event->created_at,
                'label' => (string) $event->event_type,
                'status' => $this->timelineStatus((string) $event->severity),
                'detail' => (string) $event->message,
            ])->all(),
            'metrics' => [
                ['label' => 'Artifacts', 'value' => (string) $artifacts->count(), 'tone' => 'neutral'],
                ['label' => 'Repository', 'value' => (string) ($repository->name ?? 'unknown'), 'tone' => 'neutral'],
            ],
            'risk_triggers' => $this->riskTriggers($events),
            'safety_results' => $this->safetyResults($artifacts),
            'artifact_ids' => $artifacts->pluck('id')->map(fn (mixed $id): string => (string) $id)->all(),
            'test_output' => $this->testOutput($artifacts),
            'graph_status' => $this->graphStatus($runId),
            'wiki_status' => $this->wikiStatus((string) $run->project_id),
            'wiki_page_id' => $this->latestWikiPageId((string) $run->project_id),
            'audit_events' => DB::table('audit_logs')
                ->where('target_id', $runId)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (object $audit): array => [
                    'id' => (string) $audit->id,
                    'ts' => (string) $audit->created_at,
                    'actor' => (string) $audit->actor_type,
                    'action' => (string) $audit->action,
                    'target' => (string) ($audit->target_type ?? 'run'),
                    'result' => 'ok',
                ])
                ->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function wiki(): array
    {
        return DB::table('wiki_pages')
            ->leftJoin('wiki_revisions', 'wiki_revisions.id', '=', 'wiki_pages.current_revision_id')
            ->select([
                'wiki_pages.id',
                'wiki_pages.title',
                'wiki_pages.project_id',
                'wiki_pages.page_type',
                'wiki_pages.source_status',
                'wiki_pages.updated_at',
                'wiki_revisions.source_type',
                'wiki_revisions.source_status as revision_source_status',
                'wiki_revisions.evidence_refs',
                'wiki_revisions.created_at as revision_created_at',
            ])
            ->orderBy('wiki_pages.title')
            ->get()
            ->map(fn (object $page): array => $this->wikiSummary($page))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function wikiPage(string $pageId): array
    {
        $page = DB::table('wiki_pages')
            ->leftJoin('wiki_revisions', 'wiki_revisions.id', '=', 'wiki_pages.current_revision_id')
            ->select([
                'wiki_pages.id',
                'wiki_pages.title',
                'wiki_pages.project_id',
                'wiki_pages.page_type',
                'wiki_pages.source_status',
                'wiki_pages.updated_at',
                'wiki_revisions.content_markdown',
                'wiki_revisions.source_type',
                'wiki_revisions.source_status as revision_source_status',
                'wiki_revisions.evidence_refs',
                'wiki_revisions.created_at as revision_created_at',
            ])
            ->where('wiki_pages.id', $pageId)
            ->first();
        abort_unless($page, 404);

        return [
            ...$this->wikiSummary($page),
            'body_markdown' => (string) ($page->content_markdown ?? ''),
            'evidence' => $this->wikiEvidence($page),
            'related_run_ids' => $this->wikiRelatedRunIds($page),
            'related_node_ids' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function graph(?string $snapshotId = null, ?string $runId = null): array
    {
        $snapshot = DB::table('snapshots')
            ->when($snapshotId, fn ($query, string $id) => $query->where('id', $id))
            ->when(
                ! $snapshotId && $runId !== null,
                fn ($query) => $query->where('created_by_run_id', $runId),
            )
            ->orderByDesc('created_at')
            ->first();
        abort_unless($snapshot, 404);

        $artifact = $snapshot->graph_snapshot_artifact_id
            ? DB::table('artifacts')->where('id', $snapshot->graph_snapshot_artifact_id)->first()
            : null;
        $payload = $this->artifactPayload($artifact);
        $nodes = is_array($payload['nodes'] ?? null) ? $payload['nodes'] : [];
        $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];
        $repository = DB::table('repositories')->where('id', $snapshot->repository_id)->first();

        $graphNodes = array_map(
            fn (array $node): array => $this->graphNode($node, (string) ($repository->name ?? 'unknown'), $relationships),
            array_filter($nodes, 'is_array'),
        );

        $graphEdges = array_map(
            fn (array $edge, int $index): array => [
                'id' => (string) ($edge['id'] ?? 'edge-'.$index),
                'from' => (string) ($edge['from'] ?? ''),
                'to' => (string) ($edge['to'] ?? ''),
                'kind' => $this->graphEdgeKind((string) ($edge['type'] ?? 'uses')),
            ],
            array_filter($relationships, 'is_array'),
            array_keys(array_filter($relationships, 'is_array')),
        );

        return [
            'snapshot_id' => (string) $snapshot->id,
            'run_id' => $snapshot->created_by_run_id ? (string) $snapshot->created_by_run_id : null,
            'generated_at' => (string) $snapshot->created_at,
            'source' => $this->sourceMeta(type: 'local_analyzer', ref: (string) $snapshot->id),
            'stats' => [
                'nodes' => count($graphNodes),
                'edges' => count($graphEdges),
                'modules' => count(array_filter($graphNodes, fn (array $node): bool => $node['kind'] === 'module')),
                'routes' => count(array_filter($graphNodes, fn (array $node): bool => $node['kind'] === 'route')),
            ],
            'nodes' => array_values($graphNodes),
            'edges' => array_values($graphEdges),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function artifacts(): array
    {
        return DB::table('artifacts')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (object $artifact): array => $this->artifact($artifact))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function artifactDownload(string $runId, string $artifactId): array
    {
        $artifact = DB::table('artifacts')
            ->where('id', $artifactId)
            ->where('run_id', $runId)
            ->first();
        abort_unless($artifact, 404);
        abort_unless(in_array($artifact->status, ['validated', 'imported'], true), 409);

        return [
            'url' => "/runs/{$runId}/artifacts/{$artifactId}/download",
            'name' => basename((string) $artifact->storage_path),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pluginTokens(): array
    {
        return DB::table('api_tokens')
            ->leftJoin('users', 'users.id', '=', 'api_tokens.user_id')
            ->select([
                'api_tokens.id',
                'api_tokens.name',
                'api_tokens.token_prefix',
                'api_tokens.scopes',
                'api_tokens.created_at',
                'api_tokens.last_used_at',
                'api_tokens.revoked_at',
                'users.name as created_by',
            ])
            ->orderByDesc('api_tokens.created_at')
            ->get()
            ->map(fn (object $token): array => $this->pluginToken($token))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function pluginToken(object $token, ?string $plainToken = null): array
    {
        $row = [
            'id' => (string) $token->id,
            'name' => (string) $token->name,
            'prefix' => (string) $token->token_prefix,
            'scopes' => $this->decodeList($token->scopes ?? null),
            'created_at' => (string) $token->created_at,
            'last_used_at' => $token->last_used_at ? (string) $token->last_used_at : null,
            'created_by' => (string) ($token->created_by ?? 'DevBoard'),
            'revoked' => $token->revoked_at !== null,
        ];

        if ($plainToken !== null) {
            $row['plain_token'] = $plainToken;
        }

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pluginDevices(): array
    {
        return DB::table('devices')
            ->leftJoin('api_tokens', 'api_tokens.device_id', '=', 'devices.id')
            ->select([
                'devices.id',
                'devices.name',
                'devices.platform_os',
                'devices.plugin_version',
                'devices.created_at',
                'devices.last_seen_at',
                'devices.status',
                'api_tokens.token_prefix',
            ])
            ->groupBy([
                'devices.id',
                'devices.name',
                'devices.platform_os',
                'devices.plugin_version',
                'devices.created_at',
                'devices.last_seen_at',
                'devices.status',
                'api_tokens.token_prefix',
            ])
            ->orderByDesc('devices.created_at')
            ->get()
            ->map(fn (object $device): array => [
                'id' => (string) $device->id,
                'hostname' => (string) $device->name,
                'os' => (string) $device->platform_os,
                'plugin_version' => (string) $device->plugin_version,
                'registered_at' => (string) $device->created_at,
                'last_seen_at' => (string) ($device->last_seen_at ?? $device->created_at),
                'token_prefix' => (string) ($device->token_prefix ?? ''),
                'status' => $this->deviceStatus((string) $device->status),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function systemStatus(?array $lastOperation = null): array
    {
        return [
            'runtime' => [
                ['label' => 'Projects', 'value' => (string) DB::table('projects')->count(), 'tone' => 'neutral'],
                ['label' => 'Repositories', 'value' => (string) DB::table('repositories')->count(), 'tone' => 'neutral'],
                ['label' => 'Runs', 'value' => (string) DB::table('runs')->count(), 'tone' => DB::table('runs')->where('status', 'failed')->exists() ? 'warn' : 'good'],
                ['label' => 'Active devices', 'value' => (string) DB::table('devices')->where('status', 'active')->count(), 'tone' => 'neutral'],
            ],
            'queue' => [
                [
                    'name' => (string) config('queue.default', 'sync'),
                    'pending' => 0,
                    'processing' => 0,
                    'failed' => DB::table('failed_jobs')->count(),
                ],
            ],
            'graph_status' => DB::table('artifacts')->where('artifact_type', 'graph_snapshot')->exists() ? 'complete' : 'not_started',
            'import_status' => DB::table('genesis_imports')->where('status', 'failed')->exists() ? 'failed' : 'complete',
            'retention' => [
                'artifact_retention_days' => (int) config('services.devboard.artifact_retention_days', 90),
                'auto_purge_enabled' => false,
            ],
            'last_operation' => $lastOperation,
            'audit_export_available' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectSummary(object $project): array
    {
        $projectId = (string) $project->id;
        $repoCount = DB::table('repositories')->where('project_id', $projectId)->count();
        $openTasks = DB::table('tasks')
            ->join('kanban_columns', 'kanban_columns.id', '=', 'tasks.status_column_id')
            ->where('tasks.project_id', $projectId)
            ->where('kanban_columns.status_key', '!=', 'done')
            ->count();
        $latestRun = DB::table('runs')->where('project_id', $projectId)->orderByDesc('created_at')->first();

        return [
            'id' => $projectId,
            'key' => (string) $project->slug,
            'name' => (string) $project->name,
            'description' => (string) ($project->description ?? ''),
            'owner' => $this->userName($project->created_by_user_id),
            'repository_count' => $repoCount,
            'open_tasks' => $openTasks,
            'risk_level' => $this->risk($latestRun?->risk_level ?? 'low'),
            'wiki_freshness' => DB::table('wiki_pages')->where('project_id', $projectId)->whereIn('source_status', ['stale', 'conflict_with_code'])->exists() ? 'stale' : 'complete',
            'genesis_status' => $this->pipelineStatus(DB::table('genesis_imports')->where('project_id', $projectId)->orderByDesc('created_at')->value('status')),
            'delta_status' => $this->pipelineStatus(DB::table('delta_syncs')->where('project_id', $projectId)->orderByDesc('created_at')->value('status')),
            'graph_status' => DB::table('artifacts')->where('project_id', $projectId)->where('artifact_type', 'graph_snapshot')->exists() ? 'complete' : 'not_started',
            'updated_at' => (string) $project->updated_at,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function repositories(string $projectId): array
    {
        return DB::table('repositories')
            ->where('project_id', $projectId)
            ->orderBy('name')
            ->get()
            ->map(function (object $repository): array {
                $latestRun = DB::table('runs')->where('repository_id', $repository->id)->orderByDesc('created_at')->first();
                $latestSnapshot = DB::table('snapshots')->where('repository_id', $repository->id)->orderByDesc('created_at')->first();

                return [
                    'id' => (string) $repository->id,
                    'project_id' => (string) $repository->project_id,
                    'name' => (string) $repository->name,
                    'default_branch' => (string) $repository->default_branch,
                    'git_mode' => 'local_clone',
                    'last_local_snapshot' => $latestSnapshot?->created_at ? (string) $latestSnapshot->created_at : null,
                    'genesis_status' => $this->pipelineStatus(DB::table('genesis_imports')->where('repository_id', $repository->id)->orderByDesc('created_at')->value('status')),
                    'delta_status' => $this->pipelineStatus(DB::table('delta_syncs')->where('repository_id', $repository->id)->orderByDesc('created_at')->value('status')),
                    'graph_status' => DB::table('artifacts')->where('repository_id', $repository->id)->where('artifact_type', 'graph_snapshot')->exists() ? 'complete' : 'not_started',
                    'wiki_status' => DB::table('wiki_pages')->where('repository_id', $repository->id)->whereIn('source_status', ['stale', 'conflict_with_code'])->exists() ? 'stale' : 'complete',
                    'risk_level' => $this->risk($latestRun?->risk_level ?? 'low'),
                    'latest_run_id' => $latestRun?->id ? (string) $latestRun->id : null,
                    'latest_run_status' => $latestRun ? $this->runStatus((string) $latestRun->status) : null,
                    'source' => $this->sourceMeta(ref: (string) $repository->id),
                ];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function taskCard(object $task): array
    {
        $column = DB::table('kanban_columns')->where('id', $task->status_column_id)->first();
        $owner = $task->owner_user_id ? DB::table('users')->where('id', $task->owner_user_id)->first() : null;
        $latestRun = DB::table('runs')->where('task_id', $task->id)->orderByDesc('created_at')->first();
        $repositoryNames = $latestRun?->repository_id
            ? DB::table('repositories')->where('id', $latestRun->repository_id)->pluck('name')->map(fn (mixed $name): string => (string) $name)->all()
            : [];
        $wikiPageId = $this->latestWikiPageId((string) $task->project_id);

        return [
            'id' => (string) $task->id,
            'title' => (string) $task->title,
            'column' => $this->taskColumn((string) ($column->status_key ?? 'backlog')),
            'owner' => (string) ($owner->name ?? 'Unassigned'),
            'owner_color' => $this->avatarColor((string) ($owner->email ?? $task->id)),
            'risk' => $this->risk((string) $task->risk_level),
            'project_id' => (string) $task->project_id,
            'repositories' => $repositoryNames,
            'linked_run_id' => $latestRun?->id ? (string) $latestRun->id : null,
            'linked_run_status' => $latestRun ? $this->runStatus((string) $latestRun->status) : null,
            'wiki_page_id' => $wikiPageId,
            'source_status' => 'verified_from_code',
            'blocked' => $task->risk_level === 'high',
            'blocked_reason' => $task->risk_level === 'high' ? 'High risk task requires review.' : null,
            'updated_at' => (string) $task->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runSummary(object $run): array
    {
        $repository = $run->repository_id ? DB::table('repositories')->where('id', $run->repository_id)->first() : null;

        return [
            'id' => (string) $run->id,
            'project_id' => (string) $run->project_id,
            'repository_id' => $run->repository_id ? (string) $run->repository_id : '',
            'repository_name' => (string) ($repository->name ?? 'unknown'),
            'type' => $this->runType((string) $run->id),
            'status' => $this->runStatus((string) $run->status, $this->reviewEvent((string) $run->id) !== null),
            'risk_level' => $this->risk((string) $run->risk_level),
            'started_at' => (string) $run->started_at,
            'finished_at' => $run->finished_at ? (string) $run->finished_at : null,
            'duration_ms' => $run->finished_at ? max(0, strtotime((string) $run->finished_at) - strtotime((string) $run->started_at)) * 1000 : null,
            'task_id' => $run->task_id ? (string) $run->task_id : null,
            'source' => $this->sourceMeta(ref: (string) $run->id),
            'reviewed_by' => $this->reviewEvent((string) $run->id)['reviewed_by'] ?? null,
        ];
    }

    private function runType(string $runId): string
    {
        if (DB::table('genesis_imports')->where('run_id', $runId)->exists()) {
            return 'genesis_import';
        }

        if (DB::table('delta_syncs')->where('run_id', $runId)->exists()) {
            return 'delta_sync';
        }

        return 'analysis';
    }

    /**
     * @return array{reviewed_by: string}|null
     */
    private function reviewEvent(string $runId): ?array
    {
        $event = DB::table('run_events')
            ->where('run_id', $runId)
            ->where('event_type', 'run.reviewed')
            ->orderByDesc('created_at')
            ->first();

        if (! $event) {
            return null;
        }

        $payload = $this->decodeJson($event->payload);
        $userId = $payload['reviewed_by_user_id'] ?? null;

        return [
            'reviewed_by' => $userId ? $this->userName($userId) : 'Dashboard user',
        ];
    }

    /**
     * @param Collection<int, object> $events
     * @return list<array<string, mixed>>
     */
    private function riskTriggers(Collection $events): array
    {
        $triggers = [];

        foreach ($events as $event) {
            $payload = $this->decodeJson($event->payload);

            foreach (($payload['risk_triggers'] ?? []) as $index => $trigger) {
                $triggers[] = [
                    'id' => (string) $event->id.'-'.$index,
                    'label' => (string) $trigger,
                    'level' => $this->risk($event->severity === 'critical' ? 'critical' : 'medium'),
                    'reason' => (string) $event->message,
                ];
            }
        }

        return $triggers;
    }

    /**
     * @param Collection<int, object> $artifacts
     * @return list<array<string, string>>
     */
    private function safetyResults(Collection $artifacts): array
    {
        return $artifacts
            ->filter(fn (object $artifact): bool => in_array($artifact->status, ['rejected', 'failed'], true))
            ->map(fn (object $artifact): array => [
                'id' => (string) $artifact->id,
                'name' => (string) $artifact->artifact_type,
                'status' => 'fail',
                'detail' => (string) $artifact->status,
            ])
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, object> $artifacts
     */
    private function testOutput(Collection $artifacts): string
    {
        $testArtifact = $artifacts->first(fn (object $artifact): bool => in_array($artifact->artifact_type, ['test_map', 'command_output'], true));
        $payload = $this->artifactPayload($testArtifact);

        return (string) ($payload['summary'] ?? $payload['output'] ?? '');
    }

    private function graphStatus(string $runId): string
    {
        return DB::table('snapshots')
            ->where('created_by_run_id', $runId)
            ->whereNotNull('graph_snapshot_artifact_id')
            ->exists() ? 'complete' : 'not_started';
    }

    private function wikiStatus(string $projectId): string
    {
        return DB::table('wiki_pages')->where('project_id', $projectId)->exists() ? 'complete' : 'not_started';
    }

    private function latestWikiPageId(string $projectId): ?string
    {
        $id = DB::table('wiki_pages')->where('project_id', $projectId)->orderByDesc('updated_at')->value('id');

        return $id ? (string) $id : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function wikiSummary(object $page): array
    {
        $evidence = $this->decodeJson($page->evidence_refs ?? null);
        $sourceType = (string) ($page->source_type ?? 'user_manual');
        $sourceStatus = (string) ($page->revision_source_status ?? $page->source_status ?? 'needs_verification');

        return [
            'id' => (string) $page->id,
            'title' => (string) $page->title,
            'project_id' => (string) $page->project_id,
            'category' => (string) $page->page_type,
            'source_status' => $this->sourceStatus($sourceStatus),
            'has_evidence' => $evidence !== [],
            'updated_at' => (string) $page->updated_at,
            'source' => $this->sourceMeta(
                type: $this->sourceType($sourceType),
                status: $this->sourceStatus($sourceStatus),
                generatedAt: $page->revision_created_at ? (string) $page->revision_created_at : (string) $page->updated_at,
                ref: (string) $page->id,
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function wikiEvidence(object $page): array
    {
        $refs = $this->decodeJson($page->evidence_refs ?? null);
        $evidence = [];

        foreach ($refs as $index => $ref) {
            if (! is_array($ref)) {
                continue;
            }

            $id = (string) ($ref['artifact_id'] ?? $ref['run_id'] ?? $ref['path'] ?? $index);
            $evidence[] = [
                'id' => 'evidence-'.$index,
                'label' => (string) ($ref['description'] ?? $id),
                'kind' => isset($ref['artifact_id']) ? 'artifact_ref' : (isset($ref['run_id']) ? 'run_ref' : 'code_ref'),
                'ref' => $id,
                'source' => $this->sourceMeta(type: 'local_analyzer', ref: $id),
            ];
        }

        return $evidence;
    }

    /**
     * @return list<string>
     */
    private function wikiRelatedRunIds(object $page): array
    {
        $refs = $this->decodeJson($page->evidence_refs ?? null);
        $runIds = [];

        foreach ($refs as $ref) {
            if (! is_array($ref)) {
                continue;
            }

            if (isset($ref['run_id'])) {
                $runIds[] = (string) $ref['run_id'];
            }

            if (isset($ref['artifact_id'])) {
                $runId = DB::table('artifacts')->where('id', $ref['artifact_id'])->value('run_id');
                if ($runId) {
                    $runIds[] = (string) $runId;
                }
            }
        }

        return array_values(array_unique($runIds));
    }

    /**
     * @param array<string, mixed> $node
     * @param list<array<string, mixed>> $relationships
     * @return array<string, mixed>
     */
    private function graphNode(array $node, string $repository, array $relationships): array
    {
        $labels = is_array($node['labels'] ?? null) ? $node['labels'] : [];
        $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];
        $id = (string) ($node['id'] ?? $properties['id'] ?? 'node');

        return [
            'id' => $id,
            'label' => (string) ($properties['name'] ?? $properties['path'] ?? $id),
            'kind' => $this->graphNodeKind($labels),
            'repository' => $repository,
            'degree' => count(array_filter($relationships, fn (array $edge): bool => ($edge['from'] ?? null) === $id || ($edge['to'] ?? null) === $id)),
            'risk' => 'medium',
            'source' => $this->sourceMeta(type: 'local_analyzer', ref: $id),
        ];
    }

    /**
     * @param list<mixed> $labels
     */
    private function graphNodeKind(array $labels): string
    {
        $lower = array_map(static fn (mixed $label): string => strtolower((string) $label), $labels);

        if (in_array('route', $lower, true)) {
            return 'route';
        }

        if (in_array('class', $lower, true)) {
            return 'class';
        }

        if (in_array('function', $lower, true)) {
            return 'function';
        }

        if (in_array('model', $lower, true)) {
            return 'model';
        }

        if (in_array('module', $lower, true)) {
            return 'module';
        }

        return 'service';
    }

    private function graphEdgeKind(string $kind): string
    {
        return match (strtolower($kind)) {
            'calls' => 'calls',
            'imports' => 'imports',
            'extends' => 'extends',
            'routes_to' => 'routes_to',
            default => 'uses',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(object $artifact): array
    {
        return [
            'id' => (string) $artifact->id,
            'name' => basename((string) $artifact->storage_path),
            'kind' => $this->artifactKind((string) $artifact->artifact_type),
            'state' => $this->artifactState((string) $artifact->status),
            'project_id' => (string) $artifact->project_id,
            'repository_id' => $artifact->repository_id ? (string) $artifact->repository_id : '',
            'run_id' => $artifact->run_id ? (string) $artifact->run_id : null,
            'size_bytes' => (int) $artifact->size_bytes,
            'checksum' => 'sha256:'.$artifact->sha256,
            'created_at' => (string) $artifact->created_at,
            'validated' => in_array($artifact->status, ['validated', 'imported'], true),
            'downloadable' => in_array($artifact->status, ['validated', 'imported'], true),
            'source' => $this->sourceMeta(ref: (string) $artifact->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function artifactPayload(?object $artifact): array
    {
        if (! $artifact || ! $artifact->storage_path || ! Storage::disk('local')->exists($artifact->storage_path)) {
            return [];
        }

        return $this->decodeJson(Storage::disk('local')->get($artifact->storage_path));
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceMeta(
        string $type = 'local_plugin_snapshot',
        string $status = 'verified_from_code',
        string $origin = 'DevBoard Laravel dashboard API',
        ?string $generatedAt = null,
        ?string $ref = null,
    ): array {
        $source = [
            'type' => $type,
            'status' => $status,
            'origin' => $origin,
            'generated_at' => $generatedAt ?? now()->toIso8601String(),
        ];

        if ($ref !== null) {
            $source['ref'] = $ref;
        }

        return $source;
    }

    private function pipelineStatus(mixed $status): string
    {
        return match ((string) $status) {
            'active', 'finished', 'imported', 'validated', 'complete' => 'complete',
            'started', 'running', 'uploading', 'processing' => 'in_progress',
            'failed', 'rejected', 'invalid' => 'failed',
            'stale' => 'stale',
            'pending', 'pending_import' => 'pending',
            default => 'not_started',
        };
    }

    private function runStatus(string $status, bool $reviewed = false): string
    {
        if ($reviewed) {
            return 'reviewed';
        }

        return match ($status) {
            'queued' => 'queued',
            'started', 'active', 'running' => 'running',
            'finished', 'passed', 'complete' => 'passed',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
            default => 'needs_review',
        };
    }

    private function taskColumn(string $column): string
    {
        return in_array($column, ['backlog', 'ready', 'in_progress', 'blocked', 'review', 'done'], true) ? $column : 'backlog';
    }

    private function risk(string $risk): string
    {
        return in_array($risk, ['low', 'medium', 'high', 'critical'], true) ? $risk : 'low';
    }

    private function sourceStatus(string $status): string
    {
        return in_array($status, ['verified_from_code', 'developer_provided', 'ai_generated', 'needs_verification', 'stale', 'conflict_with_code'], true)
            ? $status
            : 'needs_verification';
    }

    private function sourceType(string $type): string
    {
        return in_array($type, ['local_plugin_snapshot', 'local_plugin_diff', 'local_analyzer', 'server_history', 'user_manual', 'ai_generated'], true)
            ? $type
            : 'user_manual';
    }

    private function timelineStatus(string $severity): string
    {
        return match ($severity) {
            'critical', 'error' => 'error',
            'warning' => 'warn',
            'info' => 'info',
            default => 'ok',
        };
    }

    private function artifactKind(string $type): string
    {
        return match ($type) {
            'genesis_manifest', 'file_inventory' => 'genesis_import',
            'diff_summary' => 'delta_sync',
            'security_report', 'test_map', 'command_output' => 'report',
            default => 'analysis',
        };
    }

    private function artifactState(string $status): string
    {
        return match ($status) {
            'imported' => 'imported',
            'validated' => 'validated',
            'purged' => 'purged',
            'rejected', 'failed', 'invalid' => 'invalid',
            default => 'uploaded',
        };
    }

    private function deviceStatus(string $status): string
    {
        return match ($status) {
            'revoked' => 'revoked',
            'stale' => 'stale',
            default => 'active',
        };
    }

    private function userName(mixed $userId): string
    {
        return (string) (DB::table('users')->where('id', $userId)->value('name') ?? 'DevBoard');
    }

    private function avatarColor(string $seed): string
    {
        $colors = ['#2563eb', '#059669', '#d97706', '#7c3aed', '#dc2626'];

        return $colors[hexdec(substr(md5($seed), 0, 2)) % count($colors)];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(?string $payload): array
    {
        if (! $payload) {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<string>
     */
    private function decodeList(mixed $payload): array
    {
        if (is_array($payload)) {
            return array_values(array_map('strval', $payload));
        }

        if (! is_string($payload) || $payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }
}
