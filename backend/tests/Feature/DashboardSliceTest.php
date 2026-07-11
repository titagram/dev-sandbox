<?php

use App\Models\User;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seed(DevBoardSeeder::class);
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
                $items = collect($navigation);

                expect($items->pluck('label')->all())->not->toContain('Admin');
                expect($items->firstWhere('key', 'graph')['href'] ?? null)->toBe('/graph');
                expect($items->firstWhere('key', 'runs')['href'] ?? null)->toBe('/runs');
                expect($items->firstWhere('key', 'wiki')['href'] ?? null)->toBe('/wiki');
                expect($items->firstWhere('key', 'artifacts')['href'] ?? null)->toBe('/artifacts');

                return true;
            })
            ->has('recentRuns')
        );
});

it('shows an artifacts index with download and run links when available', function () {
    Storage::fake('local');

    $pm = dashboardUserWithRole('PM');
    $deltaRunId = createDashboardDeltaRun();
    $graphRunId = createDashboardGraphViewRun();

    $this->actingAs($pm)->get('/artifacts')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Artifacts/Index')
            ->where('artifacts', function ($artifacts) use ($deltaRunId, $graphRunId) {
                $items = collect($artifacts);
                $diffArtifact = $items->firstWhere('artifact_type', 'diff_summary');
                $graphArtifact = $items->firstWhere('run_id', $graphRunId);

                expect($diffArtifact['status'] ?? null)->toBe('validated');
                expect($diffArtifact['source_label'] ?? null)->toBe('local_plugin_snapshot');
                expect($diffArtifact['run']['href'] ?? null)->toBe("/runs/{$deltaRunId}");
                expect($diffArtifact['download_href'] ?? null)->not->toBeNull();
                expect($graphArtifact['artifact_type'] ?? null)->toBe('graph_snapshot');
                expect($graphArtifact['run']['href'] ?? null)->toBe("/runs/{$graphRunId}");

                return true;
            })
        );
});

it('shows a wiki index with source status and page links', function () {
    Storage::fake('local');

    $pm = dashboardUserWithRole('PM');
    createDashboardGenesisState();
    [, $pageId] = createDashboardRunWithAffectedWikiPage();

    $this->actingAs($pm)->get('/wiki')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Wiki/Index')
            ->where('pages', function ($pages) use ($pageId) {
                $items = collect($pages);
                $routesPage = $items->firstWhere('id', $pageId);

                expect($routesPage['slug'] ?? null)->toBe('technical/routes');
                expect($routesPage['source_status'] ?? null)->toBe('verified_from_code');
                expect($routesPage['source_type'] ?? null)->toBe('local_analyzer');
                expect($routesPage['evidence_count'] ?? null)->toBe(2);
                expect($routesPage['href'] ?? null)->toBe("/wiki/pages/{$pageId}");

                return true;
            })
        );
});

it('shows a runs index with detail, task, and graph links when available', function () {
    Storage::fake('local');

    $pm = dashboardUserWithRole('PM');
    [$taskId, $taskRunId] = createDashboardTaskWithLinkedRun();
    $graphRunId = createDashboardGraphViewRun();

    $this->actingAs($pm)->get('/runs')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Runs/Index')
            ->where('runs', function ($runs) use ($graphRunId, $taskId, $taskRunId) {
                $items = collect($runs);
                $graphRun = $items->firstWhere('id', $graphRunId);
                $taskRun = $items->firstWhere('id', $taskRunId);

                expect($graphRun['graph_href'] ?? null)->toBe("/graph?run={$graphRunId}");
                expect($graphRun['detail_href'] ?? null)->toBe("/runs/{$graphRunId}");
                expect($taskRun['task']['id'] ?? null)->toBe($taskId);
                expect($taskRun['task']['href'] ?? null)->toBe("/tasks/{$taskId}");
                expect($taskRun['source_label'] ?? null)->toBe('local_plugin_snapshot');

                return true;
            })
        );
});

