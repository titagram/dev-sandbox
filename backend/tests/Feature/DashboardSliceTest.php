<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

it('lets an authenticated PM see the Kanban home', function () {
    $pm = dashboardUserWithRole('PM');

    $this->actingAs($pm)->get('/kanban')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Kanban/Index')
            ->where('project.slug', 'demo-project')
            ->where('columns.0.name', 'Backlog')
            ->where('dashboard.navigation', function ($navigation) {
                expect(collect($navigation)->pluck('label')->all())->not->toContain('Admin');

                return true;
            })
            ->has('recentRuns')
        );
});

it('shows repositories and Genesis status on project detail', function () {
    $pm = dashboardUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    createDashboardGenesisState();

    $this->actingAs($pm)->get("/projects/{$projectId}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Show')
            ->where('repositories.0.name', 'demo-repository')
            ->where('repositories.0.git_mode', 'local_only')
            ->where('repositories.0.genesis_status', 'active')
            ->where('repositories.0.graph_status', 'ready')
            ->where('repositories.0.wiki_status', 'current')
            ->where('wikiPages.0.source_status', 'verified_from_code')
            ->where('wikiPages.0.evidence_refs.0.path', 'file-inventory.json')
        );
});

it('shows artifacts risk and source labels on run detail', function () {
    $pm = dashboardUserWithRole('PM');
    $runId = createDashboardRun();

    $this->actingAs($pm)->get("/runs/{$runId}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Runs/Show')
            ->where('sourceLabel', 'local_plugin_snapshot')
            ->where('run.risk_level', 'high')
            ->where('artifacts.0.artifact_type', 'security_report')
            ->where('risk.triggers.0', 'secret_scan_blocked')
            ->where('safety.blocked.0.path', '.env')
            ->where('state.source_truth', 'local plugin state, not remote Git truth')
        );
});

it('blocks PM access to Admin token page', function () {
    $pm = dashboardUserWithRole('PM');

    $this->actingAs($pm)->get('/admin/plugin-tokens')->assertForbidden();
});

