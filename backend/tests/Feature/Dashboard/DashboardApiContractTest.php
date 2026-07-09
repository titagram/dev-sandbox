<?php

use App\Models\User;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(DevBoardSeeder::class);
});

it('denies non-admin users from managing plugin tokens through gates', function () {
    $admin = dashboardApiContractUserWithRole('Admin');
    $pm = dashboardApiContractUserWithRole('PM');
    $developer = dashboardApiContractUserWithRole('Developer');
    $ids = createDashboardApiContractScenario();

    $this->actingAs($pm)
        ->postJson('/api/dashboard/admin/plugin-tokens', [
            'name' => 'pm token',
            'scopes' => ['projects.read'],
        ])
        ->assertForbidden();

    $this->actingAs($developer)
        ->postJson('/api/dashboard/admin/plugin-tokens', [
            'name' => 'dev token',
            'scopes' => ['projects.read'],
        ])
        ->assertForbidden();

    $token = $this->actingAs($admin)
        ->postJson('/api/dashboard/admin/plugin-tokens', [
            'name' => 'gate test token',
            'scopes' => ['projects.read'],
        ])
        ->assertOk()
        ->json();

    $this->actingAs($pm)
        ->deleteJson("/api/dashboard/admin/plugin-tokens/{$token['id']}")
        ->assertForbidden();

    $this->actingAs($developer)
        ->postJson("/api/dashboard/admin/plugin-tokens/{$token['id']}/rotate")
        ->assertForbidden();

    $this->actingAs($admin)
        ->getJson('/api/dashboard/admin/devices')
        ->assertOk()
        ->assertJsonPath('0.id', $ids['device_id']);

    expect(DB::table('audit_logs')
        ->where('action', 'permission.denied')
        ->count())->toBeGreaterThanOrEqual(3);
});

it('serves the generated frontend dashboard read contract', function () {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();

    $this->actingAs($admin)
        ->getJson('/api/dashboard/projects')
        ->assertOk()
        ->assertJsonPath('0.id', $ids['project_id'])
        ->assertJsonPath('0.key', 'demo-project')
        ->assertJsonStructure([['repository_count', 'open_tasks', 'wiki_freshness']]);

    $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}")
        ->assertOk()
        ->assertJsonPath('repositories.0.id', $ids['repository_id'])
        ->assertJsonPath('links.wiki', "/projects/{$ids['project_id']}/wiki")
        ->assertJsonPath('links.wiki_api', "/api/dashboard/projects/{$ids['project_id']}/wiki")
        ->assertJsonPath('policy.code_write_allowed', true)
        ->assertJsonPath('recent_run_ids.0', $ids['run_id']);

    $this->actingAs($admin)
        ->getJson('/api/dashboard/kanban')
        ->assertOk()
        ->assertJsonPath('columns.1.id', 'ready')
        ->assertJsonPath("tasks.{$ids['task_id']}.linked_run_id", $ids['run_id']);

    $doneColumn = DB::table('kanban_columns')->where('status_key', 'done')->value('id');

    $this->actingAs($admin)
        ->patchJson("/api/dashboard/tasks/{$ids['task_id']}", ['column' => 'done'])
        ->assertOk()
        ->assertJsonPath('id', $ids['task_id'])
        ->assertJsonPath('column', 'done');

    expect(DB::table('tasks')->where('id', $ids['task_id'])->value('status_column_id'))->toBe($doneColumn);

    $this->actingAs($admin)
        ->getJson("/api/dashboard/tasks/{$ids['task_id']}")
        ->assertOk()
        ->assertJsonPath('source.type', 'local_plugin_snapshot')
        ->assertJsonPath('linked_run_id', $ids['run_id']);

    $this->actingAs($admin)
        ->getJson('/api/dashboard/runs')
        ->assertOk()
        ->assertJsonPath('0.id', $ids['run_id'])
        ->assertJsonPath('0.type', 'genesis_import');

    $this->actingAs($admin)
        ->getJson("/api/dashboard/runs/{$ids['run_id']}")
        ->assertOk()
        ->assertJsonPath('id', $ids['run_id'])
        ->assertJsonPath('artifact_ids.0', $ids['artifact_id'])
        ->assertJsonPath('source.type', 'local_plugin_snapshot');

    $this->actingAs($admin)
        ->getJson('/api/dashboard/wiki')
        ->assertOk()
        ->assertJsonPath('0.id', $ids['wiki_page_id'])
        ->assertJsonPath('0.has_evidence', true);

    $this->actingAs($admin)
        ->getJson("/api/dashboard/wiki/pages/{$ids['wiki_page_id']}")
        ->assertOk()
        ->assertJsonPath('body_markdown', "# API Contract\n\nVerified from local analyzer.")
        ->assertJsonPath('evidence.0.kind', 'artifact_ref');

    $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/wiki/pages/{$ids['wiki_page_id']}")
        ->assertOk()
        ->assertJsonPath('project_id', $ids['project_id'])
        ->assertJsonPath('body_markdown', "# API Contract\n\nVerified from local analyzer.");

    $this->actingAs($admin)
        ->getJson("/api/dashboard/graph?run_id={$ids['run_id']}")
        ->assertOk()
        ->assertJsonPath('run_id', $ids['run_id'])
        ->assertJsonPath('stats.nodes', 2)
        ->assertJsonPath('nodes.0.source.type', 'local_analyzer');

    $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$ids['project_id']}/graph?run_id={$ids['run_id']}")
        ->assertOk()
        ->assertJsonPath('run_id', $ids['run_id'])
        ->assertJsonPath('stats.nodes', 2)
        ->assertJsonPath('nodes.0.source.type', 'local_analyzer');

    $this->actingAs($admin)
        ->getJson('/api/dashboard/artifacts')
        ->assertOk()
        ->assertJsonPath('0.id', $ids['artifact_id'])
        ->assertJsonPath('0.downloadable', true);

    $this->actingAs($admin)
        ->getJson("/api/dashboard/runs/{$ids['run_id']}/artifacts/{$ids['artifact_id']}/download")
        ->assertOk()
        ->assertJsonPath('name', 'contract-graph.json')
        ->assertJsonPath('url', "/runs/{$ids['run_id']}/artifacts/{$ids['artifact_id']}/download");
});

