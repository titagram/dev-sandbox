<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

it('lets admin and pm declare repositories and exposes kickstart pairing state', function () {
    $admin = kickstartUserWithRole('Admin');
    $pm = kickstartUserWithRole('PM');

    $project = $this->actingAs($admin)
        ->postJson('/api/dashboard/projects', [
            'name' => 'Live Test Project',
            'key' => 'live-test-project',
            'description' => 'Project used to verify live local workspace pairing.',
        ])
        ->assertCreated()
        ->json();

    $projectId = (string) $project['id'];

    $this->actingAs($admin)
        ->getJson("/api/dashboard/projects/{$projectId}")
        ->assertOk()
        ->assertJsonPath('repository_count', 0)
        ->assertJsonPath('kickstart.state', 'awaiting_repository_declaration')
        ->assertJsonPath('kickstart.steps.0.key', 'project_intake')
        ->assertJsonPath('kickstart.steps.0.status', 'complete')
        ->assertJsonPath('kickstart.steps.1.key', 'repository_declaration')
        ->assertJsonPath('kickstart.steps.1.status', 'current')
        ->assertJsonPath('kickstart.pairing.api_base', '/api/plugin/v1');

    $response = $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/repositories", [
            'name' => 'Target App',
            'key' => 'target-app',
            'default_branch' => 'main',
            'protected_paths' => ['.env', '*.pem'],
            'excluded_paths' => ['node_modules/', 'vendor/'],
            'stack_hints' => ['laravel', 'react'],
        ])
        ->assertCreated()
        ->assertJsonPath('repository_count', 1)
        ->assertJsonPath('repositories.0.name', 'Target App')
        ->assertJsonPath('repositories.0.key', 'target-app')
        ->assertJsonPath('repositories.0.local_workspace.status', 'missing')
        ->assertJsonPath('kickstart.state', 'awaiting_local_workspace_link')
        ->assertJsonPath('kickstart.steps.1.status', 'complete')
        ->assertJsonPath('kickstart.steps.2.status', 'current');

    $repositoryId = (string) $response->json('repositories.0.id');

    expect(DB::table('repositories')->where('id', $repositoryId)->value('slug'))->toBe('target-app')
        ->and(DB::table('audit_logs')->where('action', 'repository.declared')->where('target_id', $repositoryId)->exists())->toBeTrue();

    $token = kickstartPluginTokenWithDevice();

    $this->postJson("/api/plugin/v1/repositories/{$repositoryId}/local-workspaces", [
        'protocol_version' => 'v1',
        'local_root_hash' => 'sha256:live-test-root',
        'display_path' => '/home/gabriele/projects/target-app',
        'current_branch' => 'main',
        'last_head_sha' => 'abc123',
        'dirty_status' => 'clean',
    ], kickstartPluginHeaders($token))->assertOk();

    $this->actingAs($pm)
        ->getJson("/api/dashboard/projects/{$projectId}")
        ->assertOk()
        ->assertJsonPath('kickstart.state', 'awaiting_genesis')
        ->assertJsonPath('kickstart.steps.2.status', 'complete')
        ->assertJsonPath('kickstart.steps.3.status', 'current')
        ->assertJsonPath('repositories.0.local_workspace.status', 'linked')
        ->assertJsonPath('repositories.0.local_workspace.display_path', '/home/gabriele/projects/target-app')
        ->assertJsonPath('repositories.0.local_workspace.current_branch', 'main')
        ->assertJsonPath('repositories.0.local_workspace.dirty_status', 'clean');
});

it('keeps repository declaration behind dashboard roles and active project state', function () {
    $admin = kickstartUserWithRole('Admin');
    $developer = kickstartUserWithRole('Developer');

    $project = $this->actingAs($admin)
        ->postJson('/api/dashboard/projects', [
            'name' => 'Guarded Kickstart',
            'key' => 'guarded-kickstart',
            'description' => 'Guard repository declaration.',
        ])
        ->assertCreated()
        ->json();

    $projectId = (string) $project['id'];

    $this->actingAs($developer)
        ->postJson("/api/dashboard/projects/{$projectId}/repositories", [
            'name' => 'Blocked Repo',
            'key' => 'blocked-repo',
            'default_branch' => 'main',
        ])
        ->assertForbidden();

    $this->actingAs($admin)
        ->postJson("/api/dashboard/projects/{$projectId}/archive", ['reason' => 'Pause setup'])
        ->assertOk();

    $this->actingAs($admin)
        ->postJson("/api/dashboard/projects/{$projectId}/repositories", [
            'name' => 'Archived Repo',
            'key' => 'archived-repo',
            'default_branch' => 'main',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_not_active');
});

function kickstartUserWithRole(string $roleName): User
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
 * @return array{id: string, plain_token: string, secret: string, device_id: string}
 */
function kickstartPluginTokenWithDevice(): array
{
    $userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $tokenId = (string) Str::ulid();
    $deviceId = (string) Str::ulid();
    $secret = 'kickstart-plugin-secret';
    $prefix = 'devb_live_'.$tokenId;
    $now = now();

    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $userId,
        'name' => 'Kickstart Test Device',
        'fingerprint_hash' => 'sha256:kickstart-device',
        'platform_os' => 'linux',
        'platform_arch' => 'amd64',
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
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => 'Kickstart Test Token',
        'scopes' => json_encode([
            'projects.read',
            'repositories.read',
            'policies.read',
            'runs.write',
            'artifacts.write',
            'wiki.write',
            'graph.write',
        ], JSON_THROW_ON_ERROR),
        'expires_at' => now()->addMonth(),
        'revoked_at' => null,
        'last_used_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'id' => $tokenId,
        'plain_token' => $prefix.'|'.$secret,
        'secret' => $secret,
        'device_id' => $deviceId,
    ];
}

/**
 * @param array{plain_token: string, device_id: string} $token
 * @return array<string, string>
 */
function kickstartPluginHeaders(array $token): array
{
    return [
        'Authorization' => 'Bearer '.$token['plain_token'],
        'X-DevBoard-Protocol' => 'v1',
        'X-DevBoard-Plugin-Version' => '0.1.0',
        'X-DevBoard-Device-Id' => $token['device_id'],
    ];
}