it('lets Admin create a plugin token and only returns the secret once', function () {
    $admin = dashboardUserWithRole('Admin');

    $response = $this->actingAs($admin)->postJson('/admin/plugin-tokens', [
        'name' => 'Gabriele local plugin',
        'scopes' => ['projects.read', 'repositories.read', 'runs.write'],
        'expires_in_days' => 90,
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('token.name', 'Gabriele local plugin')
        ->assertJsonStructure(['plain_token']);

    $plainToken = $response->json('plain_token');

    expect($plainToken)->toStartWith('devb_live_');
    $this->actingAs($admin)->get('/admin/plugin-tokens')
        ->assertOk()
        ->assertDontSee($plainToken);
});

it('lets Admin revoke a plugin token', function () {
    $admin = dashboardUserWithRole('Admin');

    $token = $this->actingAs($admin)->postJson('/admin/plugin-tokens', [
        'name' => 'Revokable plugin',
        'scopes' => ['projects.read'],
        'expires_in_days' => 30,
    ])->json('token');

    $this->actingAs($admin)
        ->deleteJson("/admin/plugin-tokens/{$token['id']}")
        ->assertOk()
        ->assertJson(['revoked' => true]);

    expect(DB::table('api_tokens')->where('id', $token['id'])->value('revoked_at'))->not->toBeNull();
});

function dashboardUserWithRole(string $roleName): User
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

function createDashboardRun(): string
{
    $userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = DB::table('repositories')->where('slug', 'demo-repository')->value('id');
    $deviceId = (string) Str::ulid();
    $runId = (string) Str::ulid();
    $artifactId = (string) Str::ulid();
    $now = now();

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $userId,
        'name' => 'Dashboard Device',
        'fingerprint_hash' => 'sha256:dashboard-device',
        'platform_os' => 'darwin',
        'platform_arch' => 'arm64',
        'plugin_version' => '0.1.0',
        'last_seen_at' => $now,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('runs')->insert([
        'id' => $runId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => null,
        'task_id' => null,
        'device_id' => $deviceId,
        'started_by_user_id' => $userId,
        'runtime_profile' => 'agent_plugin',
        'status' => 'failed',
        'branch' => 'main',
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'summary' => 'Security report blocked import.',
        'risk_level' => 'high',
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
        'artifact_type' => 'security_report',
        'storage_path' => 'devboard/test/security-report.json',
        'sha256' => str_repeat('a', 64),
        'size_bytes' => 2,
        'mime_type' => 'application/json',
        'schema_version' => 'v1',
        'status' => 'rejected',
        'producer' => 'devboard-python-plugin',
        'metadata' => json_encode([
            'source_type' => 'local_plugin_snapshot',
            'blocked' => [
                ['path' => '.env', 'reason' => 'env_file'],
            ],
            'warnings' => [
                ['path' => 'vendor/package.php', 'reason' => 'generated_or_dependency_path'],
            ],
        ], JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('run_events')->insert([
        'id' => (string) Str::ulid(),
        'run_id' => $runId,
        'event_type' => 'security.blocked_upload',
        'severity' => 'critical',
        'message' => 'Blocked secret upload.',
        'payload' => json_encode(['risk_triggers' => ['secret_scan_blocked']], JSON_THROW_ON_ERROR),
        'created_at' => $now,
    ]);

    return $runId;
}

function createDashboardGenesisState(): void
{
    $userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = DB::table('repositories')->where('slug', 'demo-repository')->value('id');
    $deviceId = (string) Str::ulid();
    $workspaceId = (string) Str::ulid();
    $runId = (string) Str::ulid();
    $artifactId = (string) Str::ulid();
    $snapshotId = (string) Str::ulid();
    $revisionId = (string) Str::ulid();
    $now = now();

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $userId,
        'name' => 'Genesis Device',
        'fingerprint_hash' => 'sha256:genesis-device',
        'platform_os' => 'darwin',
        'platform_arch' => 'arm64',
        'plugin_version' => '0.1.0',
        'last_seen_at' => $now,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('local_workspaces')->insert([
        'id' => $workspaceId,
        'repository_id' => $repositoryId,
        'device_id' => $deviceId,
        'local_root_hash' => 'sha256:workspace',
        'display_path' => '/Users/gabriele/Dev/ai-sandbox-framework',
        'current_branch' => 'fase-1',
        'last_head_sha' => 'abc123',
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
        'branch' => 'fase-1',
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'summary' => 'Genesis imported.',
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
        'storage_path' => 'devboard/test/graph-snapshot.json',
        'sha256' => str_repeat('b', 64),
        'size_bytes' => 2,
        'mime_type' => 'application/json',
        'schema_version' => 'v1',
        'status' => 'imported',
        'producer' => 'devboard-python-plugin',
        'metadata' => json_encode(['source_type' => 'local_analyzer'], JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('snapshots')->insert([
        'id' => $snapshotId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => $workspaceId,
        'source_type' => 'local_plugin_snapshot',
        'branch' => 'fase-1',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'dirty_status' => 'clean',
        'file_inventory_artifact_id' => null,
        'graph_snapshot_artifact_id' => $artifactId,
        'created_by_run_id' => $runId,
        'created_at' => $now,
    ]);

    DB::table('genesis_imports')->insert([
        'id' => (string) Str::ulid(),
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => $workspaceId,
        'run_id' => $runId,
        'status' => 'active',
        'manifest_artifact_id' => null,
        'snapshot_id' => $snapshotId,
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'started_at' => $now,
        'finished_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $pageId = DB::table('wiki_pages')->where('slug', 'project-overview')->value('id');

    DB::table('wiki_revisions')->insert([
        'id' => $revisionId,
        'wiki_page_id' => $pageId,
        'author_user_id' => $userId,
        'author_device_id' => $deviceId,
        'producer' => 'devboard-python-plugin',
        'source_type' => 'local_analyzer',
        'source_status' => 'verified_from_code',
        'content_markdown' => '# Project Overview',
        'evidence_refs' => json_encode([
            ['type' => 'artifact', 'path' => 'file-inventory.json'],
        ], JSON_THROW_ON_ERROR),
        'created_at' => $now,
    ]);

    DB::table('wiki_pages')->where('id', $pageId)->update([
        'current_revision_id' => $revisionId,
        'source_status' => 'verified_from_code',
        'updated_at' => $now,
    ]);
}
