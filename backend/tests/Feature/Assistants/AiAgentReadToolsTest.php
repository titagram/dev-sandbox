<?php

use App\Assistants\Agents\BacklogTriageAgent;
use App\Assistants\Agents\TaskClarifierAgent;
use App\Assistants\AiAgentToolRegistry;
use App\Assistants\Tools\QueryProjectGraphTool;
use App\Assistants\Tools\ReadProjectSummaryTool;
use App\Assistants\Tools\ReadProjectTasksTool;
use App\Assistants\Tools\ReadTaskDetailTool;
use App\Assistants\Tools\SearchProjectMemoryTool;
use App\Assistants\Tools\SearchWikiRevisionsTool;
use App\Assistants\Tools\WriteWikiRevisionTool;
use App\Models\User;
use App\Services\Graph\GraphQueryService;
use App\Services\Neo4j\FakeNeo4jClient;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DevBoardSeeder::class);
});

it('registers read-only tools matching the controlled task clarifier profile', function () {
    expect(class_exists(AiAgentToolRegistry::class))->toBeTrue();

    $registry = app(AiAgentToolRegistry::class);
    $tools = $registry->forAgentKey('task_clarifier');
    $toolNames = array_map(fn (Tool $tool): string => $tool->name(), $tools);
    $allowedTools = json_decode((string) DB::table('ai_agent_profiles')->where('agent_key', 'task_clarifier')->value('allowed_tools'), true, flags: JSON_THROW_ON_ERROR);

    expect($tools)->toHaveCount(4)
        ->and($toolNames)->toEqualCanonicalizing(['read_project_summary', 'read_task_detail', 'search_project_memory', 'search_wiki_revisions'])
        ->and(array_values(array_intersect($allowedTools, $toolNames)))->toEqualCanonicalizing($toolNames)
        ->and(TaskClarifierAgent::make())->toBeInstanceOf(HasTools::class)
        ->and(array_map(fn (Tool $tool): string => $tool->name(), [...TaskClarifierAgent::make()->tools()]))
        ->toEqualCanonicalizing($toolNames);
});

it('registers read-only tools matching the controlled backlog triage profile', function () {
    expect(class_exists(BacklogTriageAgent::class))->toBeTrue();

    $registry = app(AiAgentToolRegistry::class);
    $tools = $registry->forAgentKey('backlog_triage');
    $toolNames = array_map(fn (Tool $tool): string => $tool->name(), $tools);
    $allowedTools = json_decode((string) DB::table('ai_agent_profiles')->where('agent_key', 'backlog_triage')->value('allowed_tools'), true, flags: JSON_THROW_ON_ERROR);

    expect($tools)->toHaveCount(3)
        ->and($toolNames)->toEqualCanonicalizing(['read_project_summary', 'read_project_tasks', 'search_project_memory'])
        ->and(array_values(array_intersect($allowedTools, $toolNames)))->toEqualCanonicalizing($toolNames)
        ->and(BacklogTriageAgent::make())->toBeInstanceOf(HasTools::class)
        ->and(array_map(fn (Tool $tool): string => $tool->name(), [...BacklogTriageAgent::make()->tools()]))
        ->toEqualCanonicalizing($toolNames);
});

it('registers memory graph and controlled wiki write tools for the matching agent profiles', function () {
    $registry = app(AiAgentToolRegistry::class);

    $socratesToolNames = array_map(fn (Tool $tool): string => $tool->name(), $registry->forAgentKey('socrate_supervisor'));
    $wikiToolNames = array_map(fn (Tool $tool): string => $tool->name(), $registry->forAgentKey('wiki_query'));

    expect($socratesToolNames)->toContain('search_project_memory')
        ->and($socratesToolNames)->toContain('query_project_graph')
        ->and($wikiToolNames)->toContain('search_wiki_revisions')
        ->and($wikiToolNames)->toContain('write_wiki_revision');
});