it('shows task detail links on Kanban and renders the task detail page', function () {
    $pm = dashboardUserWithRole('PM');
    [$taskId, $runId] = createDashboardTaskWithLinkedRun();

    $this->actingAs($pm)->get('/kanban')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Kanban/Index')
            ->where('columns.1.tasks.0.id', $taskId)
            ->where('columns.1.tasks.0.href', "/tasks/{$taskId}")
            ->where('columns.1.tasks.0.linked_run.id', $runId)
        );

    $this->actingAs($pm)->get("/tasks/{$taskId}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tasks/Show')
            ->where('task.id', $taskId)
            ->where('task.title', 'Stabilize onboarding dashboard')
            ->where('task.status.name', 'Ready')
            ->where('task.owner.name', 'DevBoard Admin')
            ->where('task.linked_run.id', $runId)
            ->where('task.linked_run.href', "/runs/{$runId}")
            ->where('task.source_label', 'local_plugin_snapshot')
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

it('shows agent options on project detail', function () {
    $pm = dashboardUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');

    $this->actingAs($pm)->get("/projects/{$projectId}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Show')
            ->where('assistant.agent_options.0.agent_key', 'socrates')
            ->where('assistant.agent_options.0.label', 'Socrates')
            ->where('assistant.agent_options.0.runtime', 'server_agent')
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

it('shows the linked task action on run detail when the run belongs to a task', function () {
    $pm = dashboardUserWithRole('PM');
    [$taskId, $runId] = createDashboardTaskWithLinkedRun();

    $this->actingAs($pm)->get("/runs/{$runId}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Runs/Show')
            ->where('linkedTask.id', $taskId)
            ->where('linkedTask.title', 'Stabilize onboarding dashboard')
            ->where('linkedTask.href', "/tasks/{$taskId}")
        );
});

it('shows the graph view action on run detail and renders graph summary', function () {
    Storage::fake('local');

    $pm = dashboardUserWithRole('PM');
    $runId = createDashboardGraphViewRun();

    $this->actingAs($pm)->get("/runs/{$runId}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Runs/Show')
            ->where('graphView.href', "/graph?run={$runId}")
            ->where('graphView.status', 'imported')
            ->where('state.graph_extraction_mode', 'lightweight_fallback')
        );

    $this->actingAs($pm)->get("/graph?run={$runId}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Graph/Show')
            ->where('snapshot.run_id', $runId)
            ->where('graph.artifact_status', 'imported')
            ->where('graph.node_count', 3)
            ->where('graph.relationship_count', 2)
            ->where('graph.extraction_mode', 'lightweight_fallback')
            ->where('graph.parser', 'regex')
            ->where('graph.labels.0.name', 'File')
            ->where('graph.labels.0.count', 2)
            ->where('sourceLabel', 'local_plugin_snapshot')
        );
});

it('shows delta summary, device, and risk report details on delta run detail', function () {
    Storage::fake('local');

    $pm = dashboardUserWithRole('PM');
    $runId = createDashboardDeltaRun();

    $this->actingAs($pm)->get("/runs/{$runId}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Runs/Show')
            ->where('runContext.kind', 'delta_sync')
            ->where('runContext.device_name', 'Delta Dashboard Device')
            ->where('summary.diff.changed_file_count', 37)
            ->where('summary.diff.additions', 840)
            ->where('summary.diff.deletions', 120)
            ->where('summary.tests.status', 'failed')
            ->where('summary.tests.summary', '2 passed, 1 failed')
            ->where('risk.report.summary', 'Large multi-file delta with failing tests.')
            ->where('risk.report.triggers.0', 'large_multi_file_diff')
            ->where('risk.report.triggers.1', 'test_failures')
        );
});

it('lets a PM download a validated run artifact', function () {
    Storage::fake('local');

    $pm = dashboardUserWithRole('PM');
    $runId = createDashboardDeltaRun();
    $artifactId = DB::table('artifacts')->where('run_id', $runId)->where('artifact_type', 'diff_summary')->value('id');

    $this->actingAs($pm)
        ->get("/runs/{$runId}/artifacts/{$artifactId}/download")
        ->assertOk()
        ->assertDownload('diff-summary.json');
});

it('lets a Developer mark a run as reviewed', function () {
    $developer = dashboardUserWithRole('Developer');
    $runId = createDashboardRun();

    $this->actingAs($developer)
        ->postJson("/runs/{$runId}/review")
        ->assertOk()
        ->assertJson(['reviewed' => true]);

    expect(DB::table('run_events')
        ->where('run_id', $runId)
        ->where('event_type', 'run.reviewed')
        ->count())->toBe(1);
});

it('lets a Developer retry a failed graph import from run detail', function () {
    Storage::fake('local');
    config(['services.devboard.graph_import_mode' => 'fake']);

    $developer = dashboardUserWithRole('Developer');
    $runId = createDashboardRetryableImportRun();

    $this->actingAs($developer)
        ->postJson("/runs/{$runId}/retry-import")
        ->assertOk()
        ->assertJson(['retried' => true]);

    expect(DB::table('genesis_imports')->where('run_id', $runId)->value('status'))->toBe('active');
    expect(DB::table('run_events')->where('run_id', $runId)->where('event_type', 'graph.imported')->exists())->toBeTrue();
});

it('shows affected wiki pages on run detail when evidence points to run artifacts', function () {
    Storage::fake('local');

    $pm = dashboardUserWithRole('PM');
    [$runId, $pageId] = createDashboardRunWithAffectedWikiPage();

    $this->actingAs($pm)->get("/runs/{$runId}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Runs/Show')
            ->where('affectedWikiPages.0.id', $pageId)
            ->where('affectedWikiPages.0.slug', 'technical/routes')
            ->where('affectedWikiPages.0.href', "/wiki/pages/{$pageId}")
        );
});

it('renders a wiki page with source banner and evidence details', function () {
    Storage::fake('local');

    $pm = dashboardUserWithRole('PM');
    [, $pageId] = createDashboardRunWithAffectedWikiPage();

    $this->actingAs($pm)->get("/wiki/pages/{$pageId}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Wiki/Show')
            ->where('page.slug', 'technical/routes')
            ->where('page.source_status', 'verified_from_code')
            ->where('revision.source_type', 'local_analyzer')
            ->where('revision.evidence_refs.0.artifact_id', 'wiki-artifact-123')
        );
});

it('blocks PM access to Admin token page', function () {
    $pm = dashboardUserWithRole('PM');

    $this->actingAs($pm)->get('/admin/plugin-tokens')->assertForbidden();
});

it('lets a Sysadmin open the system page and blocks PM access', function () {
    $sysadmin = dashboardUserWithRole('Sysadmin');
    $pm = dashboardUserWithRole('PM');
    createDashboardDeltaRun();

    $this->actingAs($sysadmin)->get('/system')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('System/Show')
            ->where('dashboard.navigation', function ($navigation) {
                $items = collect($navigation);

                expect($items->firstWhere('key', 'system')['href'] ?? null)->toBe('/system');

                return true;
            })
            ->where('health.active_devices', 1)
            ->where('health.runs_total', 1)
            ->where('health.repositories_total', 1)
        );

    $this->actingAs($pm)->get('/system')->assertForbidden();
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

it('lets Admin rotate a plugin token and invalidates the old secret', function () {
    $admin = dashboardUserWithRole('Admin');

    $created = $this->actingAs($admin)->postJson('/admin/plugin-tokens', [
        'name' => 'Rotatable plugin',
        'scopes' => ['projects.read'],
        'expires_in_days' => 30,
    ])->json();

    $oldPlainToken = $created['plain_token'];
    $token = $created['token'];

    $response = $this->actingAs($admin)->postJson("/admin/plugin-tokens/{$token['id']}/rotate", [
        'confirm_rotate' => true,
    ])
        ->assertOk()
        ->assertJsonStructure(['plain_token', 'token']);

    $newPlainToken = $response->json('plain_token');

    expect($newPlainToken)->toStartWith($token['token_prefix'].'|');
    expect($newPlainToken)->not->toBe($oldPlainToken);

    $this->postJson('/api/plugin/v1/auth/check', ['protocol_version' => 'v1'], pluginAuthHeaders($oldPlainToken))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthorized');

    $this->postJson('/api/plugin/v1/auth/check', ['protocol_version' => 'v1'], pluginAuthHeaders($newPlainToken))
        ->assertOk()
        ->assertJsonPath('authenticated', true);

    expect(DB::table('audit_logs')
        ->where('action', 'token.rotated')
        ->where('target_type', 'api_token')
        ->where('target_id', $token['id'])
        ->exists())->toBeTrue();
});

it('shows registered plugin devices on the Admin token page', function () {
    $admin = dashboardUserWithRole('Admin');
    $device = createAdminDeviceRecord();

    $this->actingAs($admin)->get('/admin/plugin-tokens')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Tokens')
            ->where('devices', function ($devices) use ($device) {
                $items = collect($devices);
                $row = $items->firstWhere('id', $device['device_id']);

                expect($row['name'] ?? null)->toBe('Test Device');
                expect($row['user_email'] ?? null)->toBe($device['user_email']);
                expect($row['status'] ?? null)->toBe('active');
                expect($row['bound_token_count'] ?? null)->toBe(1);
                expect($row['revoke_href'] ?? null)->toBe("/admin/devices/{$device['device_id']}");

                return true;
            })
        );
});

it('lets Admin revoke a registered plugin device from the Admin area', function () {
    $admin = dashboardUserWithRole('Admin');
    $device = createAdminDeviceRecord();

    $this->actingAs($admin)
        ->deleteJson("/admin/devices/{$device['device_id']}")
        ->assertOk()
        ->assertJson(['revoked' => true]);

    expect(DB::table('devices')->where('id', $device['device_id'])->value('status'))->toBe('revoked');

    $this->postJson('/api/plugin/v1/auth/check', ['protocol_version' => 'v1'], pluginAuthHeaders($device['plain_token']))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'device_required');

    expect(DB::table('audit_logs')
        ->where('action', 'device.revoked')
        ->where('target_type', 'device')
        ->where('target_id', $device['device_id'])
        ->exists())->toBeTrue();
});

it('blocks PM from revoking a registered plugin device', function () {
    $pm = dashboardUserWithRole('PM');
    $device = createAdminDeviceRecord();

    $this->actingAs($pm)
        ->deleteJson("/admin/devices/{$device['device_id']}")
        ->assertForbidden();

    expect(DB::table('devices')->where('id', $device['device_id'])->value('status'))->toBe('active');
});

it('returns 404 when Admin revokes an unknown plugin device', function () {
    $admin = dashboardUserWithRole('Admin');

    $this->actingAs($admin)
        ->deleteJson('/admin/devices/'.Str::ulid())
        ->assertNotFound();
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

function pluginAuthHeaders(string $plainToken): array
{
    return [
        'Authorization' => 'Bearer '.$plainToken,
        'X-DevBoard-Protocol' => 'v1',
        'X-DevBoard-Plugin-Version' => '0.1.0',
    ];
}

/**
 * @return array{device_id: string, plain_token: string, user_email: string}
 */
function createAdminDeviceRecord(): array
{
    $user = User::factory()->create(['status' => 'active']);
    $deviceId = (string) Str::ulid();
    $tokenId = (string) Str::ulid();
    $secret = 'device-admin-secret';
    $prefix = 'devb_live_'.$tokenId;
    $now = now();

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $user->id,
        'name' => 'Test Device',
        'fingerprint_hash' => 'sha256:test-admin-device',
        'platform_os' => 'darwin',
        'platform_arch' => 'arm64',
        'plugin_version' => '0.1.0',
        'last_seen_at' => $now,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('api_tokens')->insert([
        'id' => $tokenId,
        'token_prefix' => $prefix,
        'token_hash' => hash('sha256', $secret),
        'user_id' => $user->id,
        'device_id' => $deviceId,
        'name' => 'Device-bound token',
        'scopes' => json_encode(['projects.read', 'repositories.read', 'runs.write'], JSON_THROW_ON_ERROR),
        'expires_at' => $now->copy()->addMonth(),
        'revoked_at' => null,
        'last_used_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'device_id' => $deviceId,
        'plain_token' => $prefix.'|'.$secret,
        'user_email' => $user->email,
    ];
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

/**
 * @return array{0: string, 1: string}
 */
function createDashboardTaskWithLinkedRun(): array
{
    $runId = createDashboardRun();
    $userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $readyColumnId = DB::table('kanban_columns')->where('status_key', 'ready')->value('id');
    $taskId = (string) Str::ulid();
    $now = now();

    DB::table('tasks')->insert([
        'id' => $taskId,
        'project_id' => $projectId,
        'title' => 'Stabilize onboarding dashboard',
        'description' => 'Close the last dashboard navigation gaps for onboarding and Genesis import.',
        'status_column_id' => $readyColumnId,
        'priority' => 'high',
        'risk_level' => 'medium',
        'owner_user_id' => $userId,
        'created_by_user_id' => $userId,
        'due_at' => $now->copy()->addDay(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('runs')->where('id', $runId)->update([
        'task_id' => $taskId,
        'updated_at' => $now,
    ]);

    return [$taskId, $runId];
}

function createDashboardGraphViewRun(): string
{
    $userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = DB::table('repositories')->where('slug', 'demo-repository')->value('id');
    $deviceId = (string) Str::ulid();
    $workspaceId = (string) Str::ulid();
    $runId = (string) Str::ulid();
    $artifactId = (string) Str::ulid();
    $snapshotId = (string) Str::ulid();
    $now = now();
    $storagePath = "devboard/test/graph-view-{$artifactId}.json";

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $userId,
        'name' => 'Graph View Device',
        'fingerprint_hash' => 'sha256:graph-view-device',
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
        'local_root_hash' => 'sha256:graph-view-workspace',
        'display_path' => '/Users/gabriele/Dev/ai-sandbox-framework',
        'current_branch' => 'feature/graph-view',
        'last_head_sha' => 'def456',
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
        'branch' => 'feature/graph-view',
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'summary' => 'Graph snapshot imported successfully.',
        'risk_level' => 'low',
        'started_at' => $now->copy()->subMinutes(3),
        'finished_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Storage::disk('local')->put($storagePath, json_encode([
        'nodes' => [
            ['id' => 'file:app.py', 'labels' => ['File'], 'properties' => ['path' => 'app.py']],
            ['id' => 'file:routes.py', 'labels' => ['File'], 'properties' => ['path' => 'routes.py']],
            ['id' => 'class:TaskShowController', 'labels' => ['Class'], 'properties' => ['name' => 'TaskShowController']],
        ],
        'relationships' => [
            ['type' => 'DECLARES', 'from' => 'file:app.py', 'to' => 'class:TaskShowController'],
            ['type' => 'REFERENCES', 'from' => 'file:routes.py', 'to' => 'class:TaskShowController'],
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
        'metadata' => json_encode([
            'source_type' => 'local_plugin_snapshot',
            'graph_extraction_mode' => 'lightweight_fallback',
            'graph_parser' => 'regex',
            'graph_analyzer' => 'lightweight_fallback',
        ], JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('snapshots')->insert([
        'id' => $snapshotId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => $workspaceId,
        'source_type' => 'local_plugin_snapshot',
        'branch' => 'feature/graph-view',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'dirty_status' => 'clean',
        'file_inventory_artifact_id' => null,
        'graph_snapshot_artifact_id' => $artifactId,
        'created_by_run_id' => $runId,
        'created_at' => $now,
    ]);

    return $runId;
}

function createDashboardDeltaRun(): string
{
    $userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = DB::table('repositories')->where('slug', 'demo-repository')->value('id');
    $deviceId = (string) Str::ulid();
    $workspaceId = (string) Str::ulid();
    $runId = (string) Str::ulid();
    $deltaId = (string) Str::ulid();
    $diffArtifactId = (string) Str::ulid();
    $riskArtifactId = (string) Str::ulid();
    $testArtifactId = (string) Str::ulid();
    $now = now();

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $userId,
        'name' => 'Delta Dashboard Device',
        'fingerprint_hash' => 'sha256:delta-dashboard-device',
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
        'local_root_hash' => 'sha256:delta-dashboard-workspace',
        'display_path' => '/Users/gabriele/Dev/ai-sandbox-framework',
        'current_branch' => 'feature/delta-audit',
        'last_head_sha' => 'def456',
        'dirty_status' => 'dirty',
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
        'status' => 'failed',
        'branch' => 'feature/delta-audit',
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'summary' => 'Delta sync failed after test failures.',
        'risk_level' => 'high',
        'started_at' => $now->copy()->subMinutes(5),
        'finished_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('delta_syncs')->insert([
        'id' => $deltaId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => $workspaceId,
        'run_id' => $runId,
        'base_snapshot_id' => null,
        'new_snapshot_id' => null,
        'status' => 'failed',
        'branch' => 'feature/delta-audit',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'dirty_status' => 'dirty',
        'changed_file_count' => 37,
        'risk_level' => 'high',
        'started_at' => $now->copy()->subMinutes(5),
        'finished_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Storage::disk('local')->put('devboard/test/diff-summary.json', json_encode([
        'changed_file_count' => 37,
        'additions' => 840,
        'deletions' => 120,
    ], JSON_THROW_ON_ERROR));
    Storage::disk('local')->put('devboard/test/risk-report.json', json_encode([
        'summary' => 'Large multi-file delta with failing tests.',
        'triggers' => ['large_multi_file_diff', 'test_failures'],
        'risk_level' => 'high',
    ], JSON_THROW_ON_ERROR));
    Storage::disk('local')->put('devboard/test/test-map.json', json_encode([
        'status' => 'failed',
        'summary' => '2 passed, 1 failed',
    ], JSON_THROW_ON_ERROR));

    DB::table('artifacts')->insert([
        [
            'id' => $diffArtifactId,
            'project_id' => $projectId,
            'repository_id' => $repositoryId,
            'run_id' => $runId,
            'artifact_type' => 'diff_summary',
            'storage_path' => 'devboard/test/diff-summary.json',
            'sha256' => hash('sha256', Storage::disk('local')->get('devboard/test/diff-summary.json')),
            'size_bytes' => Storage::disk('local')->size('devboard/test/diff-summary.json'),
            'mime_type' => 'application/json',
            'schema_version' => 'v1',
            'status' => 'validated',
            'producer' => 'devboard-python-plugin',
            'metadata' => json_encode(['source_type' => 'local_plugin_snapshot'], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id' => $riskArtifactId,
            'project_id' => $projectId,
            'repository_id' => $repositoryId,
            'run_id' => $runId,
            'artifact_type' => 'risk_report',
            'storage_path' => 'devboard/test/risk-report.json',
            'sha256' => hash('sha256', Storage::disk('local')->get('devboard/test/risk-report.json')),
            'size_bytes' => Storage::disk('local')->size('devboard/test/risk-report.json'),
            'mime_type' => 'application/json',
            'schema_version' => 'v1',
            'status' => 'validated',
            'producer' => 'devboard-python-plugin',
            'metadata' => json_encode(['source_type' => 'local_plugin_snapshot'], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id' => $testArtifactId,
            'project_id' => $projectId,
            'repository_id' => $repositoryId,
            'run_id' => $runId,
            'artifact_type' => 'test_map',
            'storage_path' => 'devboard/test/test-map.json',
            'sha256' => hash('sha256', Storage::disk('local')->get('devboard/test/test-map.json')),
            'size_bytes' => Storage::disk('local')->size('devboard/test/test-map.json'),
            'mime_type' => 'application/json',
            'schema_version' => 'v1',
            'status' => 'validated',
            'producer' => 'devboard-python-plugin',
            'metadata' => json_encode(['source_type' => 'local_plugin_snapshot'], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    DB::table('run_events')->insert([
        'id' => (string) Str::ulid(),
        'run_id' => $runId,
        'event_type' => 'run.finished',
        'severity' => 'error',
        'message' => 'Delta sync failed after tests.',
        'payload' => json_encode([
            'risk_report' => [
                'summary' => 'Large multi-file delta with failing tests.',
                'triggers' => ['large_multi_file_diff', 'test_failures'],
                'risk_level' => 'high',
            ],
        ], JSON_THROW_ON_ERROR),
        'created_at' => $now,
    ]);

    return $runId;
}

function createDashboardRetryableImportRun(): string
{
    $userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = DB::table('repositories')->where('slug', 'demo-repository')->value('id');
    $deviceId = (string) Str::ulid();
    $workspaceId = (string) Str::ulid();
    $runId = (string) Str::ulid();
    $artifactId = (string) Str::ulid();
    $snapshotId = (string) Str::ulid();
    $importId = (string) Str::ulid();
    $now = now();
    $storagePath = "devboard/artifacts/genesis/{$importId}/{$artifactId}/artifact";

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $userId,
        'name' => 'Retryable Import Device',
        'fingerprint_hash' => 'sha256:retryable-import-device',
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
        'local_root_hash' => 'sha256:retryable-import-workspace',
        'display_path' => '/Users/gabriele/Dev/ai-sandbox-framework',
        'current_branch' => 'feature/retry-import',
        'last_head_sha' => 'def456',
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
        'status' => 'failed',
        'branch' => 'feature/retry-import',
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'summary' => 'Graph import failed.',
        'risk_level' => 'medium',
        'started_at' => $now->copy()->subMinutes(5),
        'finished_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Storage::disk('local')->put($storagePath, json_encode([
        'nodes' => [
            ['id' => 'file:app.py', 'labels' => ['File'], 'properties' => ['path' => 'app.py']],
        ],
        'relationships' => [],
    ], JSON_THROW_ON_ERROR));

    DB::table('artifacts')->insert([
        'id' => $artifactId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'run_id' => $runId,
        'artifact_type' => 'graph_snapshot',
        'storage_path' => $storagePath,
        'sha256' => str_repeat('a', 64),
        'size_bytes' => 1,
        'mime_type' => 'application/json',
        'schema_version' => 'v1',
        'status' => 'validated',
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
        'branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'dirty_status' => 'clean',
        'file_inventory_artifact_id' => null,
        'graph_snapshot_artifact_id' => $artifactId,
        'created_by_run_id' => $runId,
        'created_at' => $now,
    ]);

    DB::table('genesis_imports')->insert([
        'id' => $importId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => $workspaceId,
        'run_id' => $runId,
        'status' => 'failed',
        'manifest_artifact_id' => null,
        'snapshot_id' => $snapshotId,
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'def456',
        'started_at' => $now->copy()->subMinutes(5),
        'finished_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('run_events')->insert([
        'id' => (string) Str::ulid(),
        'run_id' => $runId,
        'event_type' => 'graph.import_failed',
        'severity' => 'error',
        'message' => 'Genesis graph import failed after queue retries.',
        'payload' => json_encode([
            'genesis_import_id' => $importId,
            'tries' => 3,
            'backoff' => [10, 60, 300],
        ], JSON_THROW_ON_ERROR),
        'created_at' => $now,
    ]);

    return $runId;
}

/**
 * @return array{0: string, 1: string}
 */
function createDashboardRunWithAffectedWikiPage(): array
{
    $runId = createDashboardRun();
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = DB::table('repositories')->where('slug', 'demo-repository')->value('id');
    $userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $deviceId = DB::table('runs')->where('id', $runId)->value('device_id');
    $pageId = (string) Str::ulid();
    $revisionId = (string) Str::ulid();
    $now = now();

    DB::table('wiki_pages')->insert([
        'id' => $pageId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'slug' => 'technical/routes',
        'title' => 'Routes',
        'page_type' => 'technical',
        'current_revision_id' => null,
        'source_status' => 'verified_from_code',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('wiki_revisions')->insert([
        'id' => $revisionId,
        'wiki_page_id' => $pageId,
        'author_user_id' => $userId,
        'author_device_id' => $deviceId,
        'producer' => 'devboard-python-plugin',
        'source_type' => 'local_analyzer',
        'source_status' => 'verified_from_code',
        'content_markdown' => "# Routes\n\nGenerated from analyzer evidence.",
        'evidence_refs' => json_encode([
            ['type' => 'artifact', 'artifact_id' => 'wiki-artifact-123', 'description' => 'route-index.json'],
            ['type' => 'artifact', 'artifact_id' => DB::table('artifacts')->where('run_id', $runId)->value('id')],
        ], JSON_THROW_ON_ERROR),
        'created_at' => $now,
    ]);

    DB::table('wiki_pages')->where('id', $pageId)->update([
        'current_revision_id' => $revisionId,
        'updated_at' => $now,
    ]);

    return [$runId, $pageId];
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
