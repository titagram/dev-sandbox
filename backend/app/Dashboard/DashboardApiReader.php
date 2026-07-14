<?php

namespace App\Dashboard;

use App\Services\Graph\CanonicalGraphRepository;
use App\Services\Graph\DashboardGraphPublicKind;
use App\Services\Graph\DashboardGraphPublicHandle;
use App\Services\Neo4j\Neo4jClient;
use App\Services\Neo4j\Neo4jResultMaterializer;
use App\Services\Neo4jClientFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class DashboardApiReader
{
    private const GRAPH_PREVIEW_NODE_LIMIT = 200;

    private const GRAPH_PREVIEW_EDGE_LIMIT = 300;

    private const CANONICAL_SCOPE_LIMIT = 50;

    /**
     * Dashboard graph previews are a data-minimized public projection, not a
     * serialization of analyzer nodes. Only these canonical semantic types may
     * contribute a human label, and only through the fields listed here.
     * Unknown producer types remain non-renderable and cannot contribute labels.
     *
     * @var array<string, array{types: list<string>, label_fields: list<string>}>
     */
    private const GRAPH_PREVIEW_NODE_POLICY = [
        'method' => [
            'types' => ['method'],
            'label_fields' => ['name'],
        ],
        'class' => [
            'types' => ['class'],
            'label_fields' => ['name'],
        ],
        'method_reference' => [
            'types' => ['method_reference'],
            'label_fields' => ['name'],
        ],
        'external_class' => [
            'types' => ['external_class'],
            'label_fields' => ['name'],
        ],
        'table' => [
            'types' => ['table'],
            'label_fields' => ['name'],
        ],
        'route' => [
            'types' => ['route'],
            'label_fields' => ['name', 'label', 'path', 'uri', 'route', 'url'],
        ],
        'trait' => [
            'types' => ['trait'],
            'label_fields' => ['name'],
        ],
        'external_symbol' => [
            'types' => ['external_symbol'],
            'label_fields' => ['name'],
        ],
        'interface' => [
            'types' => ['interface'],
            'label_fields' => ['name'],
        ],
        'file' => [
            'types' => ['file'],
            'label_fields' => ['name'],
        ],
    ];

    public function __construct(
        private readonly CanonicalGraphRepository $canonicalGraphs,
        private readonly ?DashboardGraphPublicHandle $publicHandles = null,
        private readonly ?Neo4jClient $neo4j = null,
        private readonly ?DashboardGraphPublicKind $publicKinds = null,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function projects(string $status = 'active'): array
    {
        return DB::table('projects')
            ->where('status', $status)
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
        abort_unless($project && $project->status !== 'deleted', 404);

        $repositories = $this->repositories($projectId);

        return [
            ...$this->projectSummary($project),
            'links' => [
                'wiki' => "/projects/{$projectId}/wiki",
                'wiki_api' => "/api/dashboard/projects/{$projectId}/wiki",
            ],
            'repositories' => $repositories,
            'kickstart' => $this->kickstart($projectId),
            'assistant' => [
                'triage_href' => "/api/dashboard/projects/{$projectId}/assistant/backlog-triage",
                'latest_backlog_triage_suggestion' => $this->latestBacklogTriageSuggestion($projectId),
            ],
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
    public function projectLifecycle(string $projectId): array
    {
        $project = DB::table('projects')->where('id', $projectId)->first();
        abort_unless($project, 404);

        return $this->projectSummary($project);
    }

    /**
     * @return array{entries: list<array<string, mixed>>}
     */
    public function projectMemory(string $projectId, ?string $domain = null, ?string $query = null): array
    {
        $this->abortUnlessProjectReadable($projectId);

        $normalizedDomain = $this->normalizeMemoryDomain($domain);
        $normalizedQuery = trim((string) ($query ?? ''));

        if ($normalizedDomain === 'wiki') {
            $entries = $this->wikiMemoryEntries($projectId, $normalizedQuery);
        } else {
            $entries = $this->projectMemoryEntries($projectId, $normalizedDomain, $normalizedQuery);
        }

        return [
            'domain' => $normalizedDomain ?? 'all',
            'query' => $normalizedQuery === '' ? null : $normalizedQuery,
            'domains' => $this->memoryDomainCounts($projectId),
            'entries' => $entries,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function projectMemoryEntry(string $entryId): array
    {
        $entry = DB::table('project_memory_entries')->where('id', $entryId)->first();
        abort_unless($entry, 404);
        $this->abortUnlessProjectReadable((string) $entry->project_id);

        return $this->memoryEntry($entry);
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function projectAgentWork(string $projectId): array
    {
        $this->abortUnlessProjectReadable($projectId);

        $items = DB::table('agent_work_items')
            ->where('project_id', $projectId)
            ->whereNull('archived_at')
            ->orderByRaw("case priority when 'urgent' then 1 when 'high' then 2 when 'normal' then 3 else 4 end")
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (object $item): array => $this->agentWorkItem($item))
            ->all();

        return ['items' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    public function agentWorkDetail(string $projectId, string $workItemId): array
    {
        $this->abortUnlessProjectReadable($projectId);

        $item = DB::table('agent_work_items')
            ->where('project_id', $projectId)
            ->where('id', $workItemId)
            ->first();
        abort_unless($item, 404);

        $resultMemory = $item->result_memory_entry_id
            ? DB::table('project_memory_entries')->where('id', $item->result_memory_entry_id)->first()
            : null;

        $run = DB::table('assistant_runs')
            ->where('target_type', 'agent_work_item')
            ->where('target_id', $workItemId)
            ->orderByDesc('started_at')
            ->first();

        return [
            'item' => [
                ...$this->agentWorkItem($item),
                'result_memory_entry' => $resultMemory ? $this->memoryEntry($resultMemory) : null,
                'events' => $this->agentWorkEvents($workItemId),
                'chat' => [
                    'run_id' => $run ? (string) $run->id : null,
                    'agent_key' => (string) $item->assigned_agent_key,
                    'messages' => $run ? $this->assistantMessages((string) $run->id) : [],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function agentWorkItemById(string $workItemId): array
    {
        $item = DB::table('agent_work_items')->where('id', $workItemId)->first();
        abort_unless($item, 404);
        $this->abortUnlessProjectReadable((string) $item->project_id);

        return $this->agentWorkItem($item);
    }

    /**
     * @return array{threads: list<array<string, mixed>>}
     */
    public function projectAgentChats(string $projectId): array
    {
        $this->abortUnlessProjectReadable($projectId);

        $threads = DB::table('agent_chat_threads')
            ->where('project_id', $projectId)
            ->where('status', '!=', 'archived')
            ->whereNull('archived_at')
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get()
            ->map(fn (object $thread): array => $this->agentChatThread($thread))
            ->all();

        return ['threads' => $threads];
    }

    /**
     * @return array{thread: array<string, mixed>}
     */
    public function agentChatThreadDetail(string $projectId, string $threadId): array
    {
        $this->abortUnlessProjectReadable($projectId);

        $thread = DB::table('agent_chat_threads')
            ->where('project_id', $projectId)
            ->where('id', $threadId)
            ->first();
        abort_unless($thread, 404);

        return [
            'thread' => [
                ...$this->agentChatThread($thread),
                'messages' => $this->agentChatMessages($threadId),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $taskStateCounts = DB::table('tasks')
            ->join('projects', 'projects.id', '=', 'tasks.project_id')
            ->join('kanban_columns', 'kanban_columns.id', '=', 'tasks.status_column_id')
            ->where('projects.status', 'active')
            ->select('kanban_columns.status_key', DB::raw('count(*) as aggregate'))
            ->groupBy('kanban_columns.status_key')
            ->pluck('aggregate', 'kanban_columns.status_key')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $taskRiskCounts = DB::table('tasks')
            ->join('projects', 'projects.id', '=', 'tasks.project_id')
            ->where('projects.status', 'active')
            ->select('risk_level', DB::raw('count(*) as aggregate'))
            ->groupBy('risk_level')
            ->pluck('aggregate', 'risk_level')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $repositoryIdsWithGenesis = DB::table('genesis_imports')
            ->whereIn('status', ['active', 'finished', 'imported', 'complete'])
            ->distinct()
            ->pluck('repository_id')
            ->all();

        return [
            'summary' => [
                'active_projects' => DB::table('projects')->where('status', 'active')->count(),
                'repositories_awaiting_genesis' => DB::table('repositories')
                    ->join('projects', 'projects.id', '=', 'repositories.project_id')
                    ->where('projects.status', 'active')
                    ->when($repositoryIdsWithGenesis !== [], fn ($query) => $query->whereNotIn('repositories.id', $repositoryIdsWithGenesis))
                    ->count(),
            ],
            'tasks' => [
                'total' => DB::table('tasks')
                    ->join('projects', 'projects.id', '=', 'tasks.project_id')
                    ->where('projects.status', 'active')
                    ->count(),
                'blocked' => (int) ($taskStateCounts['blocked'] ?? 0),
                'by_state' => $this->countsWithDefaults($taskStateCounts, ['backlog', 'ready', 'in_progress', 'blocked', 'review', 'done']),
                'by_risk' => $this->countsWithDefaults($taskRiskCounts, ['low', 'medium', 'high', 'critical']),
            ],
            'runs' => [
                'failed' => DB::table('runs')
                    ->join('projects', 'projects.id', '=', 'runs.project_id')
                    ->where('projects.status', 'active')
                    ->where('runs.status', 'failed')
                    ->count(),
                'running' => DB::table('runs')
                    ->join('projects', 'projects.id', '=', 'runs.project_id')
                    ->where('projects.status', 'active')
                    ->whereIn('runs.status', [
                        'created',
                        'queued',
                        'started',
                        'context_pulled',
                        'local_snapshot_received',
                        'working',
                        'heartbeat',
                        'artifact_uploaded',
                        'active',
                        'running',
                    ])
                    ->count(),
            ],
            'wiki' => [
                'stale_pages' => DB::table('wiki_pages')
                    ->join('projects', 'projects.id', '=', 'wiki_pages.project_id')
                    ->where('projects.status', 'active')
                    ->whereIn('wiki_pages.source_status', ['stale', 'conflict_with_code'])
                    ->count(),
            ],
            'agents' => [
                'online' => DB::table('devices')->where('status', 'active')->where('last_seen_at', '>=', now()->subMinutes(10))->count(),
                'offline' => DB::table('devices')
                    ->where('status', 'active')
                    ->where(function ($query): void {
                        $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subMinutes(10));
                    })
                    ->count(),
            ],
            'projects' => $this->projects('active'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function kanban(?string $projectId = null): array
    {
        $project = $this->resolveProject($projectId);
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
        $this->abortUnlessProjectReadable((string) $task->project_id);

        return [
            ...$this->taskCard($task),
            'description' => (string) ($task->description ?? ''),
            'acceptance_criteria' => $this->jsonList($task->acceptance_criteria ?? null),
            'attachments' => $this->taskAttachments($taskId),
            'assistant' => [
                'clarify_href' => "/api/dashboard/tasks/{$taskId}/assistant/clarify",
                'resolve_suggestion_href' => '/api/dashboard/assistant-suggestions',
                'apply_suggestion_href' => '/api/dashboard/assistant-suggestions',
                'latest_suggestion' => $this->latestTaskClarificationSuggestion($taskId),
            ],
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
     * @return array<string, mixed>
     */
    public function taskAttachment(string $attachmentId): array
    {
        $attachment = DB::table('task_attachments')->where('id', $attachmentId)->whereNull('deleted_at')->first();
        abort_unless($attachment, 404);
        $this->abortUnlessProjectReadable((string) $attachment->project_id);

        return $this->taskAttachmentRow($attachment);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestTaskClarificationSuggestion(string $taskId): ?array
    {
        $suggestion = DB::table('assistant_suggestions')
            ->where('target_type', 'task')
            ->where('target_id', $taskId)
            ->where('suggestion_type', 'task_clarification')
            ->orderByDesc('created_at')
            ->first();

        if (! $suggestion) {
            return null;
        }

        return [
            'id' => (string) $suggestion->id,
            'assistant_run_id' => (string) $suggestion->assistant_run_id,
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
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestBacklogTriageSuggestion(string $projectId): ?array
    {
        $suggestion = DB::table('assistant_suggestions')
            ->where('target_type', 'project')
            ->where('target_id', $projectId)
            ->where('suggestion_type', 'backlog_triage')
            ->orderByDesc('created_at')
            ->first();

        if (! $suggestion) {
            return null;
        }

        return [
            'id' => (string) $suggestion->id,
            'assistant_run_id' => (string) $suggestion->assistant_run_id,
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
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function runs(?string $projectId = null): array
    {
        $this->abortUnlessProjectExists($projectId);

        return DB::table('runs')
            ->join('projects', 'projects.id', '=', 'runs.project_id')
            ->where('projects.status', '!=', 'deleted')
            ->when($projectId !== null, fn ($query) => $query->where('runs.project_id', $projectId))
            ->select('runs.*')
            ->orderByDesc('runs.created_at')
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
        $this->abortUnlessProjectReadable((string) $run->project_id);

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
    public function wiki(?string $projectId = null): array
    {
        $this->abortUnlessProjectExists($projectId);

        return DB::table('wiki_pages')
            ->join('projects', 'projects.id', '=', 'wiki_pages.project_id')
            ->leftJoin('wiki_revisions', 'wiki_revisions.id', '=', 'wiki_pages.current_revision_id')
            ->where('projects.status', '!=', 'deleted')
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
            ->when($projectId !== null, fn ($query) => $query->where('wiki_pages.project_id', $projectId))
            ->orderBy('wiki_pages.title')
            ->get()
            ->map(fn (object $page): array => $this->wikiSummary($page))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function wikiPage(string $pageId, ?string $projectId = null): array
    {
        $this->abortUnlessProjectExists($projectId);

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
            ->when($projectId !== null, fn ($query) => $query->where('wiki_pages.project_id', $projectId))
            ->first();
        abort_unless($page, 404);
        $this->abortUnlessProjectReadable((string) $page->project_id);

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
    public function graph(?string $projectId = null, ?string $snapshotId = null, ?string $runId = null): array
    {
        $this->abortUnlessProjectExists($projectId);

        if ($projectId !== null && $snapshotId === null && $runId === null) {
            $this->abortUnlessProjectReadable($projectId);

            return $this->canonicalGraph($projectId);
        }

        $snapshot = DB::table('snapshots')
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
            ->when($snapshotId, fn ($query, string $id) => $query->where('id', $id))
            ->when(
                ! $snapshotId && $runId !== null,
                fn ($query) => $query->where('created_by_run_id', $runId),
            )
            ->orderByDesc('created_at')
            ->first();

        if (! $snapshot && $projectId !== null) {
            return $this->emptyGraph($projectId);
        }

        abort_unless($snapshot, 404);
        $this->abortUnlessProjectReadable((string) $snapshot->project_id);

        $artifact = $snapshot->graph_snapshot_artifact_id
            ? DB::table('artifacts')->where('id', $snapshot->graph_snapshot_artifact_id)->first()
            : null;
        $payload = $this->artifactPayload($artifact);
        $nodes = is_array($payload['nodes'] ?? null) ? $payload['nodes'] : [];
        $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];
        $repository = DB::table('repositories')->where('id', $snapshot->repository_id)->first();
        $validNodes = array_values(array_filter($nodes, 'is_array'));
        $normalizedRelationships = $this->graphRelationships($relationships);
        $degreeByNode = $this->graphDegrees($normalizedRelationships);
        $nodeStats = $this->graphNodeStats($validNodes, [], true);
        $previewNodes = $this->graphPreviewNodes($validNodes, $normalizedRelationships);
        $privateIdentityTokens = $this->graphPrivateIdentityTokenMap($validNodes);

        $graphNodes = array_map(
            fn (array $node): array => $this->graphNode(
                $node,
                (string) ($repository->name ?? 'unknown'),
                $degreeByNode,
                $privateIdentityTokens[$this->graphNodeId($node)] ?? [],
                null,
                null,
                null,
                null,
                'local_analyzer',
                true,
            ),
            $previewNodes,
        );
        $previewNodeIds = array_fill_keys(array_column($graphNodes, 'id'), true);
        $previewEdges = array_values(array_filter(
            $normalizedRelationships,
            static fn (array $edge): bool => isset($previewNodeIds[$edge['from']], $previewNodeIds[$edge['to']]),
        ));
        $previewEdges = array_slice($previewEdges, 0, self::GRAPH_PREVIEW_EDGE_LIMIT);

        $graphEdges = array_map(
            fn (array $edge): array => [
                'id' => $edge['id'],
                'from' => $edge['from'],
                'to' => $edge['to'],
                'kind' => $this->graphEdgeKind((string) ($edge['type'] ?? 'uses')),
            ],
            $previewEdges,
        );
        $preview = $this->sanitizeGraphPreview($graphNodes, $graphEdges);

        return [
            'snapshot_id' => (string) $snapshot->id,
            'run_id' => $snapshot->created_by_run_id ? (string) $snapshot->created_by_run_id : null,
            'generated_at' => (string) $snapshot->created_at,
            'source' => $this->sourceMeta(type: 'local_analyzer', ref: (string) $snapshot->id),
            'stats' => [
                'nodes' => count($validNodes),
                'edges' => count($normalizedRelationships),
                'modules' => $nodeStats['modules'],
                'routes' => $nodeStats['routes'],
            ],
            'nodes' => $preview['nodes'],
            'edges' => $preview['edges'],
        ];
    }

    /** @return array<string, mixed> */
    private function canonicalGraph(string $projectId): array
    {
        $scopeMetadata = $this->canonicalGraphs->listScopeMetadata($projectId, self::CANONICAL_SCOPE_LIMIT);
        $scopes = $scopeMetadata['scopes'];

        if (count($scopes) !== 1) {
            return $this->canonicalGraphSelection(
                $projectId,
                $scopes === [] ? 'unavailable' : 'scope_required',
                array_map(fn (array $scope): array => $this->canonicalScopeMetadata($scope), $scopes),
                $scopeMetadata['truncated'],
            );
        }

        $scope = $scopes[0];
        $scopeType = (string) $scope['source_scope_type'];
        $scopeId = (string) $scope['source_scope_id'];
        $projection = $this->canonicalGraphProjectionWinner($projectId, $scopeType, $scopeId);
        if ($projection === null) {
            $latestProjection = $this->canonicalGraphProjectionState($projectId, $scopeType, $scopeId);
            if ($latestProjection === null
                || in_array((string) $latestProjection->status, ['queued', 'projecting'], true)) {
                return $this->canonicalGraphSelection($projectId, 'unavailable', [
                    $this->canonicalScopeMetadata($scope),
                ]);
            }

            return $this->canonicalGraphRebuildRequired(
                $projectId,
                [
                    'artifact_id' => (string) $latestProjection->artifact_id,
                    'created_at' => (string) ($latestProjection->created_at ?? $latestProjection->projected_at ?? now()),
                ],
                $scope,
                $latestProjection,
            );
        }

        $graph = $this->canonicalGraphs->findByIdentity(
            $projectId,
            $scopeType,
            $scopeId,
            (string) $projection->artifact_type,
            (string) $projection->artifact_id,
        );
        if ($graph === null) {
            return $this->canonicalGraphSelection($projectId, 'unavailable', [
                $this->canonicalScopeMetadata($scope),
            ]);
        }

        $identity = $graph['identity'];
        $activeGraphVersion = trim((string) ($projection?->active_graph_version ?? ''));
        if ($projection !== null
            && ($activeGraphVersion === '' || ! $this->canonicalProjectionKeyIsCurrent(
                $projectId,
                (string) $scope['source_scope_type'],
                (string) $scope['source_scope_id'],
                $activeGraphVersion,
            ))) {
            return $this->canonicalGraphRebuildRequired($projectId, $identity, $scope, $projection);
        }
        $nodes = array_values(array_filter($graph['nodes'] ?? [], 'is_array'));
        $relationships = $this->graphRelationships($graph['relationships'] ?? []);
        $degreeByNode = $this->graphDegrees($relationships);
        $trustedProducerRouteProvenance = is_array($graph['private_route_provenance'] ?? null)
            ? $graph['private_route_provenance']
            : [];
        $privateIdentityTokens = $this->graphPrivateIdentityTokenMap(
            $nodes,
            is_array($graph['private_identity_provenance'] ?? null) ? $graph['private_identity_provenance'] : [],
        );
        $nodeStats = $this->graphNodeStats($nodes, $privateIdentityTokens, false, $trustedProducerRouteProvenance);
        $previewNodes = $this->graphPreviewNodes($nodes, $relationships, null);
        $graphNodes = array_map(
            fn (array $node): array => $this->graphNode(
                $node,
                (string) $scope['source_scope_type'],
                $degreeByNode,
                $privateIdentityTokens[$this->graphNodeId($node)] ?? [],
                $projectId,
                (string) $scope['source_scope_type'],
                (string) $scope['source_scope_id'],
                $activeGraphVersion,
                'canonical_graph',
                isset($trustedProducerRouteProvenance[$this->graphNodeId($node)]),
            ),
            $previewNodes,
        );
        $graphNodes = array_values(array_filter(
            $graphNodes,
            fn (array $node): bool => $this->isGraphPreviewCanvasNode($node),
        ));
        $graphNodes = array_slice($graphNodes, 0, self::GRAPH_PREVIEW_NODE_LIMIT);
        $previewNodeIds = array_fill_keys(array_column($graphNodes, 'id'), true);
        $previewEdges = array_slice(array_values(array_filter(
            $relationships,
            static fn (array $edge): bool => isset($previewNodeIds[$edge['from']], $previewNodeIds[$edge['to']]),
        )), 0, self::GRAPH_PREVIEW_EDGE_LIMIT);
        $preview = $this->sanitizeGraphPreview(
            $graphNodes,
            array_map(fn (array $edge): array => [
                'id' => $edge['id'],
                'from' => $edge['from'], 'to' => $edge['to'],
                'kind' => $this->graphEdgeKind((string) ($edge['type'] ?? 'uses')),
            ], $previewEdges),
            allowFallbackLabel: false,
        );

        return [
            'snapshot_id' => null,
            'run_id' => null,
            'generated_at' => (string) $identity['created_at'],
            'source' => $this->sourceMeta(type: 'canonical_graph'),
            'source_scope' => [
                'type' => (string) $scope['source_scope_type'],
                'id' => (string) $scope['source_scope_id'],
            ],
            'graph_version' => $projection?->graph_version ? (string) $projection->graph_version : null,
            'active_graph_version' => $activeGraphVersion !== '' ? $activeGraphVersion : null,
            'quality' => (string) ($graph['contract']['extractor']['quality'] ?? $projection?->quality ?? 'unknown'),
            'projection_status' => $projection?->status ? (string) $projection->status : 'unavailable',
            'stats' => [
                'nodes' => count($nodes), 'edges' => count($relationships),
                ...$nodeStats,
            ],
            'nodes' => $preview['nodes'],
            'edges' => $preview['edges'],
        ];
    }

    private function canonicalGraphProjectionWinner(string $projectId, string $scopeType, string $scopeId): ?object
    {
        return DB::table('canonical_graph_projections')
            ->where('project_id', $projectId)
            ->where('source_scope_type', $scopeType)
            ->where('source_scope_id', $scopeId)
            ->where('status', 'ready')
            ->whereNotNull('active_graph_version')
            ->where('active_graph_version', '!=', '')
            ->orderByDesc('projected_at')
            ->orderByDesc('id')
            ->first();
    }

    private function canonicalGraphProjectionState(string $projectId, string $scopeType, string $scopeId): ?object
    {
        return DB::table('canonical_graph_projections')
            ->where('project_id', $projectId)
            ->where('source_scope_type', $scopeType)
            ->where('source_scope_id', $scopeId)
            ->orderByRaw("CASE status WHEN 'failed' THEN 3 WHEN 'stale' THEN 3 WHEN 'ready' THEN 2 WHEN 'projecting' THEN 1 WHEN 'queued' THEN 1 ELSE 0 END DESC")
            ->orderByDesc('projected_at')
            ->orderByDesc('id')
            ->first();
    }

    /** @param list<array<string, mixed>> $scopes */
    private function canonicalGraphSelection(string $projectId, string $status, array $scopes, bool $scopesTruncated = false): array
    {
        return [
            ...$this->emptyGraph($projectId),
            'source_scope' => null,
            'graph_version' => null,
            'quality' => null,
            'projection_status' => $status,
            'scopes' => $scopes,
            'scopes_truncated' => $scopesTruncated,
        ];
    }

    /** @param array{artifact_id: string, created_at: string} $identity */
    private function canonicalGraphRebuildRequired(
        string $projectId,
        array $identity,
        array $scope,
        object $projection,
    ): array {
        $activeGraphVersion = trim((string) ($projection->active_graph_version ?? ''));

        return [
            'snapshot_id' => null,
            'run_id' => null,
            'generated_at' => (string) $identity['created_at'],
            'source' => $this->sourceMeta(type: 'canonical_graph'),
            'source_scope' => [
                'type' => (string) $scope['source_scope_type'],
                'id' => (string) $scope['source_scope_id'],
            ],
            'graph_version' => $projection->graph_version ? (string) $projection->graph_version : null,
            'active_graph_version' => $activeGraphVersion !== '' ? $activeGraphVersion : null,
            'quality' => (string) ($projection->quality ?? 'unknown'),
            'projection_status' => 'graph_projection_rebuild_required',
            'stats' => [
                'nodes' => 0,
                'edges' => 0,
                'modules' => 0,
                'routes' => 0,
                'unknown_kind_count' => 0,
                'missing_label_count' => 0,
                'excluded_node_count' => 0,
            ],
            'nodes' => [],
            'edges' => [],
        ];
    }

    private function canonicalProjectionKeyIsCurrent(
        string $projectId,
        string $scopeType,
        string $scopeId,
        string $activeGraphVersion,
    ): bool {
        $handles = $this->publicHandles ?? new DashboardGraphPublicHandle;
        try {
            $client = $this->neo4j ?? app(Neo4jClientFactory::class)->client();
            $rows = $client->run(
                'MATCH (version:CanonicalGraphVersion {project_id: $project_id, source_scope_type: $source_scope_type, source_scope_id: $source_scope_id, graph_version: $active_graph_version}) '
                .'WHERE version.public_handle_key_version = $public_handle_key_version '
                .'AND version.public_handle_key_fingerprint = $public_handle_key_fingerprint '
                .'RETURN version.public_handle_key_version AS public_handle_key_version, '
                .'version.public_handle_key_fingerprint AS public_handle_key_fingerprint LIMIT 1',
                [
                    'project_id' => $projectId,
                    'source_scope_type' => $scopeType,
                    'source_scope_id' => $scopeId,
                    'active_graph_version' => $activeGraphVersion,
                    'public_handle_key_version' => $handles->keyVersion(),
                    'public_handle_key_fingerprint' => $handles->keyFingerprint(),
                ],
            );
        } catch (\Throwable) {
            return false;
        }
        foreach (Neo4jResultMaterializer::materializeRows($rows) as $row) {
            return $this->neo4jRowValue($row, 'public_handle_key_version') === $handles->keyVersion()
                && $this->neo4jRowValue($row, 'public_handle_key_fingerprint') === $handles->keyFingerprint();
        }

        return false;
    }

    private function neo4jRowValue(mixed $row, string $key): mixed
    {
        if (is_array($row)) {
            return $row[$key] ?? null;
        }

        if ($row instanceof \ArrayAccess && $row->offsetExists($key)) {
            return $row[$key];
        }

        if (is_object($row) && method_exists($row, 'toArray')) {
            $values = $row->toArray();

            return is_array($values) ? ($values[$key] ?? null) : null;
        }

        return is_object($row) && property_exists($row, $key) ? $row->{$key} : null;
    }

    /** @param array{source_scope_type: string, source_scope_id: string, quality: string|null, head_commit: string|null, created_at: string|null, projection_status: string} $scope */
    private function canonicalScopeMetadata(array $scope): array
    {
        return [
            'type' => $scope['source_scope_type'],
            'id' => $scope['source_scope_id'],
            'quality' => $scope['quality'],
            'head_commit' => $scope['head_commit'],
            'created_at' => $scope['created_at'],
            'projection_status' => $scope['projection_status'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyGraph(string $projectId): array
    {
        return [
            'snapshot_id' => null,
            'run_id' => null,
            'generated_at' => now()->toIso8601String(),
            'source' => $this->sourceMeta(
                type: 'local_analyzer',
                status: 'needs_verification',
                origin: 'DevBoard Laravel dashboard API',
                ref: $projectId,
            ),
            'stats' => [
                'nodes' => 0,
                'edges' => 0,
                'modules' => 0,
                'routes' => 0,
                'unknown_kind_count' => 0,
                'missing_label_count' => 0,
                'excluded_node_count' => 0,
            ],
            'nodes' => [],
            'edges' => [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function artifacts(?string $projectId = null): array
    {
        $this->abortUnlessProjectExists($projectId);

        return DB::table('artifacts')
            ->join('projects', 'projects.id', '=', 'artifacts.project_id')
            ->where('projects.status', '!=', 'deleted')
            ->when($projectId !== null, fn ($query) => $query->where('artifacts.project_id', $projectId))
            ->select('artifacts.*')
            ->orderByDesc('artifacts.created_at')
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
        $this->abortUnlessProjectReadable((string) $artifact->project_id);
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
            'status' => (string) $project->status,
            'archived_at' => $project->archived_at ? (string) $project->archived_at : null,
            'deleted_at' => $project->deleted_at ? (string) $project->deleted_at : null,
            'restored_at' => $project->restored_at ? (string) $project->restored_at : null,
            'updated_at' => (string) $project->updated_at,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function projectMemoryEntries(string $projectId, ?string $domain, string $query): array
    {
        $entries = DB::table('project_memory_entries')
            ->where('project_id', $projectId)
            ->when($domain === 'logbook', function ($builder): void {
                $builder->where(function ($nested): void {
                    $nested
                        ->whereNull('agent_key')
                        ->orWhere('agent_key', '');
                })->where('kind', '!=', 'agent_note')
                    ->whereNotIn('source', ['server_agent', 'hades_agent']);
            })
            ->when($domain === 'agent_notes', function ($builder): void {
                $builder->where(function ($nested): void {
                    $nested
                        ->where('kind', 'agent_note')
                        ->orWhereIn('source', ['server_agent', 'hades_agent'])
                        ->orWhereNotNull('agent_key');
                });
            })
            ->when($query !== '', function ($builder) use ($query): void {
                $like = '%'.$query.'%';
                $builder->where(function ($nested) use ($like): void {
                    $nested
                        ->where('summary', 'like', $like)
                        ->orWhere('payload', 'like', $like)
                        ->orWhere('kind', 'like', $like)
                        ->orWhere('source', 'like', $like)
                        ->orWhere('agent_key', 'like', $like);
                });
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (object $entry): array => $this->memoryEntry($entry))
            ->all();

        return $entries;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function wikiMemoryEntries(string $projectId, string $query): array
    {
        return DB::table('wiki_pages')
            ->join('wiki_revisions', 'wiki_revisions.id', '=', 'wiki_pages.current_revision_id')
            ->where('wiki_pages.project_id', $projectId)
            ->when($query !== '', function ($builder) use ($query): void {
                $like = '%'.$query.'%';
                $builder->where(function ($nested) use ($like): void {
                    $nested
                        ->where('wiki_pages.title', 'like', $like)
                        ->orWhere('wiki_pages.slug', 'like', $like)
                        ->orWhere('wiki_revisions.content_markdown', 'like', $like)
                        ->orWhere('wiki_revisions.source_status', 'like', $like);
                });
            })
            ->select([
                'wiki_pages.id as page_id',
                'wiki_pages.project_id',
                'wiki_pages.repository_id',
                'wiki_pages.slug',
                'wiki_pages.title',
                'wiki_pages.page_type',
                'wiki_pages.source_status as page_source_status',
                'wiki_pages.updated_at',
                'wiki_revisions.id as revision_id',
                'wiki_revisions.author_user_id',
                'wiki_revisions.source_type',
                'wiki_revisions.source_status',
                'wiki_revisions.content_markdown',
                'wiki_revisions.evidence_refs',
                'wiki_revisions.created_at as revision_created_at',
            ])
            ->orderByDesc('wiki_pages.updated_at')
            ->limit(100)
            ->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->revision_id,
                'project_id' => (string) $row->project_id,
                'repository_id' => $row->repository_id ? (string) $row->repository_id : null,
                'task_id' => null,
                'run_id' => null,
                'author_user_id' => $row->author_user_id === null ? null : (int) $row->author_user_id,
                'agent_key' => null,
                'source' => 'wiki_revision',
                'kind' => 'wiki',
                'domain' => 'wiki',
                'completeness' => (string) $row->source_status,
                'summary' => (string) $row->title,
                'payload' => [
                    'page_id' => (string) $row->page_id,
                    'page_slug' => (string) $row->slug,
                    'page_type' => (string) $row->page_type,
                    'page_source_status' => (string) $row->page_source_status,
                    'revision_source_status' => (string) $row->source_status,
                    'source_type' => (string) $row->source_type,
                    'content_excerpt' => $this->memoryExcerpt((string) $row->content_markdown),
                    'evidence_refs' => $this->decodeJsonList($row->evidence_refs),
                ],
                'occurred_at' => (string) $row->revision_created_at,
                'created_at' => (string) $row->revision_created_at,
            ])
            ->all();
    }

    /**
     * @return array{logbook: int, wiki: int, agent_notes: int}
     */
    private function memoryDomainCounts(string $projectId): array
    {
        $agentNotes = DB::table('project_memory_entries')
            ->where('project_id', $projectId)
            ->where(function ($nested): void {
                $nested
                    ->where('kind', 'agent_note')
                    ->orWhereIn('source', ['server_agent', 'hades_agent'])
                    ->orWhereNotNull('agent_key');
            })
            ->count();

        $logbook = DB::table('project_memory_entries')
            ->where('project_id', $projectId)
            ->where(function ($nested): void {
                $nested
                    ->whereNull('agent_key')
                    ->orWhere('agent_key', '');
            })
            ->where('kind', '!=', 'agent_note')
            ->whereNotIn('source', ['server_agent', 'hades_agent'])
            ->count();

        $wiki = DB::table('wiki_pages')
            ->join('wiki_revisions', 'wiki_revisions.id', '=', 'wiki_pages.current_revision_id')
            ->where('wiki_pages.project_id', $projectId)
            ->count();

        return [
            'logbook' => $logbook,
            'wiki' => $wiki,
            'agent_notes' => $agentNotes,
        ];
    }

    private function normalizeMemoryDomain(?string $domain): ?string
    {
        $domain = trim((string) $domain);

        if ($domain === '' || $domain === 'all') {
            return null;
        }

        abort_unless(in_array($domain, ['logbook', 'wiki', 'agent_notes'], true), 422, 'Unknown memory domain.');

        return $domain;
    }

    /**
     * @return array<string, mixed>
     */
    private function memoryEntry(object $entry): array
    {
        return [
            'id' => (string) $entry->id,
            'project_id' => (string) $entry->project_id,
            'repository_id' => $entry->repository_id ? (string) $entry->repository_id : null,
            'task_id' => $entry->task_id ? (string) $entry->task_id : null,
            'run_id' => $entry->run_id ? (string) $entry->run_id : null,
            'author_user_id' => $entry->author_user_id === null ? null : (int) $entry->author_user_id,
            'agent_key' => $entry->agent_key ? (string) $entry->agent_key : null,
            'source' => (string) $entry->source,
            'kind' => (string) $entry->kind,
            'domain' => $this->memoryDomain($entry),
            'completeness' => (string) $entry->completeness,
            'summary' => (string) $entry->summary,
            'payload' => json_decode((string) $entry->payload, true, flags: JSON_THROW_ON_ERROR),
            'occurred_at' => (string) $entry->occurred_at,
            'created_at' => (string) $entry->created_at,
        ];
    }

    private function memoryDomain(object $entry): string
    {
        if ((string) $entry->kind === 'agent_note'
            || in_array((string) $entry->source, ['server_agent', 'hades_agent'], true)
            || $entry->agent_key !== null) {
            return 'agent_notes';
        }

        return 'logbook';
    }

    private function memoryExcerpt(string $content): string
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', strip_tags($content)));

        return substr($normalized, 0, 500);
    }

    /**
     * @return array<string, mixed>
     */
    private function agentWorkItem(object $item): array
    {
        return [
            'id' => (string) $item->id,
            'project_id' => (string) $item->project_id,
            'repository_id' => $item->repository_id ? (string) $item->repository_id : null,
            'task_id' => $item->task_id ? (string) $item->task_id : null,
            'requested_by_user_id' => $item->requested_by_user_id === null ? null : (int) $item->requested_by_user_id,
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
            'archived_at' => $item->archived_at ? (string) $item->archived_at : null,
            'archived_by_user_id' => $item->archived_by_user_id === null ? null : (int) $item->archived_by_user_id,
            'archive_reason' => $item->archive_reason ? (string) $item->archive_reason : null,
            'created_at' => (string) $item->created_at,
            'updated_at' => (string) $item->updated_at,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function agentWorkEvents(string $workItemId): array
    {
        return DB::table('agent_work_item_events')
            ->where('agent_work_item_id', $workItemId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn (object $event): array => [
                'id' => (string) $event->id,
                'event_type' => (string) $event->event_type,
                'actor_user_id' => $event->actor_user_id === null ? null : (int) $event->actor_user_id,
                'actor_device_id' => $event->actor_device_id ? (string) $event->actor_device_id : null,
                'message' => $event->message ? (string) $event->message : null,
                'payload' => json_decode((string) $event->payload, true, flags: JSON_THROW_ON_ERROR),
                'created_at' => (string) $event->created_at,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function agentChatThread(object $thread): array
    {
        $latestMessage = DB::table('agent_chat_messages')
            ->where('agent_chat_thread_id', $thread->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return [
            'id' => (string) $thread->id,
            'project_id' => (string) $thread->project_id,
            'repository_id' => $thread->repository_id ? (string) $thread->repository_id : null,
            'task_id' => $thread->task_id ? (string) $thread->task_id : null,
            'created_by_user_id' => $thread->created_by_user_id === null ? null : (int) $thread->created_by_user_id,
            'agent_key' => (string) $thread->agent_key,
            'title' => (string) $thread->title,
            'status' => (string) $thread->status,
            'latest_agent_work_item_id' => $thread->latest_agent_work_item_id ? (string) $thread->latest_agent_work_item_id : null,
            'latest_assistant_run_id' => $thread->latest_assistant_run_id ? (string) $thread->latest_assistant_run_id : null,
            'last_message_at' => $thread->last_message_at ? (string) $thread->last_message_at : null,
            'archived_at' => $thread->archived_at ? (string) $thread->archived_at : null,
            'archived_by_user_id' => $thread->archived_by_user_id === null ? null : (int) $thread->archived_by_user_id,
            'archive_reason' => $thread->archive_reason ? (string) $thread->archive_reason : null,
            'message_count' => DB::table('agent_chat_messages')->where('agent_chat_thread_id', $thread->id)->count(),
            'last_message' => $latestMessage ? [
                'id' => (string) $latestMessage->id,
                'role' => (string) $latestMessage->role,
                'content' => (string) $latestMessage->content,
                'created_at' => (string) $latestMessage->created_at,
            ] : null,
            'metadata' => $this->decodeJson(is_string($thread->metadata ?? null) ? $thread->metadata : null),
            'created_at' => (string) $thread->created_at,
            'updated_at' => (string) $thread->updated_at,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function agentChatMessages(string $threadId): array
    {
        return DB::table('agent_chat_messages')
            ->where('agent_chat_thread_id', $threadId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(fn (object $message): array => [
                'id' => (string) $message->id,
                'thread_id' => (string) $message->agent_chat_thread_id,
                'role' => (string) $message->role,
                'author_user_id' => $message->author_user_id === null ? null : (int) $message->author_user_id,
                'assistant_run_id' => $message->assistant_run_id ? (string) $message->assistant_run_id : null,
                'agent_work_item_id' => $message->agent_work_item_id ? (string) $message->agent_work_item_id : null,
                'content' => (string) $message->content,
                'metadata' => $this->decodeJson(is_string($message->metadata ?? null) ? $message->metadata : null),
                'created_at' => (string) $message->created_at,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function assistantMessages(string $runId): array
    {
        return DB::table('assistant_messages')
            ->where('assistant_run_id', $runId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn (object $message): array => [
                'id' => (string) $message->id,
                'role' => (string) $message->role,
                'content' => (string) $message->content,
                'metadata' => json_decode((string) $message->metadata, true, flags: JSON_THROW_ON_ERROR),
                'created_at' => (string) $message->created_at,
            ])
            ->all();
    }

    private function resolveProject(?string $projectId): ?object
    {
        if ($projectId === null) {
            return DB::table('projects')->where('status', 'active')->orderBy('created_at')->first();
        }

        $project = DB::table('projects')->where('id', $projectId)->first();
        abort_unless($project && $project->status !== 'deleted', 404);

        return $project;
    }

    private function abortUnlessProjectExists(?string $projectId): void
    {
        if ($projectId === null) {
            return;
        }

        $this->abortUnlessProjectReadable($projectId);
    }

    private function abortUnlessProjectReadable(string $projectId): void
    {
        abort_unless(
            DB::table('projects')->where('id', $projectId)->where('status', '!=', 'deleted')->exists(),
            404,
        );
    }

    /**
     * @param  array<string, int>  $counts
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    private function countsWithDefaults(array $counts, array $keys): array
    {
        $normalized = [];

        foreach ($keys as $key) {
            $normalized[$key] = (int) ($counts[$key] ?? 0);
        }

        return $normalized;
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
                $localWorkspace = $this->latestLocalWorkspace((string) $repository->id);

                return [
                    'id' => (string) $repository->id,
                    'project_id' => (string) $repository->project_id,
                    'key' => (string) $repository->slug,
                    'name' => (string) $repository->name,
                    'default_branch' => (string) $repository->default_branch,
                    'git_mode' => 'local_clone',
                    'local_workspace' => $this->localWorkspaceState($localWorkspace),
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
    private function kickstart(string $projectId): array
    {
        $repositoryCount = (int) DB::table('repositories')
            ->where('project_id', $projectId)
            ->count();
        $linkedWorkspaceCount = (int) DB::table('local_workspaces')
            ->join('repositories', 'repositories.id', '=', 'local_workspaces.repository_id')
            ->where('repositories.project_id', $projectId)
            ->count();
        $genesisExists = DB::table('genesis_imports')
            ->where('project_id', $projectId)
            ->exists();
        $genesisActive = DB::table('genesis_imports')
            ->where('project_id', $projectId)
            ->whereIn('status', ['active', 'started', 'queued', 'running', 'uploading'])
            ->exists();

        $repositoryDeclared = $repositoryCount > 0;
        $workspaceLinked = $linkedWorkspaceCount > 0;

        $state = match (true) {
            ! $repositoryDeclared => 'awaiting_repository_declaration',
            ! $workspaceLinked => 'awaiting_local_workspace_link',
            ! $genesisExists => 'awaiting_genesis',
            $genesisActive => 'analyzing',
            default => 'active',
        };

        return [
            'state' => $state,
            'steps' => [
                [
                    'key' => 'project_intake',
                    'label' => 'Project intake',
                    'status' => 'complete',
                ],
                [
                    'key' => 'repository_declaration',
                    'label' => 'Repository declaration',
                    'status' => $repositoryDeclared ? 'complete' : 'current',
                ],
                [
                    'key' => 'local_workspace_link',
                    'label' => 'Local workspace link',
                    'status' => ! $repositoryDeclared ? 'pending' : ($workspaceLinked ? 'complete' : 'current'),
                ],
                [
                    'key' => 'genesis',
                    'label' => 'Genesis import',
                    'status' => ! $workspaceLinked ? 'pending' : ($genesisExists ? 'complete' : 'current'),
                ],
            ],
            'pairing' => [
                'api_base' => '/api/plugin/v1',
                'local_workspace_endpoint' => '/api/plugin/v1/repositories/{repository}/local-workspaces',
            ],
        ];
    }

    private function latestLocalWorkspace(string $repositoryId): ?object
    {
        return DB::table('local_workspaces')
            ->where('repository_id', $repositoryId)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('updated_at')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function localWorkspaceState(?object $workspace): array
    {
        if (! $workspace) {
            return [
                'status' => 'missing',
                'id' => null,
                'display_path' => null,
                'current_branch' => null,
                'last_head_sha' => null,
                'dirty_status' => null,
                'last_seen_at' => null,
                'remote_name' => null,
                'remote_url_host' => null,
                'remote_url_hash' => null,
                'upstream_branch' => null,
                'ahead_count' => null,
                'behind_count' => null,
                'git_state_observed_at' => null,
                'source_truth' => 'local_agent_reported',
            ];
        }

        return [
            'status' => 'linked',
            'id' => (string) $workspace->id,
            'device_id' => (string) $workspace->device_id,
            'display_path' => (string) $workspace->display_path,
            'current_branch' => (string) $workspace->current_branch,
            'last_head_sha' => $workspace->last_head_sha ? (string) $workspace->last_head_sha : null,
            'dirty_status' => (string) $workspace->dirty_status,
            'last_seen_at' => $workspace->last_seen_at ? (string) $workspace->last_seen_at : null,
            'remote_name' => $workspace->remote_name ? (string) $workspace->remote_name : null,
            'remote_url_host' => $workspace->remote_url_host ? (string) $workspace->remote_url_host : null,
            'remote_url_hash' => $workspace->remote_url_hash ? (string) $workspace->remote_url_hash : null,
            'upstream_branch' => $workspace->upstream_branch ? (string) $workspace->upstream_branch : null,
            'ahead_count' => $workspace->ahead_count === null ? null : (int) $workspace->ahead_count,
            'behind_count' => $workspace->behind_count === null ? null : (int) $workspace->behind_count,
            'git_state_observed_at' => $workspace->git_state_observed_at ? (string) $workspace->git_state_observed_at : null,
            'source_truth' => 'local_agent_reported',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taskCard(object $task): array
    {
        $column = DB::table('kanban_columns')->where('id', $task->status_column_id)->first();
        $owner = $task->owner_user_id ? DB::table('users')->where('id', $task->owner_user_id)->first() : null;
        $latestRun = DB::table('runs')->where('task_id', $task->id)->orderByDesc('created_at')->first();
        $repositories = $this->taskRepositories(
            (string) $task->id,
            (string) $task->project_id,
            $latestRun?->repository_id ? (string) $latestRun->repository_id : null,
        );
        $wikiPageId = $this->latestWikiPageId((string) $task->project_id);
        $attachmentCount = DB::table('task_attachments')
            ->where('task_id', $task->id)
            ->whereNull('deleted_at')
            ->count();
        $imageAttachmentCount = DB::table('task_attachments')
            ->where('task_id', $task->id)
            ->where('kind', 'image')
            ->whereNull('deleted_at')
            ->count();
        $agentWork = $this->taskAgentWork((string) $task->id);

        return [
            'id' => (string) $task->id,
            'title' => (string) $task->title,
            'column' => $this->taskColumn((string) ($column->status_key ?? 'backlog')),
            'owner' => (string) ($owner->name ?? 'Unassigned'),
            'owner_color' => $this->avatarColor((string) ($owner->email ?? $task->id)),
            'risk' => $this->risk((string) $task->risk_level),
            'project_id' => (string) $task->project_id,
            'repositories' => $repositories,
            'linked_run_id' => $latestRun?->id ? (string) $latestRun->id : null,
            'linked_run_status' => $latestRun ? $this->runStatus((string) $latestRun->status) : null,
            'wiki_page_id' => $wikiPageId,
            'attachment_count' => (int) $attachmentCount,
            'image_attachment_count' => (int) $imageAttachmentCount,
            'agent_work' => $agentWork,
            'source_status' => 'verified_from_code',
            'blocked' => $task->risk_level === 'high',
            'blocked_reason' => $task->risk_level === 'high' ? 'High risk task requires review.' : null,
            'updated_at' => (string) $task->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taskAgentWork(string $taskId): array
    {
        $items = DB::table('agent_work_items')
            ->where('task_id', $taskId)
            ->whereNull('archived_at')
            ->orderByDesc('created_at')
            ->get();

        if ($items->isEmpty()) {
            return [
                'status' => 'not_assigned',
                'label' => 'Not assigned',
                'count' => 0,
                'by_status' => [],
                'latest_work_item_id' => null,
                'latest_status' => null,
                'assigned_agent_key' => null,
                'next_step' => 'Assign local agent when the task is ready for agent work.',
            ];
        }

        $byStatus = [];
        foreach ($items as $item) {
            $status = (string) $item->status;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
        }

        $latest = $items->first();
        $status = $this->agentWorkAggregateStatus($byStatus, (string) $latest->status);

        return [
            'status' => $status,
            'label' => $this->agentWorkStatusLabel($status),
            'count' => $items->count(),
            'by_status' => $byStatus,
            'latest_work_item_id' => (string) $latest->id,
            'latest_status' => (string) $latest->status,
            'assigned_agent_key' => (string) $latest->assigned_agent_key,
            'next_step' => $this->agentWorkNextStep($status),
        ];
    }

    /**
     * @param  array<string, int>  $byStatus
     */
    private function agentWorkAggregateStatus(array $byStatus, string $fallback): string
    {
        foreach (['failed', 'claimed', 'running', 'queued', 'completed', 'canceled'] as $status) {
            if (($byStatus[$status] ?? 0) > 0) {
                return $status;
            }
        }

        return $fallback !== '' ? $fallback : 'unknown';
    }

    private function agentWorkStatusLabel(string $status): string
    {
        return match ($status) {
            'queued' => 'Queued for local agent',
            'claimed', 'running' => 'Local agent running',
            'completed' => 'Agent work completed',
            'failed' => 'Agent work failed',
            'canceled' => 'Agent work canceled',
            default => 'Agent work '.$status,
        };
    }

    private function agentWorkNextStep(string $status): string
    {
        return match ($status) {
            'queued' => 'Run the local Hades task worker to claim this task.',
            'claimed', 'running' => 'Wait for heartbeat or inspect the active local worker.',
            'completed' => 'Review the agent result and linked memory/evidence.',
            'failed' => 'Open the work item events and retry after fixing the failure.',
            'canceled' => 'Reassign the task if agent work is still needed.',
            default => 'Assign local agent when the task is ready for agent work.',
        };
    }

    /**
     * @return list<string>
     */
    private function jsonList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $item): string => trim((string) $item),
            $decoded,
        ), fn (string $item): bool => $item !== ''));
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function taskRepositories(string $taskId, string $projectId, ?string $latestRunRepositoryId): array
    {
        $repositoryIds = DB::table('repository_task')
            ->where('task_id', $taskId)
            ->pluck('repository_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        if ($repositoryIds === [] && $latestRunRepositoryId !== null) {
            $repositoryIds = [$latestRunRepositoryId];
        }

        if ($repositoryIds === []) {
            return [];
        }

        return DB::table('repositories')
            ->where('project_id', $projectId)
            ->whereIn('id', $repositoryIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (object $repository): array => [
                'id' => (string) $repository->id,
                'name' => (string) $repository->name,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function taskAttachments(string $taskId): array
    {
        return DB::table('task_attachments')
            ->where('task_id', $taskId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (object $attachment): array => $this->taskAttachmentRow($attachment))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function taskAttachmentRow(object $attachment): array
    {
        $taskId = (string) $attachment->task_id;
        $attachmentId = (string) $attachment->id;
        $downloadUrl = "/api/dashboard/tasks/{$taskId}/attachments/{$attachmentId}/download";

        return [
            'id' => $attachmentId,
            'task_id' => $taskId,
            'project_id' => (string) $attachment->project_id,
            'name' => (string) $attachment->original_name,
            'mime_type' => (string) $attachment->mime_type,
            'kind' => (string) $attachment->kind,
            'status' => (string) $attachment->status,
            'scan_status' => (string) $attachment->scan_status,
            'size_bytes' => (int) $attachment->size_bytes,
            'uploaded_at' => (string) $attachment->created_at,
            'uploaded_by' => $this->userName($attachment->uploaded_by_user_id),
            'download_url' => $downloadUrl,
            'preview_url' => $attachment->kind === 'image' ? $downloadUrl : null,
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
     * @param  Collection<int, object>  $events
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
     * @param  Collection<int, object>  $artifacts
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
     * @param  Collection<int, object>  $artifacts
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
     * @param  array<string, mixed>  $node
     * @param  array<string, int>  $degreeByNode
     * @param  array<string, true>  $privateIdentityTokens
     * @return array<string, mixed>
     */
    private function graphNode(
        array $node,
        string $repository,
        array $degreeByNode,
        array $privateIdentityTokens = [],
        ?string $projectId = null,
        ?string $scopeType = null,
        ?string $scopeId = null,
        ?string $activeGraphVersion = null,
        string $sourceType = 'local_analyzer',
        bool $trustedProducerRoute = false,
    ): array
    {
        $id = $this->graphNodeId($node);
        $kind = $this->graphNodeSemanticKind($node);
        $handle = null;
        if ($projectId !== null && $scopeType !== null && $scopeId !== null && $activeGraphVersion !== null) {
            try {
                $handle = ($this->publicHandles ?? new DashboardGraphPublicHandle)->forNode(
                    $projectId,
                    $scopeType,
                    $scopeId,
                    $activeGraphVersion,
                    $id,
                );
            } catch (\InvalidArgumentException) {
                $handle = null;
            }
        }

        $result = [
            'id' => $id,
            'label' => $this->graphNodePublicLabel($node, $kind, $privateIdentityTokens, $trustedProducerRoute),
            'kind' => ($this->publicKinds ?? new DashboardGraphPublicKind)->map($kind),
            'repository' => $repository,
            'degree' => $degreeByNode[$id] ?? 0,
            'risk' => 'medium',
            'source' => $this->sourceMeta(type: $sourceType, ref: $id),
        ];
        if ($handle !== null) {
            $result['handle'] = $handle;
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private function sanitizeGraphPreview(array $nodes, array $edges, bool $allowFallbackLabel = true): array
    {
        $rawNodeIds = array_map(
            static fn (array $node): string => (string) ($node['id'] ?? 'node'),
            $nodes,
        );
        $idMap = $this->graphPublicIdentifierMap($rawNodeIds, 'node');
        $sanitizedNodes = [];
        $seenRawNodeIds = [];

        foreach ($nodes as $node) {
            $rawId = (string) ($node['id'] ?? 'node');
            if (isset($seenRawNodeIds[$rawId])) {
                continue;
            }
            $seenRawNodeIds[$rawId] = true;

            $publicId = $idMap[$rawId];
            $node['id'] = $publicId;
            $publicLabel = is_string($node['label'] ?? null) && trim($node['label']) !== ''
                ? $node['label']
                : ($allowFallbackLabel ? (string) ($node['kind'] ?? 'service').' '.$publicId : null);
            if (is_array($node['source'] ?? null)) {
                $node['source']['ref'] = $publicId;
            }
            $node = $this->sanitizeGraphPreviewValue($node);
            if ($publicLabel !== null
                && (($node['kind'] ?? null) !== 'route'
                    || $this->isGraphRoutePath($publicLabel)
                    || $this->isGraphHttpRouteLabel($publicLabel))) {
                $node['label'] = $publicLabel;
            } else {
                unset($node['label']);
            }
            $sanitizedNodes[] = $node;
        }

        $eligibleEdges = [];
        $signatureOccurrences = [];
        foreach ($edges as $edge) {
            $rawFrom = (string) ($edge['from'] ?? '');
            $rawTo = (string) ($edge['to'] ?? '');
            if (! isset($idMap[$rawFrom], $idMap[$rawTo])) {
                continue;
            }

            $publicFrom = $idMap[$rawFrom];
            $publicTo = $idMap[$rawTo];
            $kind = (string) ($edge['kind'] ?? 'uses');
            $signature = json_encode([
                $kind,
                $publicFrom,
                $publicTo,
            ], JSON_THROW_ON_ERROR);
            $occurrence = $signatureOccurrences[$signature] ?? 0;
            $signatureOccurrences[$signature] = $occurrence + 1;
            $identity = $signature."\0".$occurrence;
            $eligibleEdges[] = [
                'edge' => $edge,
                'raw_from' => $rawFrom,
                'raw_to' => $rawTo,
                'identity' => $identity,
            ];
        }

        $edgeCandidates = [];
        foreach ($eligibleEdges as $record) {
            $edgeCandidates[$record['identity']] = $this->graphPublicIdPrefix('edge')
                .hash('sha256', "edge\0".$record['identity']);
        }
        $edgeIdMap = $this->resolveGraphPublicIdCandidates($edgeCandidates, 'edge');

        $sanitizedEdges = [];
        foreach ($eligibleEdges as $record) {
            $edge = $record['edge'];
            $edge['id'] = $edgeIdMap[$record['identity']];
            $rawFrom = $record['raw_from'];
            $rawTo = $record['raw_to'];
            $edge['from'] = $idMap[$rawFrom];
            $edge['to'] = $idMap[$rawTo];
            $sanitizedEdges[] = $this->sanitizeGraphPreviewValue($edge);
        }

        return ['nodes' => $sanitizedNodes, 'edges' => $sanitizedEdges];
    }

    /**
     * @param  list<string>  $identifiers
     * @return array<string, string>
     */
    private function graphPublicIdentifierMap(array $identifiers, string $kind): array
    {
        $prefix = $this->graphPublicIdPrefix($kind);
        $identifiers = array_values(array_unique($identifiers));
        sort($identifiers, SORT_STRING);
        $candidates = [];

        foreach ($identifiers as $identifier) {
            $candidates[$identifier] = $prefix.hash('sha256', $kind."\0".$identifier);
        }

        return $this->resolveGraphPublicIdCandidates($candidates, $kind);
    }

    /**
     * Resolve against the complete candidate set, in sorted semantic-identity order.
     * This makes collision expansion independent of artifact ordering.
     *
     * @param  array<string, string>  $candidates
     * @return array<string, string>
     */
    private function resolveGraphPublicIdCandidates(array $candidates, string $kind): array
    {
        ksort($candidates, SORT_STRING);
        $prefix = $this->graphPublicIdPrefix($kind);
        $resolved = [];
        $used = [];

        foreach ($candidates as $identity => $candidate) {
            $attempt = 0;
            while (isset($used[$candidate])) {
                $attempt++;
                $candidate = $prefix.hash('sha256', $identity."\0collision\0".$attempt);
            }
            $resolved[$identity] = $candidate;
            $used[$candidate] = true;
        }

        return $resolved;
    }

    private function graphPublicIdPrefix(string $kind): string
    {
        return "hades-public-v1-{$kind}-";
    }

    private function sanitizeGraphPreviewValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->looksLikeLocalPath($value) ? '[redacted]' : $value;
        }
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->sanitizeGraphPreviewValue($child);
        }

        return $value;
    }

    private function looksLikeLocalPath(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }
        if ($this->isGraphHttpRouteLabel($trimmed) || $this->isGraphRoutePath($trimmed)) {
            return false;
        }
        if ($this->isGraphFilesystemIdentity($trimmed)) {
            return true;
        }

        // Prefixes are intentionally not enumerated. A path payload may follow any
        // semantic wrapper (for example `symbol:`) or ordinary token delimiter.
        $payloadBoundary = "(?:^|[\\s'\"()\\[\\]{}<>=,;|:])";

        if (preg_match("~{$payloadBoundary}file:(?:/{1,3}|[a-z]:[\\\\/]|\\\\\\\\)~i", $trimmed) === 1) {
            return true;
        }

        if (preg_match("~{$payloadBoundary}[a-z]:[\\\\/]~i", $trimmed) === 1) {
            return true;
        }

        if (preg_match("~{$payloadBoundary}\\\\\\\\[^\\\\/\\s]+[\\\\/]~", $trimmed) === 1) {
            return true;
        }

        preg_match_all(
            "~{$payloadBoundary}/(?!/)([a-z0-9._-]+)(?=[\\\\/]|$|[\\s'\"()\\[\\]{}<>=,;|?#])~i",
            $trimmed,
            $matches,
        );

        foreach ($matches[1] ?? [] as $root) {
            if ($this->isGraphWebRouteRoot($root)) {
                continue;
            }

            if ($this->isSensitiveFilesystemRoot($root)) {
                return true;
            }
        }

        return false;
    }

    private function isGraphFilesystemIdentity(string $value): bool
    {
        $boundary = "(?:\A|[\s'\"()\\[\\]{}<>=,;|:])";
        if (preg_match('~'.$boundary.'file://~i', $value) === 1
            || preg_match('~'.$boundary.'[a-z]:[\\\\/]~i', $value) === 1
            || preg_match('~'.$boundary.'[a-z]:[a-z0-9_.-]*[\\\\/]~i', $value) === 1
            || preg_match('~'.$boundary.'(?:\\\\\\\\|//)~', $value) === 1
            || preg_match('~'.$boundary.'\.\.?[\\\\/]~', $value) === 1
            || preg_match('~'.$boundary.'/~', $value) === 1) {
            return true;
        }
        if (str_contains($value, chr(92)) && ! $this->isValidGraphFqcn($value)) {
            return true;
        }

        return preg_match('~'.$boundary.'(?:[A-Za-z0-9_.-]+)[\\\\/][^\s]+~', $value) === 1;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array{from: string, to: string}>  $relationships
     * @return list<array<string, mixed>>
     */
    private function graphPreviewNodes(array $nodes, array $relationships, ?int $limit = self::GRAPH_PREVIEW_NODE_LIMIT): array
    {
        $nodeById = [];
        foreach ($nodes as $node) {
            $id = $this->graphNodeId($node);
            if ($id !== '' && ! isset($nodeById[$id])) {
                $nodeById[$id] = $node;
            }
        }

        $previewNodeIds = [];
        foreach ($relationships as $relationship) {
            foreach ([$relationship['from'], $relationship['to']] as $id) {
                if ($limit !== null && count($previewNodeIds) >= $limit) {
                    break 2;
                }

                if (isset($nodeById[$id]) && ! isset($previewNodeIds[$id])) {
                    $previewNodeIds[$id] = true;
                }
            }
        }

        foreach ($nodes as $node) {
            if ($limit !== null && count($previewNodeIds) >= $limit) {
                break;
            }

            $id = $this->graphNodeId($node);
            if ($id !== '' && ! isset($previewNodeIds[$id])) {
                $previewNodeIds[$id] = true;
            }
        }

        $previewNodes = [];
        foreach (array_keys($previewNodeIds) as $id) {
            $previewNodes[] = $nodeById[$id];
        }

        return $previewNodes;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function graphNodeId(array $node): string
    {
        $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];

        return (string) ($node['id'] ?? $properties['id'] ?? 'node');
    }

    /**
     * @param  list<mixed>  $relationships
     * @return list<array{id: string|null, from: string, to: string, type: string|null}>
     */
    private function graphRelationships(array $relationships): array
    {
        $normalized = [];

        foreach ($relationships as $relationship) {
            if (! is_array($relationship)) {
                continue;
            }

            $from = $relationship['from'] ?? $relationship['source_id'] ?? $relationship['source_symbol_id'] ?? null;
            $to = $relationship['to'] ?? $relationship['target_id'] ?? $relationship['target_symbol_id'] ?? null;

            if (! is_string($from) || ! is_string($to) || $from === '' || $to === '') {
                continue;
            }

            $normalized[] = [
                'id' => isset($relationship['id']) ? (string) $relationship['id'] : null,
                'from' => $from,
                'to' => $to,
                'type' => isset($relationship['type']) ? (string) $relationship['type'] : null,
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array{from: string, to: string}>  $relationships
     * @return array<string, int>
     */
    private function graphDegrees(array $relationships): array
    {
        $degreeByNode = [];

        foreach ($relationships as $relationship) {
            $degreeByNode[$relationship['from']] = ($degreeByNode[$relationship['from']] ?? 0) + 1;
            $degreeByNode[$relationship['to']] = ($degreeByNode[$relationship['to']] ?? 0) + 1;
        }

        return $degreeByNode;
    }

    /**
     * @param  list<mixed>  $nodes
     * @return array{modules: int, routes: int}
     */
    private function graphNodeStats(array $nodes, array $privateIdentityTokens = [], bool $trustedProducerRoutes = false, array $trustedProducerRouteProvenance = []): array
    {
        $stats = [
            'modules' => 0,
            'routes' => 0,
            'unknown_kind_count' => 0,
            'missing_label_count' => 0,
            'excluded_node_count' => 0,
        ];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $kind = $this->graphNodeKind(is_array($node['labels'] ?? null) ? $node['labels'] : []);
            if ($kind === 'module') {
                $stats['modules']++;
            }

            if ($kind === 'route') {
                $stats['routes']++;
            }

            $semanticKind = $this->graphNodeSemanticKind($node);
            if ($semanticKind === 'unknown') {
                $stats['unknown_kind_count']++;
            }

            $label = $this->graphNodePublicLabel(
                $node,
                $semanticKind,
                $privateIdentityTokens[$this->graphNodeId($node)] ?? [],
                $trustedProducerRoutes || isset($trustedProducerRouteProvenance[$this->graphNodeId($node)]),
            );
            if ($label === null) {
                $stats['missing_label_count']++;
            }

            if ($semanticKind === 'unknown' || $label === null) {
                $stats['excluded_node_count']++;
            }
        }

        return $stats;
    }

    /** @param array<string, mixed> $node */
    private function isGraphPreviewCanvasNode(array $node): bool
    {
        return ($node['kind'] ?? 'unknown') !== 'unknown'
            && is_string($node['label'] ?? null)
            && trim($node['label']) !== '';
    }

    /**
     * @param  list<mixed>  $labels
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

    /** @param array<string, mixed> $node */
    private function graphNodeSemanticKind(array $node): string
    {
        $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];
        $types = [];
        if (is_string($properties['kind'] ?? null)) {
            $types[] = strtolower(trim($properties['kind']));
        }
        foreach (is_array($node['labels'] ?? null) ? $node['labels'] : [] as $label) {
            $types[] = strtolower(trim((string) $label));
        }

        $recognizedKinds = [];
        $hasUnknownType = false;
        foreach (array_values(array_unique($types)) as $type) {
            if ($type === '' || $type === 'symbol') {
                continue;
            }

            $recognizedKind = null;
            foreach (self::GRAPH_PREVIEW_NODE_POLICY as $publicKind => $policy) {
                if (in_array($type, $policy['types'], true)) {
                    $recognizedKind = $publicKind;
                    break;
                }
            }

            if ($recognizedKind === null) {
                $hasUnknownType = true;

                continue;
            }

            $recognizedKinds[$recognizedKind] = true;
        }

        if ($hasUnknownType || count($recognizedKinds) !== 1) {
            return 'unknown';
        }

        return (string) array_key_first($recognizedKinds);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, true>  $privateIdentityTokens
     */
    private function graphNodePublicLabel(array $node, string $kind, array $privateIdentityTokens, bool $trustedProducerRoute = false): ?string
    {
        $policy = self::GRAPH_PREVIEW_NODE_POLICY[$kind] ?? null;
        if ($policy === null) {
            return null;
        }
        $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];

        $label = null;
        if ($kind === 'route') {
            $label = $this->graphRouteLabel($properties, $policy['label_fields'], $trustedProducerRoute);
        } else {
            foreach ($policy['label_fields'] as $field) {
                $value = $properties[$field] ?? null;
                if (! is_string($value)) {
                    continue;
                }
                $label = $kind === 'file'
                    ? $this->graphFileLabel($value)
                    : $this->graphSymbolLabel($value);
                if ($label !== null) {
                    break;
                }
            }
        }

        if ($label === null) {
            return null;
        }

        foreach ($this->graphPublicLabelComparisonTokens($label) as $token) {
            if (isset($privateIdentityTokens[$token])) {
                return null;
            }
        }

        return $label;
    }

    /**
     * Build exact, case-folded comparison tokens from producer-controlled
     * identity fields. Path components and stems are tokens in their own right;
     * arbitrary substrings are not, avoiding accidental suppression of safe
     * labels that merely contain part of an identity.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, list<string>>  $capturedProvenance
     * @return array<string, array<string, true>>
     */
    private function graphPrivateIdentityTokenMap(array $nodes, array $capturedProvenance = []): array
    {
        $tokensByNode = [];

        foreach ($nodes as $node) {
            $nodeId = $this->graphNodeId($node);
            $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];
            $publicRoutePaths = $this->graphPublicRoutePathValues($node, $properties);
            $identityValues = is_array($capturedProvenance[$nodeId] ?? null)
                ? $capturedProvenance[$nodeId]
                : [];
            if ($publicRoutePaths !== []) {
                $identityValues = array_values(array_filter(
                    $identityValues,
                    static fn (mixed $value): bool => ! is_string($value) || ! in_array($value, $publicRoutePaths, true),
                ));
            }

            foreach ([$node, $properties] as $identityContainer) {
                foreach (['id', 'external_id', 'symbol_id', 'source_ref', 'source_path', 'path'] as $field) {
                    $value = $identityContainer[$field] ?? null;
                    if (is_string($value)
                        && ($field !== 'path' || ! in_array($value, $publicRoutePaths, true))) {
                        $identityValues[] = $value;
                    }
                }

                $source = $identityContainer['source'] ?? null;
                if (! is_array($source)) {
                    continue;
                }
                foreach (['ref', 'path', 'id', 'external_id', 'symbol_id'] as $field) {
                    if (is_string($source[$field] ?? null)) {
                        $identityValues[] = $source[$field];
                    }
                }
            }

            $tokens = $tokensByNode[$nodeId] ?? [];
            foreach ($identityValues as $identityValue) {
                if (! is_string($identityValue)) {
                    continue;
                }
                foreach ($this->graphIdentityComparisonTokens($identityValue) as $token) {
                    $tokens[$token] = true;
                }
            }
            $tokensByNode[$nodeId] = $tokens;
        }

        return $tokensByNode;
    }

    /**
     * A route's direct `path` is approved presentation data, not filesystem
     * provenance. Nested `source.path` remains private and is never exempted.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $properties
     * @return list<string>
     */
    private function graphPublicRoutePathValues(array $node, array $properties): array
    {
        if ($this->graphNodeSemanticKind($node) !== 'route') {
            return [];
        }

        $paths = [];
        foreach ([$node['path'] ?? null, $properties['path'] ?? null] as $path) {
            if (is_string($path) && $this->isGraphRoutePath(trim($path))) {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    /** @return list<string> */
    private function graphIdentityComparisonTokens(string $identity): array
    {
        $normalized = $this->normalizeGraphIdentityToken($identity);
        if ($normalized === '') {
            return [];
        }
        $tokens = [$normalized => true];
        $namespaceToken = $this->graphCodeNamespaceComparisonToken($identity, true);
        if ($namespaceToken !== null) {
            $tokens[$namespaceToken] = true;
        }
        $path = preg_replace('/\Afile:\/\//i', '', trim($identity)) ?? trim($identity);
        $path = str_replace('\\', '/', rawurldecode($path));

        foreach (explode('/', $path) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }
            $token = $this->normalizeGraphIdentityToken($segment);
            $tokens[$token] = true;

            $stem = pathinfo($segment, PATHINFO_FILENAME);
            if ($stem !== '' && $stem !== $segment) {
                $tokens[$this->normalizeGraphIdentityToken($stem)] = true;
            }
        }

        return array_keys(array_filter($tokens, static fn (bool $present, string $token): bool => $present && $token !== '', ARRAY_FILTER_USE_BOTH));
    }

    private function normalizeGraphIdentityToken(string $identity): string
    {
        return strtolower(trim($identity));
    }

    /** @return list<string> */
    private function graphPublicLabelComparisonTokens(string $label): array
    {
        $tokens = [$this->normalizeGraphIdentityToken($label)];
        $namespaceToken = $this->graphCodeNamespaceComparisonToken($label, false);
        if ($namespaceToken !== null) {
            $tokens[] = $namespaceToken;
        }

        return array_values(array_unique(array_filter($tokens, static fn (string $token): bool => $token !== '')));
    }

    private function graphCodeNamespaceComparisonToken(string $identity, bool $requireCodeEvidence): ?string
    {
        $identity = trim($identity);
        if (preg_match('/\A[A-Za-z_$][A-Za-z0-9_$]*(?:(?:\\\\|::|\.)[A-Za-z_$][A-Za-z0-9_$]*)+\z/', $identity) !== 1) {
            return null;
        }
        if ($requireCodeEvidence
            && ! str_contains($identity, '\\')
            && ! str_contains($identity, '::')
            && preg_match('/[A-Z_$]/', $identity) !== 1) {
            return null;
        }

        $canonical = preg_replace('/(?:\\\\|::|\.)/', '::', $identity) ?? $identity;

        return 'namespace:'.strtolower($canonical);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  list<string>  $labelFields
     */
    private function graphRouteLabel(array $properties, array $labelFields, bool $trustedProducerRoute = false): ?string
    {
        if (! $trustedProducerRoute) {
            return null;
        }
        foreach ($labelFields as $field) {
            if (! is_string($properties[$field] ?? null)) {
                continue;
            }
            $candidate = trim($properties[$field]);
            if ($this->isGraphHttpRouteLabel($candidate) || $this->isGraphRoutePath($candidate)) {
                return $candidate;
            }
        }

        $method = $properties['http_method'] ?? $properties['method'] ?? $properties['verb'] ?? null;
        $path = $properties['path'] ?? $properties['uri'] ?? $properties['route'] ?? $properties['url'] ?? null;
        if (is_string($method) && is_string($path)) {
            $method = strtoupper(trim($method));
            $path = trim($path);
            if (preg_match('/\A(?:GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS|CONNECT|TRACE)\z/', $method) === 1
                && $this->isGraphRoutePath($path)) {
                return $method.' '.$path;
            }
        }

        return null;
    }

    private function isGraphHttpRouteLabel(string $value): bool
    {
        if (preg_match('/\A(?:GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS|CONNECT|TRACE)\s+(?<path>\/.*)\z/', $value, $matches) !== 1) {
            return false;
        }

        return $this->isGraphRoutePath((string) $matches['path']);
    }

    private function isGraphRoutePath(string $value): bool
    {
        return strlen($value) <= 512
            && preg_match('#\A/(?!/)(?!.*(?:^|/)\.\.(?:/|$))[A-Za-z0-9._~!$&\x27()*+,;=:@%{}\-/]*\z#', $value) === 1
            && preg_match('/(?:\A|\/)[^\/{}?*]+\.(?:php|phar|inc|phtml|ts|tsx|js|jsx|mjs|cjs|py|rb|go|java|kt|kts|rs|c|cc|cpp|h|hpp|swift|dart|vue|svelte|sql|yaml|yml|json|xml|toml|ini|env)(?:\/|\z)/i', $value) !== 1
            && ! str_contains($value, '\\')
            && stripos($value, 'file://') === false;
    }

    private function isValidGraphFqcn(string $value): bool
    {
        return preg_match('/\A\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+\z/D', $value) === 1;
    }

    private function graphFileLabel(string $value): ?string
    {
        $basename = basename(str_replace('\\', '/', trim($value)));

        return $basename !== ''
            && $basename !== '.'
            && $basename !== '..'
            && strlen($basename) <= 200
            && preg_match('/\A[A-Za-z0-9_@.-]+\z/', $basename) === 1
                ? $basename
                : null;
    }

    private function graphSymbolLabel(string $value): ?string
    {
        $value = trim($value);

        return strlen($value) <= 200
            && ($this->isValidGraphFqcn($value)
                || preg_match('/\A[A-Za-z_$][A-Za-z0-9_$]*(?:(?:\\\\|::|\.)[A-Za-z_$][A-Za-z0-9_$]*)*\z/', $value) === 1)
                ? $value
                : null;
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
     * @return list<mixed>
     */
    private function decodeJsonList(mixed $payload): array
    {
        $decoded = is_string($payload) ? json_decode($payload, true) : $payload;

        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
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