it('reads a bounded project summary from DevBoard evidence only', function () {
    $pm = aiAgentToolsUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    aiAgentToolsCreateTask($projectId, $pm, [
        'title' => 'Clarify refund workflow',
        'priority' => 'high',
        'risk_level' => 'medium',
    ]);
    aiAgentToolsCreateWikiRevision($projectId, [
        'slug' => 'refunds',
        'title' => 'Refund Workflow',
        'source_status' => 'stale',
        'content_markdown' => 'Refund workflow notes.',
    ]);

    $payload = (new ReadProjectSummaryTool)->payload(['project_id' => $projectId]);

    expect($payload['source_status'])->toBe('verified_from_code')
        ->and($payload['tool'])->toBe('read_project_summary')
        ->and($payload['found'])->toBeTrue()
        ->and($payload['project']['id'])->toBe($projectId)
        ->and($payload['repositories']['total'])->toBe(1)
        ->and($payload['tasks']['total'])->toBe(1)
        ->and($payload['tasks']['by_status'][0]['status_key'])->toBe('backlog')
        ->and($payload['wiki']['pages_total'])->toBeGreaterThanOrEqual(1)
        ->and($payload['wiki']['stale_pages'])->toBeGreaterThanOrEqual(1)
        ->and(DB::table('tasks')->where('project_id', $projectId)->count())->toBe(1);

    DB::table('projects')->where('id', $projectId)->update(['status' => 'deleted']);

    expect((new ReadProjectSummaryTool)->payload(['project_id' => $projectId])['found'])->toBeFalse();
});

it('reads bounded project tasks for backlog triage without mutating tasks', function () {
    $pm = aiAgentToolsUserWithRole('PM');
    $developer = aiAgentToolsUserWithRole('Developer');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $taskId = aiAgentToolsCreateTask($projectId, $pm, [
        'title' => 'Clarify payment retry state',
        'description' => str_repeat('Payment retry state needs exact behavior. ', 12),
        'owner_user_id' => $developer->id,
        'priority' => 'high',
        'risk_level' => 'high',
    ]);
    $updatedAt = DB::table('tasks')->where('id', $taskId)->value('updated_at');

    $payload = (new ReadProjectTasksTool)->payload([
        'project_id' => $projectId,
        'status_keys' => ['backlog'],
        'limit' => 5,
    ]);

    expect($payload['source_status'])->toBe('verified_from_code')
        ->and($payload['tool'])->toBe('read_project_tasks')
        ->and($payload['found'])->toBeTrue()
        ->and($payload['project']['id'])->toBe($projectId)
        ->and($payload['tasks'][0]['id'])->toBe($taskId)
        ->and($payload['tasks'][0]['title'])->toBe('Clarify payment retry state')
        ->and($payload['tasks'][0]['status_key'])->toBe('backlog')
        ->and($payload['tasks'][0]['owner']['name'])->toBe($developer->name)
        ->and(strlen($payload['tasks'][0]['description_excerpt']))->toBeLessThanOrEqual(260)
        ->and(DB::table('tasks')->where('id', $taskId)->value('updated_at'))->toBe($updatedAt);

    DB::table('projects')->where('id', $projectId)->update(['status' => 'deleted']);

    expect((new ReadProjectTasksTool)->payload(['project_id' => $projectId])['found'])->toBeFalse();
});