it('serves dashboard run, admin, and system operations through the adapter contract', function () {
    config(['services.devboard.graph_import_mode' => 'fake']);

    $admin = dashboardApiContractUserWithRole('Admin');
    $developer = dashboardApiContractUserWithRole('Developer');
    $pm = dashboardApiContractUserWithRole('PM');
    $ids = createDashboardApiContractScenario(retryable: true);

    $this->actingAs($developer)
        ->postJson("/api/dashboard/runs/{$ids['run_id']}/review")
        ->assertOk()
        ->assertJsonPath('id', $ids['run_id'])
        ->assertJsonPath('status', 'reviewed')
        ->assertJsonPath('reviewed_by', $developer->name);

    $this->actingAs($pm)
        ->postJson("/api/dashboard/runs/{$ids['run_id']}/retry-import")
        ->assertForbidden();

    $this->actingAs($developer)
        ->postJson("/api/dashboard/runs/{$ids['run_id']}/retry-import")
        ->assertOk()
        ->assertJsonPath('id', $ids['run_id'])
        ->assertJsonPath('graph_status', 'complete');

    $created = $this->actingAs($admin)
        ->postJson('/api/dashboard/admin/plugin-tokens', [
            'name' => 'frontend adapter token',
            'scopes' => ['projects.read'],
        ])
        ->assertOk()
        ->assertJsonPath('name', 'frontend adapter token')
        ->assertJsonStructure(['plain_token', 'prefix'])
        ->json();

    $this->actingAs($admin)
        ->getJson('/api/dashboard/admin/plugin-tokens')
        ->assertOk()
        ->assertJsonMissing(['plain_token' => $created['plain_token']])
        ->assertJsonPath('0.id', $created['id']);

    $this->actingAs($admin)
        ->postJson("/api/dashboard/admin/plugin-tokens/{$created['id']}/rotate")
        ->assertOk()
        ->assertJsonPath('id', $created['id'])
        ->assertJsonStructure(['plain_token']);

    $this->actingAs($admin)
        ->deleteJson("/api/dashboard/admin/plugin-tokens/{$created['id']}")
        ->assertNoContent();

    $this->actingAs($admin)
        ->getJson('/api/dashboard/admin/devices')
        ->assertOk()
        ->assertJsonPath('0.id', $ids['device_id'])
        ->assertJsonPath('0.status', 'active');

    $this->actingAs($admin)
        ->deleteJson("/api/dashboard/admin/devices/{$ids['device_id']}")
        ->assertNoContent();

    $this->actingAs($pm)
        ->getJson('/api/dashboard/system')
        ->assertForbidden();

    $this->actingAs($admin)
        ->getJson('/api/dashboard/system')
        ->assertOk()
        ->assertJsonPath('retention.artifact_retention_days', 90)
        ->assertJsonPath('audit_export_available', true);

    $this->actingAs($admin)
        ->postJson('/api/dashboard/system/artifact-retention', [
            'retention_days' => 90,
            'auto_purge_enabled' => false,
        ])
        ->assertOk()
        ->assertJsonPath('last_operation.status', 'ok');

    $this->actingAs($admin)
        ->postJson('/api/dashboard/system/audit-exports', ['range_days' => 30])
        ->assertOk()
        ->assertJsonPath('last_operation.status', 'ok');
});

