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
    Storage::fake('local');
    $this->withoutVite();
    $this->seed(DevBoardSeeder::class);
});

it('shows maintenance operation config on the system page for sysadmin users', function () {
    $sysadmin = maintenanceUserWithRole('Sysadmin');

    $this->actingAs($sysadmin)->get('/system')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('System/Show')
            ->where('operations.artifact_retention.run_href', '/system/artifact-retention')
            ->where('operations.artifact_retention.default_days', 90)
            ->where('operations.audit_export.run_href', '/system/audit-exports')
            ->where('operations.audit_export.formats', ['jsonl', 'csv'])
            ->where('operations.audit_export.actor_types', ['user', 'plugin', 'system'])
        );
});

it('lets an admin run artifact retention in dry-run mode from the system page', function () {
    $admin = maintenanceUserWithRole('Admin');
    $artifact = createSystemMaintenanceArtifact([
        'status' => 'validated',
        'updated_at' => now()->subDays(45),
    ]);

    $this->actingAs($admin)
        ->postJson('/system/artifact-retention', [
            'days' => 30,
            'limit' => 25,
            'dry_run' => true,
        ])
        ->assertOk()
        ->assertJson([
            'scanned' => 1,
            'purged' => 0,
            'would_purge' => 1,
            'skipped' => 0,
            'failed' => 0,
        ]);

    Storage::disk('local')->assertExists($artifact['storage_path']);
    expect(DB::table('artifacts')->where('id', $artifact['id'])->value('status'))->toBe('validated');
});

it('requires explicit confirmation before a live artifact retention purge', function () {
    $admin = maintenanceUserWithRole('Admin');
    $artifact = createSystemMaintenanceArtifact([
        'status' => 'imported',
        'updated_at' => now()->subDays(45),
    ]);

    $this->actingAs($admin)
        ->postJson('/system/artifact-retention', [
            'days' => 30,
            'dry_run' => false,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['confirm_purge']);

    Storage::disk('local')->assertExists($artifact['storage_path']);
    expect(DB::table('artifacts')->where('id', $artifact['id'])->value('status'))->toBe('imported');
});

it('lets an admin export audit logs from the system page', function () {
    $admin = maintenanceUserWithRole('Admin');
    createMaintenanceAuditLog('artifact.purged', ['artifact_id' => 'artifact_1']);
    createMaintenanceAuditLog('wiki.updated', ['page_id' => 'wiki_1']);

    $response = $this->actingAs($admin)
        ->postJson('/system/audit-exports', [
            'format' => 'csv',
            'filters' => [
                'action' => 'artifact.purged',
                'actor_type' => 'system',
            ],
        ])
        ->assertOk()
        ->assertJsonPath('format', 'csv')
        ->assertJsonPath('row_count', 1);

    $path = $response->json('path');

    expect($path)->toBeString();
    Storage::disk('local')->assertExists($path);
    expect(Storage::disk('local')->get($path))->toContain('artifact.purged');
    expect(Storage::disk('local')->get($path))->not->toContain('wiki.updated');
});

it('requires explicit confirmation before rotating a plugin token', function () {
    $admin = maintenanceUserWithRole('Admin');

    $token = $this->actingAs($admin)->postJson('/admin/plugin-tokens', [
        'name' => 'Confirmable plugin',
        'scopes' => ['projects.read'],
        'expires_in_days' => 30,
    ])->json('token');

    $this->actingAs($admin)
        ->postJson("/admin/plugin-tokens/{$token['id']}/rotate")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['confirm_rotate']);

    expect(DB::table('audit_logs')
        ->where('action', 'token.rotated')
        ->where('target_id', $token['id'])
        ->exists())->toBeFalse();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array{id: string, storage_path: string}
 */
function createSystemMaintenanceArtifact(array $overrides = []): array
{
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = DB::table('repositories')->where('slug', 'demo-repository')->value('id');
    $userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $deviceId = (string) Str::ulid();
    $runId = (string) Str::ulid();
    $artifactId = (string) Str::ulid();
    $storagePath = "devboard/artifacts/system-maintenance/{$artifactId}.json";
    $now = now();

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $userId,
        'name' => 'System Maintenance Device',
        'fingerprint_hash' => 'sha256:system-maintenance-'.$artifactId,
        'platform_os' => 'linux',
        'platform_arch' => 'amd64',
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
        'status' => 'finished',
        'branch' => 'main',
        'base_branch' => 'main',
        'base_sha' => 'abc123',
        'head_sha' => 'abc123',
        'summary' => null,
        'risk_level' => 'low',
        'started_at' => $now->copy()->subDays(46),
        'finished_at' => $now->copy()->subDays(45),
        'created_at' => $now->copy()->subDays(46),
        'updated_at' => $now->copy()->subDays(45),
    ]);

    Storage::disk('local')->put($storagePath, '{"nodes":[],"relationships":[]}');

    DB::table('artifacts')->insert(array_merge([
        'id' => $artifactId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'run_id' => $runId,
        'artifact_type' => 'graph_snapshot',
        'storage_path' => $storagePath,
        'sha256' => hash('sha256', '{"nodes":[],"relationships":[]}'),
        'size_bytes' => 30,
        'mime_type' => 'application/json',
        'schema_version' => 'v1',
        'status' => 'imported',
        'producer' => 'devboard-python-plugin',
        'metadata' => '{}',
        'created_at' => $now->copy()->subDays(46),
        'updated_at' => $now->copy()->subDays(45),
    ], $overrides));

    return [
        'id' => $artifactId,
        'storage_path' => $storagePath,
    ];
}

/**
 * @param  array<string, mixed>  $payload
 */
function createMaintenanceAuditLog(string $action, array $payload): void
{
    DB::table('audit_logs')->insert([
        'id' => (string) Str::ulid(),
        'actor_user_id' => null,
        'actor_device_id' => null,
        'actor_type' => 'system',
        'action' => $action,
        'target_type' => Str::before($action, '.'),
        'target_id' => (string) Str::ulid(),
        'ip_address' => null,
        'user_agent' => null,
        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        'created_at' => now(),
    ]);
}

function maintenanceUserWithRole(string $roleName): User
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