it('reads task detail without mutating the task', function () {
    $pm = aiAgentToolsUserWithRole('PM');
    $developer = aiAgentToolsUserWithRole('Developer');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $taskId = aiAgentToolsCreateTask($projectId, $pm, [
        'title' => 'Clarify checkout retries',
        'description' => 'Retry handling needs acceptance criteria.',
        'owner_user_id' => $developer->id,
        'priority' => 'high',
        'risk_level' => 'high',
    ]);
    $updatedAt = DB::table('tasks')->where('id', $taskId)->value('updated_at');

    $payload = json_decode((new ReadTaskDetailTool)->handle(new Request(['task_id' => $taskId])), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['source_status'])->toBe('verified_from_code')
        ->and($payload['tool'])->toBe('read_task_detail')
        ->and($payload['found'])->toBeTrue()
        ->and($payload['task']['id'])->toBe($taskId)
        ->and($payload['task']['title'])->toBe('Clarify checkout retries')
        ->and($payload['task']['status_key'])->toBe('backlog')
        ->and($payload['task']['owner']['name'])->toBe($developer->name)
        ->and($payload['project']['id'])->toBe($projectId)
        ->and(DB::table('tasks')->where('id', $taskId)->value('updated_at'))->toBe($updatedAt);
});

it('searches current wiki revisions with bounded excerpts and evidence refs', function () {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $longTail = str_repeat('internal tail ', 80);
    $firstRevisionId = aiAgentToolsCreateWikiRevision($projectId, [
        'slug' => 'billing/refunds',
        'title' => 'Billing Refunds',
        'source_status' => 'verified_from_code',
        'content_markdown' => 'Refund handling must ask finance before release. '.$longTail,
        'evidence_refs' => [['type' => 'artifact', 'id' => 'artifact-1']],
    ]);
    aiAgentToolsCreateWikiRevision($projectId, [
        'slug' => 'billing/invoices',
        'title' => 'Billing Invoices',
        'content_markdown' => 'Invoice handling has no matching term.',
    ]);

    $payload = (new SearchWikiRevisionsTool)->payload([
        'project_id' => $projectId,
        'query' => 'refund',
        'limit' => 1,
    ]);

    expect($payload['source_status'])->toBe('verified_from_code')
        ->and($payload['tool'])->toBe('search_wiki_revisions')
        ->and($payload['results'])->toHaveCount(1)
        ->and($payload['results'][0]['revision_id'])->toBe($firstRevisionId)
        ->and($payload['results'][0]['excerpt'])->toContain('Refund handling')
        ->and($payload['results'][0]['excerpt'])->not->toContain(str_repeat('internal tail ', 20))
        ->and($payload['results'][0])->not->toHaveKey('content_markdown')
        ->and($payload['results'][0]['evidence_refs'][0]['id'])->toBe('artifact-1');
});