it('serves a bounded graph preview with total stats and analyzer relationship keys', function () {
    $admin = dashboardApiContractUserWithRole('Admin');
    $ids = createDashboardApiContractScenario();
    $nodes = [];
    $relationships = [];

    for ($index = 0; $index < 50; $index++) {
        $nodes[] = [
            'id' => "module:unconnected{$index}",
            'labels' => ['Module'],
            'properties' => ['name' => "unconnected{$index}"],
        ];
    }

    for ($index = 0; $index < 250; $index++) {
        $nodes[] = [
            'id' => "function:handler{$index}",
            'labels' => ['Function'],
            'properties' => ['name' => "handler{$index}"],
        ];
    }

    for ($index = 0; $index < 249; $index++) {
        $relationships[] = [
            'id' => "rel-{$index}",
            'type' => 'CALLS',
            'source_id' => "function:handler{$index}",
            'target_id' => 'function:handler'.($index + 1),
        ];
    }

    $storagePath = DB::table('artifacts')->where('id', $ids['artifact_id'])->value('storage_path');
    Storage::disk('local')->put($storagePath, json_encode([
        'nodes' => $nodes,
        'relationships' => $relationships,
    ], JSON_THROW_ON_ERROR));

    $response = $this->actingAs($admin)
        ->getJson("/api/dashboard/graph?run_id={$ids['run_id']}")
        ->assertOk()
        ->assertJsonPath('stats.nodes', 300)
        ->assertJsonPath('stats.edges', 249)
        ->assertJsonPath('edges.0.from', 'function:handler0')
        ->assertJsonPath('edges.0.to', 'function:handler1')
        ->json();

    expect($response['nodes'])->toHaveCount(200);
    expect($response['edges'])->toHaveCount(199);
    expect($response['nodes'][0]['id'])->toBe('function:handler0');
    expect($response['nodes'][0]['degree'])->toBeGreaterThan(0);
});

