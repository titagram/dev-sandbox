<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

it('serves a cross-project overview for the dashboard adapter', function () {
    $pm = multiprojectDashboardApiUserWithRole('PM');
    $fixture = multiprojectDashboardApiScenario();

    $this->actingAs($pm)
        ->getJson('/api/dashboard/overview')
        ->assertOk()
        ->assertJsonPath('summary.active_projects', 2)
        ->assertJsonPath('summary.repositories_awaiting_genesis', 1)
        ->assertJsonPath('tasks.total', 2)
        ->assertJsonPath('tasks.blocked', 1)
        ->assertJsonPath('tasks.by_state.ready', 1)
        ->assertJsonPath('tasks.by_risk.high', 1)
        ->assertJsonPath('runs.failed', 1)
        ->assertJsonPath('runs.running', 1)
        ->assertJsonPath('wiki.stale_pages', 1)
        ->assertJsonPath('agents.online', 1)
        ->assertJsonPath('projects.0.id', $fixture['primary']['project_id'])
        ->assertJsonPath('projects.1.id', $fixture['secondary']['project_id']);
});

it('serves project-scoped dashboard resources without mixing projects', function () {
    $pm = multiprojectDashboardApiUserWithRole('PM');
    $fixture = multiprojectDashboardApiScenario();
    $primary = $fixture['primary'];
    $secondary = $fixture['secondary'];

    $kanban = $this->actingAs($pm)
        ->getJson("/api/dashboard/projects/{$secondary['project_id']}/kanban")
        ->assertOk()
        ->assertJsonPath("tasks.{$secondary['task_id']}.project_id", $secondary['project_id'])
        ->json();

    expect($kanban['tasks'])->toHaveKey($secondary['task_id'])
        ->and($kanban['tasks'])->not->toHaveKey($primary['task_id']);

    $runs = $this->actingAs($pm)
        ->getJson("/api/dashboard/projects/{$secondary['project_id']}/runs")
        ->assertOk()
        ->json();

    expect($runs)->toHaveCount(1)
        ->and($runs[0]['id'])->toBe($secondary['run_id'])
        ->and($runs[0]['project_id'])->toBe($secondary['project_id']);

    $wiki = $this->actingAs($pm)
        ->getJson("/api/dashboard/projects/{$secondary['project_id']}/wiki")
        ->assertOk()
        ->json();

    expect($wiki)->toHaveCount(1)
        ->and($wiki[0]['id'])->toBe($secondary['wiki_page_id'])
        ->and($wiki[0]['project_id'])->toBe($secondary['project_id']);

    $artifacts = $this->actingAs($pm)
        ->getJson("/api/dashboard/projects/{$secondary['project_id']}/artifacts")
        ->assertOk()
        ->json();

    expect($artifacts)->toHaveCount(1)
        ->and($artifacts[0]['id'])->toBe($secondary['artifact_id'])
        ->and($artifacts[0]['project_id'])->toBe($secondary['project_id']);
});

it('counts non-terminal run lifecycle states as running in overview', function () {
    $pm = multiprojectDashboardApiUserWithRole('PM');
    $fixture = multiprojectDashboardApiScenario();

    multiprojectDashboardApiRun(
        projectId: $fixture['primary']['project_id'],
        repositoryId: $fixture['primary']['repository_id'],
        workspaceId: $fixture['primary']['workspace_id'],
        deviceId: $fixture['primary']['device_id'],
        adminId: (int) $fixture['primary']['admin_id'],
        status: 'heartbeat',
    );
    multiprojectDashboardApiRun(
        projectId: $fixture['secondary']['project_id'],
        repositoryId: $fixture['secondary']['repository_id'],
        workspaceId: $fixture['secondary']['workspace_id'],
        deviceId: $fixture['secondary']['device_id'],
        adminId: (int) $fixture['secondary']['admin_id'],
        status: 'artifact_uploaded',
    );

    $this->actingAs($pm)
        ->getJson('/api/dashboard/overview')
        ->assertOk()
        ->assertJsonPath('runs.running', 3)
        ->assertJsonPath('runs.failed', 1);
});