it('searches project memory by domain for agent use without mutating memory', function () {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $memoryId = (string) Str::ulid();
    $now = now();

    DB::table('project_memory_entries')->insert([
        'id' => $memoryId,
        'project_id' => $projectId,
        'repository_id' => null,
        'task_id' => null,
        'run_id' => null,
        'author_user_id' => null,
        'agent_key' => 'socrates',
        'source' => 'server_agent',
        'kind' => 'agent_note',
        'completeness' => 'complete',
        'summary' => 'Socrates captured the queue latency diagnosis.',
        'payload' => json_encode(['answer' => str_repeat('Queue latency evidence. ', 30)], JSON_THROW_ON_ERROR),
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $beforeCount = DB::table('project_memory_entries')->count();

    $payload = (new SearchProjectMemoryTool)->payload([
        'project_id' => $projectId,
        'domain' => 'agent_notes',
        'query' => 'latency',
        'limit' => 5,
    ]);

    expect($payload['tool'])->toBe('search_project_memory')
        ->and($payload['source_status'])->toBe('verified_from_code')
        ->and($payload['domain'])->toBe('agent_notes')
        ->and($payload['results'][0]['id'])->toBe($memoryId)
        ->and($payload['results'][0]['domain'])->toBe('agent_notes')
        ->and(strlen($payload['results'][0]['payload_excerpt']))->toBeLessThanOrEqual(500)
        ->and(DB::table('project_memory_entries')->count())->toBe($beforeCount);
});

it('queries a bounded read-only project graph from the latest graph artifact', function () {
    Storage::fake('local');

    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = DB::table('repositories')->where('project_id', $projectId)->value('id');
    aiAgentToolsCreateGraphSnapshot($projectId, $repositoryId, [
        'nodes' => [
            ['id' => 'app/Http/Controllers/FooController.php', 'labels' => ['File', 'Controller'], 'properties' => ['name' => 'FooController', 'path' => 'app/Http/Controllers/FooController.php']],
            ['id' => 'App\\Services\\InvoiceService', 'labels' => ['Class'], 'properties' => ['name' => 'InvoiceService']],
        ],
        'relationships' => [
            ['id' => 'rel-1', 'source_id' => 'app/Http/Controllers/FooController.php', 'target_id' => 'App\\Services\\InvoiceService', 'type' => 'USES'],
        ],
    ]);

    $payload = (new QueryProjectGraphTool)->payload([
        'project_id' => $projectId,
        'query' => 'invoice',
        'limit' => 5,
    ]);

    expect($payload['tool'])->toBe('query_project_graph')
        ->and($payload['source_status'])->toBe('verified_from_code')
        ->and($payload['found'])->toBeTrue()
        ->and($payload['stats']['nodes'])->toBe(2)
        ->and($payload['nodes'][0]['id'])->toBe('App\\Services\\InvoiceService')
        ->and($payload['relationships'][0]['from'])->toBe('app/Http/Controllers/FooController.php')
        ->and($payload['relationships'][0]['to'])->toBe('App\\Services\\InvoiceService');
});

it('QueryProjectGraphTool accepts structured query arguments for callers', function () {
    $payload = (new QueryProjectGraphTool)->payload([
        'project_id' => 'demo-project',
        'structured_query' => [
            'type' => 'callers',
            'symbol_id' => 'App\\Services\\InvoiceService',
            'limit' => 10,
        ],
    ]);

    expect($payload['tool'])->toBe('query_project_graph')
        ->and($payload['query_type'])->toBe('callers')
        ->and($payload['symbol_id'])->toBe('App\\Services\\InvoiceService');
});

it('scopes structured callers graph queries to the requested project latest snapshot', function () {
    Storage::fake('local');

    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = DB::table('repositories')->where('project_id', $projectId)->value('id');
    $snapshotId = aiAgentToolsCreateGraphSnapshot($projectId, $repositoryId, [
        'nodes' => [
            ['id' => 'App\\Services\\InvoiceService', 'labels' => ['Symbol', 'Function'], 'properties' => ['name' => 'InvoiceService']],
            ['id' => 'App\\Http\\Controllers\\InvoiceController', 'labels' => ['Symbol', 'Function'], 'properties' => ['name' => 'InvoiceController']],
        ],
        'relationships' => [
            ['id' => 'calls-1', 'source_id' => 'App\\Http\\Controllers\\InvoiceController', 'target_id' => 'App\\Services\\InvoiceService', 'type' => 'CALLS'],
        ],
    ]);
    $fakeClient = new FakeNeo4jClient;

    $payload = (new QueryProjectGraphTool(new GraphQueryService($fakeClient)))->payload([
        'project_id' => $projectId,
        'structured_query' => [
            'type' => 'callers',
            'symbol_id' => 'App\\Services\\InvoiceService',
            'limit' => 10,
        ],
    ]);

    expect([
        'found' => $payload['found'],
        'project_id' => $payload['project_id'],
        'reason' => $payload['reason'] ?? null,
        'commands' => count($fakeClient->commands),
        'command_snapshot_id' => $fakeClient->commands[0]['params']['snapshot_id'] ?? null,
    ])->toBe([
        'found' => true,
        'project_id' => $projectId,
        'reason' => null,
        'commands' => 1,
        'command_snapshot_id' => $snapshotId,
    ]);
});

it('QueryProjectGraphTool accepts structured query arguments for callees', function () {
    $payload = (new QueryProjectGraphTool)->payload([
        'project_id' => 'demo-project',
        'structured_query' => [
            'type' => 'callees',
            'symbol_id' => 'App\\Services\\InvoiceService',
            'limit' => 10,
        ],
    ]);

    expect($payload['tool'])->toBe('query_project_graph')
        ->and($payload['query_type'])->toBe('callees');
});

it('QueryProjectGraphTool accepts structured query arguments for path', function () {
    $payload = (new QueryProjectGraphTool)->payload([
        'project_id' => 'demo-project',
        'structured_query' => [
            'type' => 'path',
            'from_symbol_id' => 'App\\Controllers\\Foo',
            'to_symbol_id' => 'App\\Services\\Bar',
            'max_depth' => 5,
        ],
    ]);

    expect($payload['tool'])->toBe('query_project_graph')
        ->and($payload['query_type'])->toBe('path')
        ->and($payload['from_symbol_id'])->toBe('App\\Controllers\\Foo')
        ->and($payload['to_symbol_id'])->toBe('App\\Services\\Bar');
});

it('writes wiki revisions through a controlled audited agent tool', function () {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');

    $payload = app(WriteWikiRevisionTool::class)->payload([
        'project_id' => $projectId,
        'slug' => 'agent/runtime-notes',
        'title' => 'Agent Runtime Notes',
        'page_type' => 'technical',
        'source_status' => 'verified_from_code',
        'content_markdown' => "# Agent Runtime Notes\n\nGenerated from controlled agent evidence.",
        'evidence_refs' => [
            ['type' => 'memory_entry', 'id' => 'mem_123'],
        ],
        'agent_key' => 'wiki_query',
    ]);

    expect($payload['tool'])->toBe('write_wiki_revision')
        ->and($payload['source_status'])->toBe('verified_from_code')
        ->and($payload['written'])->toBeTrue()
        ->and($payload['wiki_page_id'])->toBeString()
        ->and($payload['wiki_revision_id'])->toBeString();

    expect(DB::table('wiki_pages')->where('id', $payload['wiki_page_id'])->value('slug'))->toBe('agent/runtime-notes')
        ->and(DB::table('wiki_revisions')->where('id', $payload['wiki_revision_id'])->value('producer'))->toBe('ai_agent:wiki_query')
        ->and(DB::table('wiki_revisions')->where('id', $payload['wiki_revision_id'])->value('source_type'))->toBe('controlled_agent_tool')
        ->and(DB::table('audit_logs')->where('action', 'wiki.updated')->where('target_id', $payload['wiki_page_id'])->exists())->toBeTrue();
});

function aiAgentToolsUserWithRole(string $roleName): User
{
    $user = User::factory()->create();
    $roleId = DB::table('roles')->where('name', $roleName)->value('id');

    DB::table('role_user')->insert([
        'user_id' => $user->id,
        'role_id' => $roleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function aiAgentToolsCreateTask(string $projectId, User $user, array $overrides = []): string
{
    $columnId = DB::table('kanban_boards')
        ->join('kanban_columns', 'kanban_columns.board_id', '=', 'kanban_boards.id')
        ->where('kanban_boards.project_id', $projectId)
        ->where('kanban_columns.status_key', 'backlog')
        ->value('kanban_columns.id');

    $taskId = (string) Str::ulid();
    $now = now();

    DB::table('tasks')->insert(array_merge([
        'id' => $taskId,
        'project_id' => $projectId,
        'title' => 'Read-only agent tool task',
        'description' => null,
        'status_column_id' => $columnId,
        'priority' => 'normal',
        'risk_level' => 'low',
        'owner_user_id' => null,
        'created_by_user_id' => $user->id,
        'due_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return $taskId;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function aiAgentToolsCreateWikiRevision(string $projectId, array $overrides = []): string
{
    $now = now();
    $pageId = (string) Str::ulid();
    $revisionId = (string) Str::ulid();
    $sourceStatus = $overrides['source_status'] ?? 'developer_provided';

    DB::table('wiki_pages')->insert([
        'id' => $pageId,
        'project_id' => $projectId,
        'repository_id' => $overrides['repository_id'] ?? null,
        'slug' => $overrides['slug'] ?? "page-{$pageId}",
        'title' => $overrides['title'] ?? 'Read-only Tool Page',
        'page_type' => $overrides['page_type'] ?? 'business',
        'current_revision_id' => $revisionId,
        'source_status' => $sourceStatus,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('wiki_revisions')->insert([
        'id' => $revisionId,
        'wiki_page_id' => $pageId,
        'author_user_id' => null,
        'author_device_id' => null,
        'producer' => $overrides['producer'] ?? 'test',
        'source_type' => $overrides['source_type'] ?? 'manual',
        'source_status' => $sourceStatus,
        'content_markdown' => $overrides['content_markdown'] ?? 'Read-only wiki content.',
        'evidence_refs' => json_encode($overrides['evidence_refs'] ?? [], JSON_THROW_ON_ERROR),
        'created_at' => $now,
    ]);

    return $revisionId;
}

/**
 * @param  array<string, mixed>  $graphPayload
 */
function aiAgentToolsCreateGraphSnapshot(string $projectId, string $repositoryId, array $graphPayload): string
{
    $userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $now = now();
    $deviceId = (string) Str::ulid();
    $workspaceId = (string) Str::ulid();
    $runId = (string) Str::ulid();
    $artifactId = (string) Str::ulid();
    $snapshotId = (string) Str::ulid();
    $storagePath = "testing/graphs/{$artifactId}.json";
    $encoded = json_encode($graphPayload, JSON_THROW_ON_ERROR);

    Storage::disk('local')->put($storagePath, $encoded);

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $userId,
        'name' => 'AI Agent Graph Tool Device',
        'fingerprint_hash' => 'sha256:'.$deviceId,
        'platform_os' => 'linux',
        'platform_arch' => 'amd64',
        'plugin_version' => 'test',
        'last_seen_at' => $now,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('local_workspaces')->insert([
        'id' => $workspaceId,
        'repository_id' => $repositoryId,
        'device_id' => $deviceId,
        'local_root_hash' => 'sha256:'.$workspaceId,
        'display_path' => '~/Code/ai-agent-graph-tool',
        'current_branch' => 'main',
        'last_head_sha' => str_repeat('a', 40),
        'dirty_status' => 'clean',
        'last_snapshot_id' => null,
        'last_seen_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('runs')->insert([
        'id' => $runId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => $workspaceId,
        'task_id' => null,
        'device_id' => $deviceId,
        'started_by_user_id' => $userId,
        'runtime_profile' => 'agent_plugin',
        'status' => 'finished',
        'branch' => 'main',
        'base_branch' => 'main',
        'base_sha' => str_repeat('a', 40),
        'head_sha' => str_repeat('b', 40),
        'summary' => 'Graph tool test run.',
        'risk_level' => 'low',
        'started_at' => $now,
        'finished_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('artifacts')->insert([
        'id' => $artifactId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'run_id' => $runId,
        'artifact_type' => 'graph_snapshot',
        'storage_path' => $storagePath,
        'sha256' => hash('sha256', $encoded),
        'size_bytes' => strlen($encoded),
        'mime_type' => 'application/json',
        'schema_version' => 'graph.v1',
        'status' => 'imported',
        'producer' => 'test',
        'metadata' => json_encode(['graph_parser' => 'test'], JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('snapshots')->insert([
        'id' => $snapshotId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => $workspaceId,
        'source_type' => 'local_plugin_snapshot',
        'branch' => 'main',
        'base_sha' => str_repeat('a', 40),
        'head_sha' => str_repeat('b', 40),
        'dirty_status' => 'clean',
        'file_inventory_artifact_id' => null,
        'graph_snapshot_artifact_id' => $artifactId,
        'created_by_run_id' => $runId,
        'created_at' => $now,
    ]);

    return $snapshotId;
}