function dashboardApiContractUserWithRole(string $roleName): User
{
    $user = User::factory()->create(['status' => 'active']);
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
 * @return array<string, string>
 */
function createDashboardApiContractScenario(bool $retryable = false): array
{
    $adminId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = DB::table('repositories')->where('slug', 'demo-repository')->value('id');
    $readyColumnId = DB::table('kanban_columns')->where('status_key', 'ready')->value('id');
    $deviceId = (string) Str::ulid();
    $workspaceId = (string) Str::ulid();
    $taskId = (string) Str::ulid();
    $runId = (string) Str::ulid();
    $artifactId = (string) Str::ulid();
    $snapshotId = (string) Str::ulid();
    $genesisId = (string) Str::ulid();
    $wikiPageId = (string) Str::ulid();
    $wikiRevisionId = (string) Str::ulid();
    $now = now();
    $storagePath = 'devboard/test/contract-graph.json';

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $adminId,
        'name' => 'Contract Device',
        'fingerprint_hash' => 'sha256:contract-device',
        'platform_os' => 'linux',
        'platform_arch' => 'amd64',
        'plugin_version' => '0.9.4',
        'last_seen_at' => $now,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('local_workspaces')->insert([
        'id' => $workspaceId,
        'repository_id' => $repositoryId,
        'device_id' => $deviceId,
        'local_root_hash' => 'sha256:contract-workspace',
        'display_path' => '/workspace/target-repo',
        'current_branch' => 'feature/dashboard-api',
        'last_head_sha' => 'def456',
        'dirty_status' => 'clean',
        'last_snapshot_id' => null,
        'last_seen_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('tasks')->insert([
        'id' => $taskId,
        'project_id' => $projectId,
        'title' => 'Expose dashboard adapter API',
        'description' => 'Return generated frontend contract data from Laravel.',
        'status_column_id' => $readyColumnId,
        'priority' => 'high',
        'risk_level' => 'medium',
        'owner_user_id' => $adminId,
        'created_by_user_id' => $adminId,
        'due_at' => $now->copy()->addDay(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('runs')->insert([
        'id' => $runId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => $workspaceId,
        'task_id' => $taskId,
        'device_id' => $deviceId,
        'started_by_user_id' => $adminId,
        'runtime_profile' => 'agent_plugin',
        'status' => $retryable ? 'failed' : 'finished',
        'branch' => 'feature/dashboard-api',
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'summary' => 'Dashboard adapter contract fixture.',
        'risk_level' => 'medium',
        'started_at' => $now->copy()->subMinutes(10),
        'finished_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Storage::disk('local')->put($storagePath, json_encode([
        'nodes' => [
            ['id' => 'route:dashboard-api', 'labels' => ['Route'], 'properties' => ['path' => '/api/dashboard/runs']],
            ['id' => 'class:DashboardApiReader', 'labels' => ['Class'], 'properties' => ['name' => 'DashboardApiReader']],
        ],
        'relationships' => [
            ['id' => 'edge-1', 'type' => 'routes_to', 'from' => 'route:dashboard-api', 'to' => 'class:DashboardApiReader'],
        ],
    ], JSON_THROW_ON_ERROR));

    DB::table('artifacts')->insert([
        'id' => $artifactId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'run_id' => $runId,
        'artifact_type' => 'graph_snapshot',
        'storage_path' => $storagePath,
        'sha256' => hash('sha256', Storage::disk('local')->get($storagePath)),
        'size_bytes' => Storage::disk('local')->size($storagePath),
        'mime_type' => 'application/json',
        'schema_version' => 'v1',
        'status' => 'imported',
        'producer' => 'devboard-python-plugin',
        'metadata' => json_encode(['source_type' => 'local_plugin_snapshot'], JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('snapshots')->insert([
        'id' => $snapshotId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => $workspaceId,
        'source_type' => 'local_plugin_snapshot',
        'branch' => 'feature/dashboard-api',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'dirty_status' => 'clean',
        'file_inventory_artifact_id' => null,
        'graph_snapshot_artifact_id' => $artifactId,
        'created_by_run_id' => $runId,
        'created_at' => $now,
    ]);

    DB::table('genesis_imports')->insert([
        'id' => $genesisId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => $workspaceId,
        'run_id' => $runId,
        'status' => $retryable ? 'failed' : 'active',
        'manifest_artifact_id' => null,
        'snapshot_id' => $snapshotId,
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'started_at' => $now->copy()->subMinutes(10),
        'finished_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('wiki_pages')->insert([
        'id' => $wikiPageId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'slug' => 'technical/api-contract',
        'title' => 'API Contract',
        'page_type' => 'technical',
        'current_revision_id' => null,
        'source_status' => 'verified_from_code',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('wiki_revisions')->insert([
        'id' => $wikiRevisionId,
        'wiki_page_id' => $wikiPageId,
        'author_user_id' => $adminId,
        'author_device_id' => $deviceId,
        'producer' => 'devboard-python-plugin',
        'source_type' => 'local_analyzer',
        'source_status' => 'verified_from_code',
        'content_markdown' => "# API Contract\n\nVerified from local analyzer.",
        'evidence_refs' => json_encode([
            ['type' => 'artifact', 'artifact_id' => $artifactId, 'description' => 'contract-graph.json'],
        ], JSON_THROW_ON_ERROR),
        'created_at' => $now,
    ]);

    DB::table('wiki_pages')->where('id', $wikiPageId)->update([
        'current_revision_id' => $wikiRevisionId,
        'updated_at' => $now,
    ]);

    DB::table('run_events')->insert([
        'id' => (string) Str::ulid(),
        'run_id' => $runId,
        'event_type' => 'run.finished',
        'severity' => $retryable ? 'error' : 'info',
        'message' => 'Contract fixture run event.',
        'payload' => json_encode(['risk_triggers' => ['dashboard_contract']], JSON_THROW_ON_ERROR),
        'created_at' => $now,
    ]);

    return [
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'device_id' => $deviceId,
        'task_id' => $taskId,
        'run_id' => $runId,
        'artifact_id' => $artifactId,
        'snapshot_id' => $snapshotId,
        'wiki_page_id' => $wikiPageId,
    ];
}