it('returns not found for unknown project-scoped resources', function (string $path) {
    $pm = multiprojectDashboardApiUserWithRole('PM');
    $unknownProject = (string) Str::ulid();

    $this->actingAs($pm)
        ->getJson(str_replace('{project}', $unknownProject, $path))
        ->assertNotFound();
})->with([
    'kanban' => ['/api/dashboard/projects/{project}/kanban'],
    'runs' => ['/api/dashboard/projects/{project}/runs'],
    'wiki' => ['/api/dashboard/projects/{project}/wiki'],
    'artifacts' => ['/api/dashboard/projects/{project}/artifacts'],
]);

function multiprojectDashboardApiUserWithRole(string $roleName): User
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
 * @return array{primary: array<string, string>, secondary: array<string, string>}
 */
function multiprojectDashboardApiScenario(): array
{
    $adminId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $deviceId = (string) Str::ulid();
    $now = now();

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $adminId,
        'name' => 'Multiproject Device',
        'fingerprint_hash' => 'sha256:multiproject-device',
        'platform_os' => 'linux',
        'platform_arch' => 'amd64',
        'plugin_version' => '0.9.5',
        'last_seen_at' => $now->copy()->subMinutes(2),
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $primaryProjectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $primaryRepositoryId = DB::table('repositories')->where('slug', 'demo-repository')->value('id');
    $primaryReadyColumnId = DB::table('kanban_columns')
        ->join('kanban_boards', 'kanban_boards.id', '=', 'kanban_columns.board_id')
        ->where('kanban_boards.project_id', $primaryProjectId)
        ->where('kanban_columns.status_key', 'ready')
        ->value('kanban_columns.id');

    $secondaryProjectId = (string) Str::ulid();
    $secondaryRepositoryId = (string) Str::ulid();
    $secondaryBoardId = (string) Str::ulid();
    $secondaryReadyColumnId = (string) Str::ulid();
    $secondaryBlockedColumnId = (string) Str::ulid();

    DB::table('projects')->insert([
        'id' => $secondaryProjectId,
        'name' => 'Second Project',
        'slug' => 'second-project',
        'description' => 'Second project for multiproject dashboard API tests.',
        'status' => 'active',
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $adminId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('repositories')->insert([
        'id' => $secondaryRepositoryId,
        'project_id' => $secondaryProjectId,
        'name' => 'second-repository',
        'slug' => 'second-repository',
        'default_branch' => 'main',
        'local_only' => true,
        'code_exposure_policy' => 'full_code_artifacts',
        'protected_paths' => json_encode([], JSON_THROW_ON_ERROR),
        'excluded_paths' => json_encode([], JSON_THROW_ON_ERROR),
        'stack_hints' => json_encode(['php'], JSON_THROW_ON_ERROR),
        'graph_enabled' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('kanban_boards')->insert([
        'id' => $secondaryBoardId,
        'project_id' => $secondaryProjectId,
        'name' => 'Default Board',
        'is_default' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('kanban_columns')->insert([
        [
            'id' => $secondaryReadyColumnId,
            'board_id' => $secondaryBoardId,
            'name' => 'Ready',
            'position' => 1,
            'status_key' => 'ready',
            'wip_limit' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id' => $secondaryBlockedColumnId,
            'board_id' => $secondaryBoardId,
            'name' => 'Blocked',
            'position' => 2,
            'status_key' => 'blocked',
            'wip_limit' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $primary = multiprojectDashboardApiResourceSet(
        projectId: $primaryProjectId,
        repositoryId: $primaryRepositoryId,
        columnId: $primaryReadyColumnId,
        deviceId: $deviceId,
        adminId: (int) $adminId,
        taskTitle: 'Primary scoped task',
        runStatus: 'running',
        taskRisk: 'medium',
        wikiStatus: 'verified_from_code',
        now: $now,
    );

    $secondary = multiprojectDashboardApiResourceSet(
        projectId: $secondaryProjectId,
        repositoryId: $secondaryRepositoryId,
        columnId: $secondaryBlockedColumnId,
        deviceId: $deviceId,
        adminId: (int) $adminId,
        taskTitle: 'Secondary scoped task',
        runStatus: 'failed',
        taskRisk: 'high',
        wikiStatus: 'stale',
        now: $now,
    );

    DB::table('genesis_imports')->insert([
        'id' => (string) Str::ulid(),
        'project_id' => $primaryProjectId,
        'repository_id' => $primaryRepositoryId,
        'local_workspace_id' => $primary['workspace_id'],
        'run_id' => $primary['run_id'],
        'status' => 'active',
        'manifest_artifact_id' => null,
        'snapshot_id' => null,
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'started_at' => $now->copy()->subMinutes(10),
        'finished_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'primary' => $primary,
        'secondary' => $secondary,
    ];
}

/**
 * @return array<string, string>
 */
function multiprojectDashboardApiResourceSet(
    string $projectId,
    string $repositoryId,
    string $columnId,
    string $deviceId,
    int $adminId,
    string $taskTitle,
    string $runStatus,
    string $taskRisk,
    string $wikiStatus,
    mixed $now,
): array {
    $workspaceId = (string) Str::ulid();
    $taskId = (string) Str::ulid();
    $runId = (string) Str::ulid();
    $artifactId = (string) Str::ulid();
    $wikiPageId = (string) Str::ulid();
    $wikiRevisionId = (string) Str::ulid();

    DB::table('local_workspaces')->insert([
        'id' => $workspaceId,
        'repository_id' => $repositoryId,
        'device_id' => $deviceId,
        'local_root_hash' => 'sha256:'.$workspaceId,
        'display_path' => "/workspace/{$repositoryId}",
        'current_branch' => 'main',
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
        'title' => $taskTitle,
        'description' => "{$taskTitle} description.",
        'status_column_id' => $columnId,
        'priority' => 'normal',
        'risk_level' => $taskRisk,
        'owner_user_id' => $adminId,
        'created_by_user_id' => $adminId,
        'due_at' => null,
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
        'status' => $runStatus,
        'branch' => 'main',
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'summary' => "{$taskTitle} run.",
        'risk_level' => $taskRisk,
        'started_at' => $now->copy()->subMinutes(5),
        'finished_at' => $runStatus === 'running' ? null : $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('artifacts')->insert([
        'id' => $artifactId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'run_id' => $runId,
        'artifact_type' => 'security_report',
        'storage_path' => "devboard/test/{$artifactId}.json",
        'sha256' => hash('sha256', $artifactId),
        'size_bytes' => 128,
        'mime_type' => 'application/json',
        'schema_version' => 'v1',
        'status' => 'validated',
        'producer' => 'devboard-python-plugin',
        'metadata' => json_encode(['source_type' => 'local_plugin_snapshot'], JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('wiki_pages')->insert([
        'id' => $wikiPageId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'slug' => 'technical/'.$taskId,
        'title' => "{$taskTitle} Wiki",
        'page_type' => 'technical',
        'current_revision_id' => null,
        'source_status' => $wikiStatus,
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
        'source_status' => $wikiStatus,
        'content_markdown' => "# {$taskTitle}\n",
        'evidence_refs' => json_encode([
            ['type' => 'artifact', 'artifact_id' => $artifactId],
        ], JSON_THROW_ON_ERROR),
        'created_at' => $now,
    ]);

    DB::table('wiki_pages')->where('id', $wikiPageId)->update([
        'current_revision_id' => $wikiRevisionId,
        'updated_at' => $now,
    ]);

    return [
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'device_id' => $deviceId,
        'admin_id' => (string) $adminId,
        'workspace_id' => $workspaceId,
        'task_id' => $taskId,
        'run_id' => $runId,
        'artifact_id' => $artifactId,
        'wiki_page_id' => $wikiPageId,
    ];
}

function multiprojectDashboardApiRun(
    string $projectId,
    string $repositoryId,
    string $workspaceId,
    string $deviceId,
    int $adminId,
    string $status,
): string {
    $runId = (string) Str::ulid();
    $now = now();

    DB::table('runs')->insert([
        'id' => $runId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => $workspaceId,
        'task_id' => null,
        'device_id' => $deviceId,
        'started_by_user_id' => $adminId,
        'runtime_profile' => 'agent_plugin',
        'status' => $status,
        'branch' => 'main',
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'summary' => "{$status} run.",
        'risk_level' => 'medium',
        'started_at' => $now->copy()->subMinutes(5),
        'finished_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $runId;
}
